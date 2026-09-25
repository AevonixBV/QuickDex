<?php

declare(strict_types=1);

namespace QuickDex;

final class SourceIndexer
{
    /**
     * Bump whenever the schema changes shape. build() compares this against
     * the stored meta value and forces a full rebuild on mismatch, so an
     * incremental pass never runs against columns that don't exist yet.
     */
    public const SCHEMA_VERSION = '3.0';

    private \PDO $db;

    private string $root;

    private string $dbPath;

    /** Human-readable notes from the last build (artisan skipped, Ziggy config unreadable, ...). */
    public array $notes = [];

    /**
     * Bare entries match any single path segment of that name; entries containing
     * a `/` (e.g. `bootstrap/ssr`) match a specific nested path prefix only — so
     * `bootstrap/app.php` (a real source file) stays indexed while the compiled
     * `bootstrap/ssr` and `bootstrap/cache` output does not. See isExcluded().
     */
    private array $excludedDirs = [
        'vendor',
        'node_modules',
        '.git',
        '.claude',
        '.codex',
        'storage',
        'tmp',
        '.idea',
        '.vscode',
        'coverage',
        'dist',
        '.output',
        'bootstrap/cache',
        'bootstrap/ssr',
        'public/build',
    ];

    private array $extensions = ['php', 'inc', 'vue', 'js', 'ts', 'go', 'py'];

    public function __construct(string $root, string $dbPath)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->dbPath = $dbPath;
    }

    /**
     * Build or update the index. Does an incremental update (only re-parsing
     * files whose mtime changed) whenever an existing, schema-compatible
     * database is present; otherwise falls back to a full rebuild. Pass
     * $force = true to always do a full rebuild.
     */
    public function build(bool $force = false): void
    {
        $incremental = ! $force && $this->hasCompatibleExistingDb();

        $this->db = $incremental
            ? $this->openExisting($this->dbPath)
            : $this->createFresh($this->dbPath);

        $this->db->beginTransaction();

        $seen = [];
        $indexed = 0;
        $skipped = 0;

        foreach ($this->collectFiles() as $absolutePath) {
            $rel = $this->relativePath($absolutePath);
            $seen[$rel] = true;
            $mtime = (int) (filemtime($absolutePath) ?: 0);

            if ($incremental) {
                $existingMtime = $this->existingMtime($rel);
                if ($existingMtime !== null && $existingMtime === $mtime) {
                    $skipped++;

                    continue;
                }
                $this->removeFileData($rel);
            }

            $this->indexFile($absolutePath, $mtime);
            $indexed++;
        }

        $purged = 0;
        if ($incremental) {
            $purged = $this->purgeMissingFiles($seen);
        }

        // Laravel layer: the route table comes from the framework itself (artisan),
        // not from parsing routes/*.php — groups, prefixes, resource() expansions and
        // middleware stacks are only knowable after Laravel has booted them. Re-run
        // whenever anything changed (a route file, a controller, a Ziggy group) or
        // the table is empty; skipping it on a no-op incremental pass keeps the
        // stale-index auto-refresh cheap.
        if ($indexed > 0 || $purged > 0 || ! $incremental || $this->routeCount() === 0) {
            $this->indexLaravelRoutes();
        }

        $this->db->prepare('DELETE FROM meta')->execute();
        $stmt = $this->db->prepare('INSERT INTO meta VALUES (?, ?)');
        $stmt->execute(['generated_at', gmdate('Y-m-d\TH:i:s\Z')]);
        $stmt->execute(['version', self::SCHEMA_VERSION]);
        $stmt->execute(['root', $this->root]);
        $stmt->execute(['total_files', (string) ($indexed + $skipped)]);
        $stmt->execute(['indexed_files', (string) $indexed]);
        $stmt->execute(['skipped_unchanged', (string) $skipped]);
        $stmt->execute(['mode', $incremental ? 'incremental' : 'full']);

        $this->db->commit();
    }

    // ── Incremental-update plumbing ─────────────────────────────────────────

    private function hasCompatibleExistingDb(): bool
    {
        if (! file_exists($this->dbPath)) {
            return false;
        }

        try {
            $db = new \PDO('sqlite:'.$this->dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $stmt = $db->query("SELECT value FROM meta WHERE key = 'version'");
            $version = $stmt ? $stmt->fetchColumn() : false;

            return $version === self::SCHEMA_VERSION;
        } catch (\Throwable) {
            return false;
        }
    }

    private function openExisting(string $dbPath): \PDO
    {
        $db = new \PDO('sqlite:'.$dbPath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');

        return $db;
    }

    private function existingMtime(string $rel): ?int
    {
        $stmt = $this->db->prepare('SELECT mtime FROM files WHERE path = ?');
        $stmt->execute([$rel]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** Remove all rows (defs, refs, hierarchy, files) belonging to one file, ahead of re-indexing it. */
    private function removeFileData(string $rel): void
    {
        $classStmt = $this->db->prepare("SELECT name FROM defs WHERE file = ? AND kind = 'class'");
        $classStmt->execute([$rel]);
        $classes = array_column($classStmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $this->db->prepare('DELETE FROM defs WHERE file = ?')->execute([$rel]);
        $this->db->prepare('DELETE FROM refs WHERE file = ?')->execute([$rel]);
        $this->db->prepare('DELETE FROM pages WHERE file = ?')->execute([$rel]);
        $this->db->prepare('DELETE FROM files WHERE path = ?')->execute([$rel]);

        if ($classes) {
            $placeholders = implode(',', array_fill(0, count($classes), '?'));
            $this->db->prepare("DELETE FROM hierarchy WHERE class IN ({$placeholders})")->execute($classes);
        }
    }

    /** Purge rows for any previously-indexed file that's gone or no longer matches the include filters. */
    private function purgeMissingFiles(array $seen): int
    {
        $stmt = $this->db->query('SELECT path FROM files');
        $known = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'path');
        $purged = 0;

        foreach ($known as $path) {
            if (! isset($seen[$path])) {
                $this->removeFileData($path);
                $purged++;
            }
        }

        return $purged;
    }

    private function createFresh(string $dbPath): \PDO
    {
        if (file_exists($dbPath)) {
            // Suppressed: on Windows, unlink() can fail with "resource temporarily
            // unavailable" if another still-open PDO handle to this same file exists
            // in-process (or a previous run didn't get to close cleanly). Fall through
            // to the explicit DROP-then-CREATE below rather than letting a leftover
            // "table already exists" turn into an uncaught fatal.
            @unlink($dbPath);
            @unlink($dbPath.'-shm');
            @unlink($dbPath.'-wal');
        }

        $db = new \PDO('sqlite:'.$dbPath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');

        $db->exec('
            DROP TABLE IF EXISTS meta;
            DROP TABLE IF EXISTS files;
            DROP TABLE IF EXISTS defs;
            DROP TABLE IF EXISTS refs;
            DROP TABLE IF EXISTS hierarchy;
            DROP TABLE IF EXISTS routes;
            DROP TABLE IF EXISTS pages;

            CREATE TABLE meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE files (
                path    TEXT PRIMARY KEY,
                type    TEXT NOT NULL,
                lines   INTEGER NOT NULL DEFAULT 0,
                ns      TEXT,
                summary TEXT,
                mtime   INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE defs (
                id        INTEGER PRIMARY KEY,
                name      TEXT NOT NULL,
                kind      TEXT NOT NULL,
                file      TEXT NOT NULL,
                line      INTEGER NOT NULL,
                end_line  INTEGER NOT NULL,
                ns        TEXT,
                generated INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE refs (
                id     INTEGER PRIMARY KEY,
                symbol TEXT NOT NULL,
                file   TEXT NOT NULL,
                line   INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE hierarchy (
                class      TEXT PRIMARY KEY,
                extends    TEXT,
                implements TEXT,
                traits     TEXT
            );

            -- Laravel route table, filled from php artisan route:list --json (see
            -- indexLaravelRoutes). One row per registered route. `file`/`line` point
            -- at the controller method (or the closure in routes/*.php); `page` is the
            -- Inertia page that method renders, if it renders exactly one; `ziggy` is
            -- the comma-joined config/ziggy.php group(s) whose patterns match `name`.
            CREATE TABLE routes (
                id          INTEGER PRIMARY KEY,
                name        TEXT,
                method      TEXT NOT NULL,
                uri         TEXT NOT NULL,
                action      TEXT NOT NULL,
                controller  TEXT,
                action_name TEXT,
                middleware  TEXT NOT NULL DEFAULT "[]",
                file        TEXT,
                line        INTEGER,
                page        TEXT,
                ziggy       TEXT
            );

            -- Inertia::render(Some/Page) / inertia(Some/Page) call sites, one row
            -- each. `owner` is Class@method when the call sits inside a class method,
            -- so routes.page can be joined without re-parsing.
            CREATE TABLE pages (
                id    INTEGER PRIMARY KEY,
                page  TEXT NOT NULL,
                file  TEXT NOT NULL,
                line  INTEGER NOT NULL,
                owner TEXT
            );

            CREATE INDEX idx_defs_name       ON defs(name);
            CREATE INDEX idx_defs_name_lower ON defs(LOWER(name));
            CREATE INDEX idx_defs_file       ON defs(file);
            CREATE INDEX idx_refs_symbol     ON refs(symbol);
            CREATE INDEX idx_refs_file       ON refs(file);
            CREATE INDEX idx_routes_name     ON routes(name);
            CREATE INDEX idx_routes_page     ON routes(page);
            CREATE INDEX idx_pages_page      ON pages(page);
            CREATE INDEX idx_pages_file      ON pages(file);
        ');

        return $db;
    }

    /**
     * Prunes excluded directories (vendor, node_modules, .git, ...) BEFORE recursing
     * into them, via RecursiveCallbackFilterIterator — a plain RecursiveDirectoryIterator
     * would walk every file inside a huge vendor/node_modules tree only to discard them
     * one by one in isExcluded(), which is what made (re)indexing large repos slow even
     * when the incremental pass had nothing new to parse.
     */
    private function collectFiles(): \Generator
    {
        $filtered = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $current, mixed $key, \RecursiveDirectoryIterator $iterator): bool {
                $rel = $this->relativePath($current->getPathname());

                if ($iterator->hasChildren()) {
                    return ! $this->isExcluded($rel);
                }

                return ! $this->isExcluded($rel) && $this->shouldIndex($rel);
            },
        );

        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                yield $item->getPathname();
            }
        }
    }

    private function indexFile(string $absolutePath, int $mtime): void
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return;
        }

        $rel = $this->relativePath($absolutePath);
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $isBlade = str_ends_with(strtolower($absolutePath), '.blade.php');
        $lines = substr_count($content, "\n") + 1;

        $data = match (true) {
            $isBlade          => $this->parseBlade($content),
            $ext === 'vue'    => $this->parseVue($content, $rel),
            $ext === 'js', $ext === 'ts' => $this->parseScript($content, $ext),
            $ext === 'go'     => $this->parseGo($content),
            $ext === 'py'     => $this->parsePython($content),
            default           => $this->parsePhp($content),
        };

        $this->db->prepare('INSERT OR REPLACE INTO files VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$rel, $data['type'], $lines, $data['ns'], $data['summary'], $mtime]);

        // Framework-generated defs (e.g. Laravel Wayfinder emits a `store`/`index`/
        // `update` const per controller action under resources/js/{actions,routes})
        // are real refs but drown hand-written symbols in def/search/body. Flag them
        // so queries can sort them last. See PLAN_IMPROVEMENTS P1.4.
        $generated = (int) ($this->isGeneratedPath($rel));

        $defStmt = $this->db->prepare('INSERT INTO defs (name, kind, file, line, end_line, ns, generated) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($data['defs'] as $def) {
            $defStmt->execute([
                $def['name'],
                $def['kind'],
                $rel,
                $def['line'],
                $def['end_line'] ?? $def['line'],
                $def['ns'] ?? $data['ns'],
                $generated,
            ]);
        }

        $refStmt = $this->db->prepare('INSERT INTO refs (symbol, file, line) VALUES (?, ?, ?)');
        $seenRefs = [];
        foreach ($data['refs'] as $ref) {
            $symbol = $ref['symbol'] ?? '';
            $line = $ref['line'] ?? 0;
            if ($symbol === '') {
                continue;
            }
            $key = $symbol."\x00".$line;
            if (isset($seenRefs[$key])) {
                continue;
            }
            $seenRefs[$key] = true;
            $refStmt->execute([$symbol, $rel, $line]);
        }

        $hierStmt = $this->db->prepare('INSERT OR REPLACE INTO hierarchy VALUES (?, ?, ?, ?)');
        foreach ($data['hierarchy'] as $class => $h) {
            $hierStmt->execute([
                $class,
                $h['extends'],
                json_encode($h['implements']),
                json_encode($h['traits']),
            ]);
        }

        $pageStmt = $this->db->prepare('INSERT INTO pages (page, file, line, owner) VALUES (?, ?, ?, ?)');
        foreach ($data['pages'] ?? [] as $page) {
            $pageStmt->execute([$page['page'], $rel, $page['line'], $page['owner']]);
        }
    }

    // ── Laravel routes ───────────────────────────────────────────────────────

    private function routeCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM routes')->fetchColumn();
    }

    /**
     * Fill the routes table from `php artisan route:list --json`. Only runs when the
     * root has an `artisan` file; a boot failure (fresh worktree without .env, broken
     * provider) leaves the previous rows in place and records a note rather than
     * failing the whole index — the symbol index is still valid without routes.
     *
     * Set QUICKDEX_NO_ARTISAN=1 to skip (CI, or a box where booting the app is unsafe).
     */
    private function indexLaravelRoutes(): void
    {
        $artisan = $this->root.DIRECTORY_SEPARATOR.'artisan';
        if (! is_file($artisan) || getenv('QUICKDEX_NO_ARTISAN')) {
            return;
        }

        $json = $this->runInRoot('php artisan route:list --json --no-interaction');
        $routes = $json === null ? null : json_decode($json, true);

        if (! is_array($routes)) {
            $this->notes[] = 'routes: `php artisan route:list --json` did not return JSON — route table left as-is. Check the app boots (.env present?).';

            return;
        }

        $ziggyGroups = $this->loadZiggyGroups();

        $this->db->exec('DELETE FROM routes');
        $stmt = $this->db->prepare(
            'INSERT INTO routes (name, method, uri, action, controller, action_name, middleware, file, line, page, ziggy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($routes as $route) {
            $action = (string) ($route['action'] ?? '');
            $name = $route['name'] ?? null;
            $controller = null;
            $actionName = null;
            $file = null;
            $line = null;
            $page = null;

            if ($action !== '' && $action !== 'Closure') {
                if (str_contains($action, '@')) {
                    [$controller, $actionName] = explode('@', $action, 2);
                } else {
                    $controller = $action;
                    $actionName = '__invoke';
                }
                $controller = ltrim($controller, '\\');
                $located = $this->locateControllerAction($controller, $actionName);
                if ($located !== null) {
                    [$file, $line] = $located;
                    $short = substr($controller, strrpos($controller, '\\') !== false ? strrpos($controller, '\\') + 1 : 0);
                    $page = $this->pageRenderedBy($file, $short.'@'.$actionName);
                }
            } elseif (! empty($route['path']) && preg_match('/^(.*):(\d+)$/', (string) $route['path'], $m)) {
                // Closure route: artisan reports "routes/web.php:372".
                $file = str_replace('\\', '/', $m[1]);
                $line = (int) $m[2];
                $page = $this->pageRenderedAt($file, $line);
            }

            $stmt->execute([
                $name,
                (string) ($route['method'] ?? ''),
                (string) ($route['uri'] ?? ''),
                $action,
                $controller,
                $actionName,
                json_encode(array_values((array) ($route['middleware'] ?? []))),
                $file,
                $line,
                $page,
                $name === null ? null : $this->ziggyGroupsFor((string) $name, $ziggyGroups),
            ]);
        }
    }

    /** @return array{0: string, 1: int}|null  [relative file, line] of Controller::method */
    private function locateControllerAction(string $fqcn, string $method): ?array
    {
        $pos = strrpos($fqcn, '\\');
        $short = $pos === false ? $fqcn : substr($fqcn, $pos + 1);
        $ns = $pos === false ? null : substr($fqcn, 0, $pos);

        $stmt = $this->db->prepare(
            "SELECT file FROM defs WHERE kind = 'class' AND name = ? AND (ns = ? OR ? IS NULL) ORDER BY generated, file LIMIT 1"
        );
        $stmt->execute([$short, $ns, $ns]);
        $file = $stmt->fetchColumn();
        if ($file === false) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT line FROM defs WHERE kind = 'method' AND file = ? AND name = ? LIMIT 1");
        $stmt->execute([$file, $method]);
        $line = $stmt->fetchColumn();

        // Inherited action (e.g. a base landing controller): file known, line not.
        return [(string) $file, $line === false ? 0 : (int) $line];
    }

    /** The one page a method renders, or null when it renders none or several. */
    private function pageRenderedBy(string $file, string $owner): ?string
    {
        $stmt = $this->db->prepare('SELECT DISTINCT page FROM pages WHERE file = ? AND owner = ?');
        $stmt->execute([$file, $owner]);
        $pages = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return count($pages) === 1 ? (string) $pages[0] : null;
    }

    /** For a closure route at routes/x.php:N — the first render call after that line, before the next closure route. */
    private function pageRenderedAt(string $file, int $line): ?string
    {
        $stmt = $this->db->prepare('SELECT page FROM pages WHERE file = ? AND line >= ? AND line <= ? ORDER BY line LIMIT 1');
        $stmt->execute([$file, $line, $line + 12]);
        $page = $stmt->fetchColumn();

        return $page === false ? null : (string) $page;
    }

    /**
     * config/ziggy.php `groups` (tightenco/ziggy): group name => list of route-name
     * patterns (`exact.name` or `prefix.*`). Loaded by including the config in a
     * separate PHP process so a config that calls env()/config() cannot take the
     * indexer down. Returns [] when the file is absent or unreadable.
     *
     * @return array<string, list<string>>
     */
    private function loadZiggyGroups(): array
    {
        $config = $this->root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'ziggy.php';
        if (! is_file($config)) {
            return [];
        }

        $helper = dirname(__DIR__).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'ziggy-groups.php';
        $json = $this->runInRoot('php '.escapeshellarg($helper).' '.escapeshellarg($config));
        $groups = $json === null ? null : json_decode($json, true);

        if (! is_array($groups)) {
            $this->notes[] = 'ziggy: config/ziggy.php exists but its `groups` could not be read — routes.ziggy left empty.';

            return [];
        }

        $out = [];
        foreach ($groups as $group => $patterns) {
            if (is_array($patterns)) {
                $out[(string) $group] = array_values(array_filter(array_map('strval', $patterns)));
            }
        }

        return $out;
    }

    /** @param array<string, list<string>> $groups */
    private function ziggyGroupsFor(string $routeName, array $groups): ?string
    {
        $hits = [];
        foreach ($groups as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === $routeName
                    || (str_ends_with($pattern, '*') && str_starts_with($routeName, substr($pattern, 0, -1)))) {
                    $hits[] = $group;
                    break;
                }
            }
        }

        return $hits === [] ? null : implode(',', $hits);
    }

    /** Run a shell command with cwd = project root; stdout on exit 0, else null (stderr goes to notes). */
    private function runInRoot(string $command): ?string
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $spec, $pipes, $this->root);
        if (! is_resource($proc)) {
            $this->notes[] = "could not start: {$command}";

            return null;
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            $this->notes[] = "`{$command}` exited {$exit}: ".trim(substr($stderr !== '' ? $stderr : $stdout, 0, 300));

            return null;
        }

        return $stdout;
    }

    // ── PHP ──────────────────────────────────────────────────────────────────

    private function parsePhp(string $content): array
    {
        $ns = null;
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $m)) {
            $ns = trim($m[1]);
        }

        $defs = [];
        $refs = [];
        $hierarchy = [];
        $counts = [];
        $classRanges = [];

        // Classes (with extends / implements)
        if (preg_match_all(
            '/^\s*(?:abstract\s+|final\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/mi',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'class',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];

                $h = $this->extractClassRelations($content, $offset);
                $hierarchy[$name] = $h;

                if ($h['bodyStart'] !== null && $h['bodyEnd'] !== null) {
                    $classRanges[] = ['name' => $name, 'start' => $h['bodyStart'], 'end' => $h['bodyEnd']];
                }
            }
            $counts['classes'] = count($m[1]);
        }

        // Methods (indented function declarations inside class/trait bodies)
        if (preg_match_all(
            '/^[ \t]+(?:(?:public|protected|private|final|abstract|static)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/mi',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $owner = $this->findOwnerClass($classRanges, $offset);
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'method',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $owner ?? $ns,
                ];
            }
            $counts['methods'] = count($m[1]);
        }

        // Table operations (Schema::create/table/dropIfExists/drop) — mostly migrations
        if (preg_match_all(
            '/Schema::(create|table|dropIfExists|drop)\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/mi',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $i => $opMatch) {
                $op = $opMatch[0];
                $table = $m[2][$i][0];
                $offset = $m[0][$i][1];
                $matchEnd = $offset + strlen($m[0][$i][0]);
                $endOffset = $this->findDeclarationEnd($content, $matchEnd);
                $defs[] = [
                    'name' => $table,
                    'kind' => 'table:'.$op,
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Interfaces
        if (preg_match_all('/^\s*interface\s+([A-Za-z_][A-Za-z0-9_]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'interface',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['interfaces'] = count($m[1]);
        }

        // Traits
        if (preg_match_all('/^\s*trait\s+([A-Za-z_][A-Za-z0-9_]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'trait',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['traits'] = count($m[1]);
        }

        // Top-level functions only (no leading whitespace)
        if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'function',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['functions'] = count($m[1]);
        }

        // Constants
        if (preg_match_all('/^\s*const\s+([A-Z_][A-Z0-9_]*)\s*=/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'constant',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
        }

        // Use statements → refs (both FQN and short name)
        if (preg_match_all('/^\s*use\s+([^;]+);/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $useStmt = $match[0];
                $line = $this->lineAt($content, $match[1]);
                foreach (preg_split('/\s*,\s*/', $useStmt) as $used) {
                    $used = trim(preg_replace('/\s+as\s+\S+$/', '', trim($used)));
                    if ($used === '') {
                        continue;
                    }
                    $refs[] = ['symbol' => $used, 'line' => $line];
                    $parts = explode('\\', $used);
                    $short = end($parts);
                    if ($short !== $used) {
                        $refs[] = ['symbol' => $short, 'line' => $line];
                    }
                }
            }
        }

        // Inline class references (new X, X::, extends/implements, type hints) — the
        // `use`-statement pass above misses any sibling referenced WITHOUT an import,
        // i.e. a class in the SAME namespace. That blind spot made `refs <Sibling>`
        // silently under-report. See PLAN_IMPROVEMENTS P1.5.
        foreach ($this->extractPhpClassRefs($content) as $ref) {
            $refs[] = $ref;
        }

        // route('name') / to_route('name') / ->route('name') string literals → refs on
        // the bare route name, so `refs sites.index` lists every caller (PHP and Vue
        // alike — see parseScriptContent) next to the route table's definition.
        foreach ($this->extractRouteNameRefs($content) as $ref) {
            $refs[] = $ref;
        }

        // Inertia page renders, with the owning Class@method so routes can be joined.
        $methodRanges = [];
        foreach ($defs as $def) {
            if ($def['kind'] === 'method') {
                $methodRanges[] = ['name' => ($def['ns'] ?? '').'@'.$def['name'], 'start' => $def['line'], 'end' => $def['end_line']];
            }
        }
        $pages = [];
        if (preg_match_all('/(?:Inertia::render|\binertia)\(\s*[\'"]([A-Za-z0-9_\/.\-]+)[\'"]/', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $line = $this->lineAt($content, $match[1]);
                $owner = null;
                foreach ($methodRanges as $range) {
                    if ($line >= $range['start'] && $line <= $range['end']) {
                        $owner = $range['name'];
                        break;
                    }
                }
                $pages[] = ['page' => $match[0], 'line' => $line, 'owner' => $owner];
            }
        }

        $summary = $this->buildSummary($counts, substr_count($content, "\n") + 1);

        return [
            'type'      => 'php',
            'ns'        => $ns,
            'defs'      => $defs,
            'refs'      => $refs,
            'hierarchy' => $hierarchy,
            'pages'     => $pages,
            'summary'   => $summary,
        ];
    }

    /** @return list<array{symbol: string, line: int}> */
    private function extractRouteNameRefs(string $content): array
    {
        $refs = [];
        if (preg_match_all('/\broute\(\s*[\'"]([A-Za-z0-9_.\-:]+)[\'"]/', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $refs[] = ['symbol' => $match[0], 'line' => $this->lineAt($content, $match[1])];
            }
        }

        return $refs;
    }

    /**
     * Extract references to classes used inline — `new X`, `X::` (static call /
     * ::class / class const), `extends`/`implements`/`instanceof X`, and type hints
     * (`X $var`, incl. promoted properties and `?X`) — via PHP's native tokenizer so
     * comments and string literals are skipped automatically (no false positive from
     * a class name mentioned in a docblock). This complements the `use`-statement
     * pass: a class referencing a sibling in the SAME namespace needs no import, so
     * it would otherwise never appear in `refs`. Records the short (last-segment)
     * name — what `refs <Name>` looks up — plus the FQN when the reference is
     * qualified. Not an AST/php-parser dependency; the lexer ships with PHP.
     *
     * @return list<array{symbol: string, line: int}>
     */
    private function extractPhpClassRefs(string $content): array
    {
        if (! function_exists('token_get_all')) {
            return [];
        }

        $tokens = @token_get_all($content);

        // Keep meaningful tokens only. Whitespace and comments are dropped; string
        // literals survive as non-T_STRING tokens, so their contents can never match
        // a class-name rule below.
        $mean = [];
        foreach ($tokens as $t) {
            if (is_array($t)) {
                if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $mean[] = [$t[0], $t[1], $t[2]];
            } else {
                $mean[] = [null, $t, 0];
            }
        }

        $nameIds = [T_STRING];
        if (defined('T_NAME_QUALIFIED')) {
            $nameIds[] = T_NAME_QUALIFIED;        // Foo\Bar
            $nameIds[] = T_NAME_FULLY_QUALIFIED;  // \Foo\Bar
        }
        // Language keywords that tokenize as names in some contexts but are never a
        // class reference we want to record.
        $skip = ['self' => true, 'static' => true, 'parent' => true, 'true' => true, 'false' => true, 'null' => true];

        $refs = [];
        $count = count($mean);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $mean[$i];
            if ($id === null || ! in_array($id, $nameIds, true)) {
                continue;
            }

            $name = ltrim($text, '\\');
            if ($name === '' || isset($skip[strtolower($name)])) {
                continue;
            }

            $prev = $mean[$i - 1] ?? [null, '', 0];
            $next = $mean[$i + 1] ?? [null, '', 0];

            $isRef =
                // new X | X instanceof | extends X | implements X
                in_array($prev[0], [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)
                // X:: — static call, ::class, class constant
                || $next[0] === T_DOUBLE_COLON
                // Type hint immediately before a variable: `X $var`, `?X $var`,
                // promoted `private X $var`. Uppercase-first keeps out lowercase
                // scalar hints (string/int/bool/array/...).
                || ($next[0] === T_VARIABLE && $name[0] >= 'A' && $name[0] <= 'Z');

            if (! $isRef) {
                continue;
            }

            $ln = (int) $line;
            if (str_contains($name, '\\')) {
                $refs[] = ['symbol' => $name, 'line' => $ln];
                $parts = explode('\\', $name);
                $name = end($parts);
            }
            $refs[] = ['symbol' => $name, 'line' => $ln];
        }

        return $refs;
    }

    // ── Go ───────────────────────────────────────────────────────────────────

    private function parseGo(string $content): array
    {
        $ns = null;
        if (preg_match('/^package\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $m)) {
            $ns = trim($m[1]);
        }

        $defs = [];
        $refs = [];
        $counts = [];

        // Methods: func (recv Type) Name(...) / func (recv *Type) Name(...) — ns
        // records the receiver type so `syms`/`def` group methods with their type,
        // mirroring how PHP methods record their owning class.
        if (preg_match_all(
            '/^func\s+\(\s*[A-Za-z_][A-Za-z0-9_]*\s+\*?([A-Za-z_][A-Za-z0-9_]*)\s*\)\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',
            $content, $m, PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[2] as $i => $match) {
                $name = trim($match[0]);
                $receiver = trim($m[1][$i][0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'method',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $receiver,
                ];
            }
            $counts['methods'] = count($m[2]);
        }

        // Plain (non-method) functions: func Name(...)
        if (preg_match_all('/^func\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'function',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['functions'] = count($m[1]);
        }

        // Structs
        if (preg_match_all('/^type\s+([A-Za-z_][A-Za-z0-9_]*)\s+struct\b/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'struct',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['structs'] = count($m[1]);
        }

        // Interfaces
        if (preg_match_all('/^type\s+([A-Za-z_][A-Za-z0-9_]*)\s+interface\b/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $offset = $match[1];
                $endOffset = $this->findDeclarationEnd($content, $offset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'interface',
                    'line' => $this->lineAt($content, $offset),
                    'end_line' => $this->lineAt($content, $endOffset),
                    'ns' => $ns,
                ];
            }
            $counts['interfaces'] = count($m[1]);
        }

        // Imports (grouped `import (...)` block) → refs, full path + last segment
        if (preg_match('/^import\s*\(([\s\S]*?)\n\)/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            $block = $m[1][0];
            $blockOffset = $m[1][1];
            if (preg_match_all('/^\s*(?:[A-Za-z_][A-Za-z0-9_]*\s+)?"([^"]+)"/m', $block, $im, PREG_OFFSET_CAPTURE)) {
                foreach ($im[1] as $match) {
                    $path = $match[0];
                    $line = $this->lineAt($content, $match[1] + $blockOffset);
                    $refs[] = ['symbol' => $path, 'line' => $line];
                    $parts = explode('/', $path);
                    $short = end($parts);
                    if ($short !== $path) {
                        $refs[] = ['symbol' => $short, 'line' => $line];
                    }
                }
            }
        }

        // Imports (single-line `import "path"`) → refs
        if (preg_match_all('/^import\s+"([^"]+)"/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $path = $match[0];
                $line = $this->lineAt($content, $match[1]);
                $refs[] = ['symbol' => $path, 'line' => $line];
                $parts = explode('/', $path);
                $short = end($parts);
                if ($short !== $path) {
                    $refs[] = ['symbol' => $short, 'line' => $line];
                }
            }
        }

        $lines = substr_count($content, "\n") + 1;
        $summary = $this->buildSummary($counts, $lines);

        return [
            'type'      => 'go',
            'ns'        => $ns,
            'defs'      => $defs,
            'refs'      => $refs,
            'hierarchy' => [],
            'summary'   => $summary,
        ];
    }

    // ── Python ───────────────────────────────────────────────────────────────

    private function parsePython(string $content): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $lineCount = count($lines);

        $defs = [];
        $refs = [];
        $counts = [];
        $classRanges = [];
        $hierarchy = [];

        // Classes — indentation-delimited body, so its extent is found by scanning
        // forward for the next line whose indentation falls back to <= the class's
        // own (mirrors findDeclarationEnd's brace-matching role for brace languages).
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)class\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
                $indent = strlen($m[1]);
                $name = $m[2];
                $lineNo = $i + 1;
                $endLineNo = $this->findPythonBlockEnd($lines, $i, $indent);
                $defs[] = [
                    'name' => $name,
                    'kind' => 'class',
                    'line' => $lineNo,
                    'end_line' => $endLineNo,
                    'ns' => null,
                ];
                $classRanges[] = ['name' => $name, 'start' => $lineNo, 'end' => $endLineNo];

                // Bases → hierarchy: the first base is `extends` (primary in the MRO),
                // any further bases (mixins) go in `implements`. Each base is also a ref.
                $bases = $this->parsePythonBases($lines, $i, strlen($m[0]));
                $hierarchy[$name] = [
                    'extends'    => $bases[0] ?? null,
                    'implements' => array_slice($bases, 1),
                    'traits'     => [],
                ];
                foreach ($bases as $base) {
                    $refs[] = ['symbol' => $base, 'line' => $lineNo];
                }
            }
        }
        $counts['classes'] = count($classRanges);

        // Functions and methods — an indented `def` inside a class's line range is a
        // method (ns = owning class), everything else is a top-level function.
        $funcCount = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m)) {
                $indent = strlen($m[1]);
                $name = $m[2];
                $lineNo = $i + 1;
                $endLineNo = $this->findPythonBlockEnd($lines, $i, $indent);
                $owner = $indent > 0 ? $this->findOwnerClassByLine($classRanges, $lineNo) : null;
                $defs[] = [
                    'name' => $name,
                    'kind' => $owner !== null ? 'method' : 'function',
                    'line' => $lineNo,
                    'end_line' => $endLineNo,
                    'ns' => $owner,
                ];
                $funcCount++;
            }
        }
        $counts['functions'] = $funcCount;

        // Imports → refs: `import x.y as z`, `import x, y`, `from x.y import a, b as c`
        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;

            if (preg_match('/^\s*from\s+(\.*[A-Za-z0-9_.]*)\s+import\s+(.+)$/', $line, $m)) {
                $module = trim($m[1]);
                if ($module !== '') {
                    $refs[] = ['symbol' => $module, 'line' => $lineNo];
                }
                $importList = trim(trim($m[2]), '()');
                foreach (explode(',', $importList) as $part) {
                    $part = trim(preg_replace('/\s+as\s+\S+$/', '', trim($part)));
                    if ($part !== '' && $part !== '*') {
                        $refs[] = ['symbol' => $part, 'line' => $lineNo];
                    }
                }
            } elseif (preg_match('/^\s*import\s+(.+)$/', $line, $m)) {
                foreach (explode(',', $m[1]) as $part) {
                    $part = trim(preg_replace('/\s+as\s+\S+$/', '', trim($part)));
                    if ($part === '') {
                        continue;
                    }
                    $refs[] = ['symbol' => $part, 'line' => $lineNo];
                    $parts = explode('.', $part);
                    $short = end($parts);
                    if ($short !== $part) {
                        $refs[] = ['symbol' => $short, 'line' => $lineNo];
                    }
                }
            }
        }

        $summary = $this->buildSummary($counts, $lineCount);

        return [
            'type'      => 'py',
            'ns'        => null,
            'defs'      => $defs,
            'refs'      => $refs,
            'hierarchy' => $hierarchy,
            'summary'   => $summary,
        ];
    }

    /**
     * Base class names from a Python class header starting at $lines[$startIndex],
     * $offset bytes past `class Name`. The parenthesised list may span lines.
     * Keyword args (metaclass=…), *args/**kwargs and `object` are dropped; generics
     * are reduced to their name (Generic[T] → Generic) and dotted names to their
     * last segment (models.Model → Model), matching how defs store class names.
     *
     * @return list<string>
     */
    private function parsePythonBases(array $lines, int $startIndex, int $offset): array
    {
        $text = substr($lines[$startIndex], $offset);
        for ($j = $startIndex + 1, $n = count($lines); $j < $n && $j <= $startIndex + 30; $j++) {
            $text .= "\n".$lines[$j];
        }

        $text = ltrim($text);
        if (! str_starts_with($text, '(')) {
            return [];
        }

        // Split the parenthesised list on top-level commas.
        $args = [];
        $current = '';
        $depth = 0;
        $len = strlen($text);
        for ($k = 0; $k < $len; $k++) {
            $c = $text[$k];
            if ($c === '#') {
                // Comment inside a multi-line header — skip to end of line.
                $nl = strpos($text, "\n", $k);
                $k = $nl === false ? $len : $nl;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) {
                    $args[] = $current;
                    break;
                }
            } elseif ($c === ',' && $depth === 1) {
                $args[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }

        $bases = [];
        foreach ($args as $arg) {
            $arg = trim($arg);
            if ($arg === '' || str_starts_with($arg, '*') || preg_match('/^[A-Za-z_]\w*\s*=(?!=)/', $arg)) {
                continue;
            }
            $arg = preg_replace('/\[.*$/s', '', $arg);
            if (! preg_match('/^[A-Za-z_][\w.]*$/', $arg)) {
                continue;
            }
            $parts = explode('.', $arg);
            $name = end($parts);
            if ($name !== 'object' && ! in_array($name, $bases, true)) {
                $bases[] = $name;
            }
        }

        return $bases;
    }

    /**
     * Line number (1-indexed) of the last non-blank line still inside the
     * indentation-delimited block that opens at $lines[$startIndex] — i.e. the
     * last line before indentation returns to <= $indent. Blank lines don't end
     * a block on their own (a blank line inside a function body is common); only
     * a following line of code at or below the opening indent does.
     */
    private function findPythonBlockEnd(array $lines, int $startIndex, int $indent): int
    {
        $count = count($lines);
        $lastContentLine = $startIndex + 1;

        for ($i = $startIndex + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $lineIndent = strlen($line) - strlen(ltrim($line, " \t"));
            if ($lineIndent <= $indent) {
                return $lastContentLine;
            }
            $lastContentLine = $i + 1;
        }

        return $lastContentLine;
    }

    /** Innermost class (by line range) containing $lineNo, if any — Python's line-number analog of findOwnerClass(). */
    private function findOwnerClassByLine(array $ranges, int $lineNo): ?string
    {
        $best = null;
        $bestSize = PHP_INT_MAX;

        foreach ($ranges as $range) {
            if ($lineNo > $range['start'] && $lineNo <= $range['end']) {
                $size = $range['end'] - $range['start'];
                if ($size < $bestSize) {
                    $bestSize = $size;
                    $best = $range['name'];
                }
            }
        }

        return $best;
    }

    /** Framework-generated code path (Laravel Wayfinder output) — flagged, not excluded. */
    private function isGeneratedPath(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);

        return str_contains($rel, 'resources/js/actions/')
            || str_contains($rel, 'resources/js/routes/');
    }

    private function extractClassRelations(string $content, int $classOffset): array
    {
        $bracePos = strpos($content, '{', $classOffset);
        if ($bracePos === false) {
            return ['extends' => null, 'implements' => [], 'traits' => [], 'bodyStart' => null, 'bodyEnd' => null];
        }

        $declaration = substr($content, $classOffset, $bracePos - $classOffset);

        $extends = null;
        if (preg_match('/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/i', $declaration, $m)) {
            $extends = trim($m[1]);
        }

        $implements = [];
        if (preg_match('/\bimplements\s+([A-Za-z_\\\\,\s]+)/i', $declaration, $m)) {
            $implements = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        }

        $traits = $this->extractTraitsFromBody($content, $bracePos);
        $bodyEnd = $this->findMatchingDelimiter($content, $bracePos, '{', '}');

        return ['extends' => $extends, 'implements' => $implements, 'traits' => $traits, 'bodyStart' => $bracePos, 'bodyEnd' => $bodyEnd];
    }

    /**
     * Find where a declaration "ends" starting the search just after its name/signature.
     * Handles three shapes uniformly:
     *  - Body-less statement terminated by `;` before any `(`/`{` (const, type alias, etc.)
     *  - A parameter list `(...)` followed by a `{ ... }` body (function/method — the
     *    paren-skip means destructured JS params like `({a, b})` don't get mistaken
     *    for the body brace)
     *  - A bare `{ ... }` block with no parameter list (class/interface/trait, or a
     *    migration closure once its own param list has been skipped)
     * Falls back to $afterOffset itself (a same-line "end") if none of the above is found.
     */
    private function findDeclarationEnd(string $content, int $afterOffset): int
    {
        $parenPos = strpos($content, '(', $afterOffset);
        $semiPos = strpos($content, ';', $afterOffset);
        $bracePos = strpos($content, '{', $afterOffset);

        if ($semiPos !== false && ($parenPos === false || $semiPos < $parenPos) && ($bracePos === false || $semiPos < $bracePos)) {
            return $semiPos;
        }

        if ($parenPos !== false && ($bracePos === false || $parenPos < $bracePos)) {
            $closeParen = $this->findMatchingDelimiter($content, $parenPos, '(', ')');
            $semiAfterParams = strpos($content, ';', $closeParen);
            $braceAfterParams = strpos($content, '{', $closeParen);

            if ($semiAfterParams !== false && ($braceAfterParams === false || $semiAfterParams < $braceAfterParams)) {
                return $semiAfterParams;
            }

            $bracePos = $braceAfterParams;
        }

        if ($bracePos === false) {
            return $afterOffset;
        }

        return $this->findMatchingDelimiter($content, $bracePos, '{', '}');
    }

    /** Offset of the closing delimiter that matches the opening one at $openPos. */
    private function findMatchingDelimiter(string $content, int $openPos, string $open, string $close): int
    {
        $depth = 0;
        $len = strlen($content);

        for ($i = $openPos; $i < $len; $i++) {
            if ($content[$i] === $open) {
                $depth++;
            } elseif ($content[$i] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $len;
    }

    /** Innermost class whose body range contains $offset, if any. */
    private function findOwnerClass(array $ranges, int $offset): ?string
    {
        $best = null;
        $bestSize = PHP_INT_MAX;

        foreach ($ranges as $range) {
            if ($offset > $range['start'] && $offset < $range['end']) {
                $size = $range['end'] - $range['start'];
                if ($size < $bestSize) {
                    $bestSize = $size;
                    $best = $range['name'];
                }
            }
        }

        return $best;
    }

    private function extractTraitsFromBody(string $content, int $bodyStart): array
    {
        $depth = 0;
        $body = '';
        $len = strlen($content);

        for ($i = $bodyStart; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($depth === 1) {
                $body .= $ch;
            }
        }

        $traits = [];
        // Indented use = trait use (not namespace import which is at file top level)
        if (preg_match_all('/^\s+use\s+([A-Za-z_][A-Za-z0-9_,\s\\\\]*)\s*;/m', $body, $m)) {
            foreach ($m[1] as $useStmt) {
                foreach (preg_split('/\s*,\s*/', $useStmt) as $trait) {
                    $trait = trim($trait);
                    if ($trait !== '') {
                        $traits[] = $trait;
                    }
                }
            }
        }

        return $traits;
    }

    // ── Vue ──────────────────────────────────────────────────────────────────

    private function parseVue(string $content, string $rel = ''): array
    {
        $defs = [];
        $refs = [];
        $component = $rel === '' ? null : basename($rel, '.vue');

        if (preg_match_all('/(<script\b[^>]*>)(.*?)<\/script>/is', $content, $scriptMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($scriptMatches as $scriptMatch) {
                $scriptContent = $scriptMatch[2][0];
                $scriptOffset = $scriptMatch[2][1];
                $jsData = $this->parseScriptContent($scriptContent, $scriptOffset, $content);
                $defs = array_merge($defs, $jsData['defs']);
                $refs = array_merge($refs, $jsData['refs']);

                foreach ($this->extractVueMacros($scriptContent, $scriptOffset, $content) as $def) {
                    $def['ns'] = $component;
                    $defs[] = $def;
                }
            }
        }

        // <template> usage. Every child component tag (<AccountStatTile>, <app-button>)
        // becomes a ref on its PascalCase name, so `refs AccountStatTile` shows where the
        // component is actually placed, not only the import line. Script/style blocks are
        // blanked (not removed) so offsets and line numbers stay those of the real file.
        $markup = preg_replace_callback(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
            $content
        ) ?? '';
        if (preg_match_all('/<([A-Z][A-Za-z0-9]*|[a-z][a-z0-9]*(?:-[a-z0-9]+)+)\b/', $markup, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $tag = $match[0];
                if (str_contains($tag, '-')) {
                    $tag = str_replace(' ', '', ucwords(str_replace('-', ' ', $tag)));
                }
                $refs[] = ['symbol' => $tag, 'line' => $this->lineAt($markup, $match[1])];
            }
        }

        $lines = substr_count($content, "\n") + 1;
        $props = count(array_filter($defs, static fn (array $d): bool => $d['kind'] === 'prop'));
        $emits = count(array_filter($defs, static fn (array $d): bool => $d['kind'] === 'emit'));
        $summary = count($defs).' def(s)'
            .($props ? "; {$props} prop(s)" : '')
            .($emits ? "; {$emits} emit(s)" : '')
            .'; '.count(array_unique(array_column($refs, 'symbol'))).' import/component ref(s); '.$lines.' lines';

        return [
            'type'      => 'vue',
            'ns'        => null,
            'defs'      => $defs,
            'refs'      => $refs,
            'hierarchy' => [],
            'summary'   => $summary,
        ];
    }

    /**
     * defineProps / defineEmits (script setup) → defs of kind `prop` / `emit`.
     * Handles the TS generic form (`defineProps<{ a: string }>()`, `defineProps<Props>()`
     * resolved through a local `interface Props {}` / `type Props = {}`), the object
     * form (`defineProps({ a: String })`), the array form (`defineProps(['a'])`),
     * `withDefaults(defineProps<...>(), {...})`, and emits as call signatures
     * (`(e: 'save', id: number): void`), named tuples (`save: [id: number]`) or arrays.
     *
     * @return list<array{name: string, kind: string, line: int, end_line: int}>
     */
    private function extractVueMacros(string $script, int $baseOffset, string $full): array
    {
        $defs = [];

        foreach (['defineProps' => 'prop', 'defineEmits' => 'emit'] as $macro => $kind) {
            if (! preg_match('/\b'.$macro.'\b\s*(<|\()/', $script, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = $m[1][1];
            $isGeneric = $m[1][0] === '<';
            $block = '';
            $blockOffset = 0;

            if ($isGeneric) {
                $close = $this->findMatchingDelimiter($script, $open, '<', '>');
                $generic = trim(substr($script, $open + 1, $close - $open - 1));
                if (preg_match('/^[A-Za-z_$][\w$]*$/', $generic)) {
                    // defineProps<Props>() — find `interface Props {` / `type Props = {`
                    if (preg_match('/\b(?:interface|type)\s+'.preg_quote($generic, '/').'\b[^{]*\{/', $script, $tm, PREG_OFFSET_CAPTURE)) {
                        $brace = $tm[0][1] + strlen($tm[0][0]) - 1;
                        $end = $this->findMatchingDelimiter($script, $brace, '{', '}');
                        $block = substr($script, $brace, $end - $brace + 1);
                        $blockOffset = $brace;
                    }
                } elseif (str_starts_with($generic, '{')) {
                    $brace = strpos($script, '{', $open);
                    $end = $this->findMatchingDelimiter($script, $brace, '{', '}');
                    $block = substr($script, $brace, $end - $brace + 1);
                    $blockOffset = $brace;
                }
            } else {
                $close = $this->findMatchingDelimiter($script, $open, '(', ')');
                $inner = substr($script, $open + 1, $close - $open - 1);
                $trimmed = ltrim($inner);
                $lead = $open + 1 + (strlen($inner) - strlen($trimmed));
                if (str_starts_with($trimmed, '{')) {
                    $end = $this->findMatchingDelimiter($script, $lead, '{', '}');
                    $block = substr($script, $lead, $end - $lead + 1);
                    $blockOffset = $lead;
                } elseif (str_starts_with($trimmed, '[')) {
                    // Array form: names are string literals.
                    if (preg_match_all('/[\'"]([A-Za-z_$][\w$:-]*)[\'"]/', $trimmed, $sm, PREG_OFFSET_CAPTURE)) {
                        foreach ($sm[1] as $s) {
                            $line = $this->lineAt($full, $lead + $s[1] + $baseOffset);
                            $defs[] = ['name' => $s[0], 'kind' => $kind, 'line' => $line, 'end_line' => $line];
                        }
                    }

                    continue;
                }
            }

            if ($block === '') {
                continue;
            }

            // Top-level members of the block: split its inside at depth-0 `;` `,` and
            // newlines, then read `name?: type` / `name: String` / `name: [..]` off each
            // segment. A nested object value stays inside its segment (depth > 0), so
            // its keys never leak out as props. Call-signature emits
            // `(e: 'save', id: number): void` carry the name in the literal.
            $inner = substr($block, 1, -1);
            $depth = 0;
            $segStart = 0;
            $len = strlen($inner);
            for ($i = 0; $i <= $len; $i++) {
                $ch = $i < $len ? $inner[$i] : "\n";
                if ($depth === 0 && ($ch === ';' || $ch === ',' || $ch === "\n")) {
                    $segment = substr($inner, $segStart, $i - $segStart);
                    $name = null;
                    if ($kind === 'emit' && preg_match('/^\s*\(\s*e\w*\s*:\s*[\'"]([^\'"]+)[\'"]/', $segment, $km)) {
                        $name = $km[1];
                    } elseif (preg_match('/^\s*(?:readonly\s+)?([A-Za-z_$][\w$]*)\s*\??\s*:/', $segment, $km)) {
                        $name = $km[1];
                    }
                    if ($name !== null) {
                        $lead = strlen($segment) - strlen(ltrim($segment));
                        $line = $this->lineAt($full, $blockOffset + 1 + $segStart + $lead + $baseOffset);
                        $defs[] = ['name' => $name, 'kind' => $kind, 'line' => $line, 'end_line' => $line];
                    }
                    $segStart = $i + 1;
                } elseif ($ch === '{' || $ch === '[' || $ch === '(' || $ch === '<') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']' || $ch === ')' || ($ch === '>' && ($i === 0 || $inner[$i - 1] !== '='))) {
                    $depth--; // `=>` is an arrow, not a generic close
                }
            }
        }

        return $defs;
    }

    // ── Blade ────────────────────────────────────────────────────────────────

    private function parseBlade(string $content): array
    {
        $defs = [];
        $refs = [];

        // @extends('layout') / @include('view') / @includeIf / @includeWhen / @component('view')
        if (preg_match_all('/@(?:extends|include|includeIf|includeWhen|component)\(\s*[\'"]([a-zA-Z0-9_.\-\/:]+)[\'"]/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $refs[] = ['symbol' => trim($match[0]), 'line' => $this->lineAt($content, $match[1])];
            }
        }

        // <x-component-name ...> / <x-namespace::name ...>
        if (preg_match_all('/<x-([a-zA-Z0-9_.\-]+(?:::[a-zA-Z0-9_.\-]+)?)/', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $refs[] = ['symbol' => 'x-'.trim($match[0]), 'line' => $this->lineAt($content, $match[1])];
            }
        }

        // @section('name') as defs — no reliable single-token or brace terminator in
        // Blade markup (mixed HTML/`{{ }}` echoes), so end_line stays same as line.
        if (preg_match_all('/@section\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $line = $this->lineAt($content, $match[1]);
                $defs[] = ['name' => trim($match[0]), 'kind' => 'section', 'line' => $line, 'end_line' => $line, 'ns' => null];
            }
        }

        $lines = substr_count($content, "\n") + 1;

        return [
            'type'      => 'blade',
            'ns'        => null,
            'defs'      => $defs,
            'refs'      => $refs,
            'hierarchy' => [],
            'summary'   => count($defs).' section(s); '.count(array_unique(array_column($refs, 'symbol'))).' include/component ref(s); '.$lines.' lines',
        ];
    }

    // ── JS / TS ──────────────────────────────────────────────────────────────

    private function parseScript(string $content, string $ext): array
    {
        $data = $this->parseScriptContent($content, 0, $content);
        $lines = substr_count($content, "\n") + 1;

        return [
            'type'      => $ext,
            'ns'        => null,
            'defs'      => $data['defs'],
            'refs'      => $data['refs'],
            'hierarchy' => [],
            'summary'   => count($data['defs']).' def(s); '.count(array_unique(array_column($data['refs'], 'symbol'))).' import(s); '.$lines.' lines',
        ];
    }

    /**
     * @param string $fullContent The whole file content (for Vue, includes markup outside
     *                             the <script> block) — end-offset brace-matching needs to
     *                             see past the fragment being parsed, so it's passed in
     *                             separately from $content (which may be just the script body).
     */
    private function parseScriptContent(string $content, int $baseOffset = 0, ?string $fullContent = null): array
    {
        $defs = [];
        $refs = [];
        $full = $fullContent ?? $content;

        // Functions — `(?:default\s+)?` mirrors the Classes rule below so
        // `export default function Foo()` (the standard Next.js/React page and
        // component export shape) is indexed the same way `export default class`
        // already is; previously it silently matched neither branch.
        if (preg_match_all('/^(?:export\s+(?:default\s+)?)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $absOffset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'function',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Arrow-function consts: const foo = (...) => {...}  /  const foo = async (...) => {...}
        if (preg_match_all('/^(?:export\s+)?const\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s+)?\([^)\n]*\)\s*(?::\s*[^=\n]+)?=>/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                // Search from the end of the full match (past the =>), not just past the
                // name, since arrow functions may have an expression body with no braces
                // at all — findDeclarationEnd's semicolon-first branch then correctly
                // bounds it there instead of scanning into unrelated later code.
                $matchEnd = $match[1] + strlen($match[0]) + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $matchEnd);
                $defs[] = [
                    'name' => $name,
                    'kind' => 'function',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Classes
        if (preg_match_all('/^(?:export\s+(?:default\s+)?)?class\s+([A-Za-z_$][A-Za-z0-9_$]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $absOffset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'class',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Interfaces + types (TS)
        if (preg_match_all('/^(?:export\s+)?interface\s+([A-Za-z_$][A-Za-z0-9_$]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $absOffset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'interface',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }
        if (preg_match_all('/^export\s+type\s+([A-Za-z_$][A-Za-z0-9_$]*)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $absOffset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'type',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Exported constants only (top-level, line starts with export; excludes arrow-function assignments, indexed above as 'function')
        if (preg_match_all('/^export\s+const\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=(?!\s*(?:async\s+)?\([^)\n]*\)\s*(?::\s*[^=\n]+)?=>)/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                $absOffset = $match[1] + $baseOffset;
                $endOffset = $this->findDeclarationEnd($full, $absOffset + strlen($name));
                $defs[] = [
                    'name' => $name,
                    'kind' => 'constant',
                    'line' => $this->lineAt($full, $absOffset),
                    'end_line' => $this->lineAt($full, $endOffset),
                    'ns' => null,
                ];
            }
        }

        // Named export blocks: export { Foo, Bar as Baz } — single-line by nature, no body to capture.
        if (preg_match_all('/^export\s*\{([^}]+)\}/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $line = $this->lineAt($full, $match[1] + $baseOffset);
                foreach (preg_split('/,/', $match[0]) as $name) {
                    $name = trim(preg_replace('/\s+as\s+\S+/', '', $name));
                    if ($name !== '') {
                        $defs[] = ['name' => $name, 'kind' => 'export', 'line' => $line, 'end_line' => $line, 'ns' => null];
                    }
                }
            }
        }

        // Imports: paths as refs
        if (preg_match_all('/^import\s+.*?from\s+["\']([^"\']+)["\']/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $refs[] = ['symbol' => $match[0], 'line' => $this->lineAt($full, $match[1] + $baseOffset)];
            }
        }

        // Imports: named symbols as refs
        if (preg_match_all('/^import\s+\{([^}]+)\}\s+from/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $line = $this->lineAt($full, $match[1] + $baseOffset);
                foreach (preg_split('/,/', $match[0]) as $name) {
                    $name = trim(preg_replace('/\s+as\s+\S+/', '', $name));
                    if ($name !== '' && $name !== 'type') {
                        $refs[] = ['symbol' => $name, 'line' => $line];
                    }
                }
            }
        }

        // Default imports
        if (preg_match_all('/^import\s+([A-Za-z_$][A-Za-z0-9_$]*)\s+from/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = trim($match[0]);
                if ($name !== 'type') {
                    $refs[] = ['symbol' => $name, 'line' => $this->lineAt($full, $match[1] + $baseOffset)];
                }
            }
        }

        // Ziggy route('name') calls — same symbol shape as the PHP side. For a Vue file
        // the whole SFC is scanned (template `:href="route('x')"` is where most live).
        foreach ($this->extractRouteNameRefs($full) as $ref) {
            $refs[] = $ref;
        }

        return ['defs' => $defs, 'refs' => $refs];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildSummary(array $counts, int $lines): string
    {
        $labels = [
            'classes'    => 'class(es)',
            'structs'    => 'struct(s)',
            'interfaces' => 'interface(s)',
            'traits'     => 'trait(s)',
            'functions'  => 'function(s)',
        ];

        $parts = [];
        foreach ($labels as $key => $label) {
            if (! empty($counts[$key])) {
                $parts[] = $counts[$key].' '.$label;
            }
        }
        $parts[] = $lines.' lines';

        return implode('; ', $parts);
    }

    private function shouldIndex(string $relativePath): bool
    {
        return in_array(pathinfo($relativePath, PATHINFO_EXTENSION), $this->extensions, true);
    }

    private function isExcluded(string $relativePath): bool
    {
        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $segments   = explode(DIRECTORY_SEPARATOR, $normalised);

        foreach ($segments as $segment) {
            if (in_array($segment, $this->excludedDirs, true)) {
                return true;
            }
        }

        // Compound entries (e.g. 'bootstrap/cache') name a specific nested
        // directory, not a bare segment — the loop above only ever matches a
        // single path segment verbatim, so a two-part entry like this would
        // silently never match anything via that check alone. Check it as a
        // path-prefix instead.
        $forward = str_replace('\\', '/', $relativePath);
        foreach ($this->excludedDirs as $excluded) {
            if (str_contains($excluded, '/')
                && (str_starts_with($forward, $excluded.'/') || $forward === $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $absolutePath): string
    {
        $prefix = $this->root.DIRECTORY_SEPARATOR;
        $rel = str_starts_with($absolutePath, $prefix)
            ? substr($absolutePath, strlen($prefix))
            : $absolutePath;

        // Store paths with forward slashes regardless of OS so lookups are
        // consistent whether the caller passes "app/Foo.php" or "app\Foo.php".
        return str_replace('\\', '/', $rel);
    }

    private function lineAt(string $content, int $offset, int $baseOffset = 0): int
    {
        return substr_count(substr($content, 0, $offset + $baseOffset), "\n") + 1;
    }
}
