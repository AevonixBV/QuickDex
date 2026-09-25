<?php

declare(strict_types=1);

namespace QuickDex;

final class QueryEngine
{
    private \PDO $db;

    public function __construct(string $dbPath)
    {
        if (! file_exists($dbPath)) {
            throw new \RuntimeException("Index not found: {$dbPath}\nRun: quickdex index");
        }

        $this->db = new \PDO('sqlite:'.$dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA query_only = ON');
    }

    /** Where is a symbol defined? Generated (Wayfinder) defs sort last. */
    public function def(string $name): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, kind, file, line, end_line, ns, generated FROM defs WHERE LOWER(name) = LOWER(?) ORDER BY generated, file, line'
        );
        $stmt->execute([$name]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Which files (and lines) reference a symbol or import path? */
    public function refs(string $symbol): array
    {
        $stmt = $this->db->prepare(
            'SELECT file, line FROM refs WHERE LOWER(symbol) = LOWER(?) ORDER BY file, line'
        );
        $stmt->execute([$symbol]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (! $rows) {
            // Suffix fallback: "pages/Calendar" matches stored "@/pages/Calendar" import paths
            $stmt = $this->db->prepare(
                'SELECT file, line FROM refs WHERE symbol LIKE ? ORDER BY file, line'
            );
            $stmt->execute(['%'.$symbol]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;
    }

    /** List files, optionally filtered by type, namespace prefix, and/or path prefix. */
    public function files(string $type = '', string $ns = '', string $path = ''): array
    {
        $where  = [];
        $params = [];

        if ($type !== '') {
            $where[]  = 'type = ?';
            $params[] = $type;
        }
        if ($ns !== '') {
            $where[]  = 'ns LIKE ?';
            $params[] = $ns.'%';
        }
        if ($path !== '') {
            $where[]  = 'path LIKE ?';
            $params[] = $path.'%';
        }

        $sql = 'SELECT path, type, lines, ns, summary FROM files';
        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY path';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Class hierarchy: extends, implements, traits. */
    public function hier(string $class): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT extends, implements, traits FROM hierarchy WHERE LOWER(class) = LOWER(?)'
        );
        $stmt->execute([$class]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (! $row) {
            return null;
        }

        $file = $this->db->prepare("SELECT file FROM defs WHERE kind = 'class' AND LOWER(name) = LOWER(?) LIMIT 1");
        $file->execute([$class]);

        return [
            'extends'    => $row['extends'],
            'implements' => json_decode($row['implements'], true) ?? [],
            'traits'     => json_decode($row['traits'], true) ?? [],
            'file'       => $file->fetchColumn() ?: null,
        ];
    }

    /**
     * All classes that extend a given class. For Python, a class listing it as a
     * later base (a mixin, stored in `implements`) counts too.
     */
    public function children(string $class): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.class, d.file, d.line FROM hierarchy h LEFT JOIN defs d ON LOWER(d.name) = LOWER(h.class) AND d.kind = \'class\'
             WHERE LOWER(h.extends) = LOWER(?) OR (d.file LIKE \'%.py\' AND h.implements LIKE ?)
             ORDER BY h.class'
        );
        $stmt->execute([$class, '%"'.$class.'"%']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Fuzzy symbol search by name pattern. Generated (Wayfinder) defs sort last. */
    public function search(string $pattern, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, kind, file, line, end_line, ns, generated FROM defs WHERE LOWER(name) LIKE LOWER(?) ORDER BY generated, name, file LIMIT ?'
        );
        $stmt->execute(['%'.$pattern.'%', $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** All defs inside a specific file. */
    public function fileSymbols(string $path): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, kind, line, end_line, ns, generated FROM defs WHERE file = ? ORDER BY line'
        );
        $stmt->execute([str_replace('\\', '/', $path)]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** All imports/refs made by a specific file. */
    public function fileRefs(string $path): array
    {
        $stmt = $this->db->prepare(
            'SELECT symbol FROM refs WHERE file = ? ORDER BY symbol'
        );
        $stmt->execute([str_replace('\\', '/', $path)]);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'symbol');
    }

    /** Classes that use a given trait. */
    public function uses(string $trait): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.class, d.file, d.line
             FROM hierarchy h
             LEFT JOIN defs d ON LOWER(d.name) = LOWER(h.class) AND d.kind = \'class\'
             WHERE h.traits LIKE ?
             ORDER BY h.class'
        );
        $stmt->execute(['%'.$trait.'%']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Check whether a file contains each pattern (yes/no + line number).
     * $classOrPath: class name (resolved via index) or relative file path.
     * Returns one row per pattern: file, found (✅/❌), line, pattern.
     */
    public function patch(string $classOrPath, array $patterns): array
    {
        $root = $this->rootPath();

        $absPath = file_exists($root.DIRECTORY_SEPARATOR.$classOrPath)
            ? $root.DIRECTORY_SEPARATOR.$classOrPath
            : (file_exists($classOrPath) ? $classOrPath : null);

        if ($absPath === null) {
            $stmt = $this->db->prepare(
                'SELECT file FROM defs WHERE LOWER(name) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$classOrPath]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $absPath = $root.DIRECTORY_SEPARATOR.$row['file'];
            }
        }

        if ($absPath === null || ! file_exists($absPath)) {
            return [['file' => $classOrPath, 'found' => '❌ NOT FOUND', 'line' => '', 'pattern' => '(file)']];
        }

        $lines       = file($absPath, FILE_IGNORE_NEW_LINES) ?: [];
        $displayFile = basename($absPath);
        $results     = [];

        foreach ($patterns as $pattern) {
            $found   = false;
            $lineNum = '';
            foreach ($lines as $i => $line) {
                if (stripos($line, $pattern) !== false) {
                    $found   = true;
                    $lineNum = (string) ($i + 1);
                    break;
                }
            }
            $results[] = [
                'file'    => $displayFile,
                'found'   => $found ? '✅' : '❌',
                'line'    => $lineNum,
                'pattern' => $pattern,
            ];
        }

        return $results;
    }

    /**
     * Route table lookup (filled from `php artisan route:list --json` at index time).
     * Matches name, URI or action by case-insensitive substring. Empty when the
     * project is not Laravel or artisan could not run — callers fall back to
     * routeGrep() then.
     *
     * @return list<array{name: ?string, method: string, uri: string, action: string, controller: ?string, action_name: ?string, middleware: list<string>, file: ?string, line: ?int, page: ?string, ziggy: ?string}>
     */
    public function routes(string $search = '', int $limit = 200): array
    {
        $sql = 'SELECT name, method, uri, action, controller, action_name, middleware, file, line, page, ziggy FROM routes';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE LOWER(COALESCE(name, \'\')) LIKE ? OR LOWER(uri) LIKE ? OR LOWER(action) LIKE ?';
            $needle = '%'.strtolower($search).'%';
            $params = [$needle, $needle, $needle];
        }
        // Exact-name hits first, then app code before vendor routes, then by URI.
        $sql .= ' ORDER BY (LOWER(COALESCE(name, \'\')) = LOWER(?)) DESC, (action LIKE \'App\\%\' OR action = \'Closure\') DESC, uri LIMIT ?';
        $params[] = $search;
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->decodeRoutes($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function hasRoutes(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM routes')->fetchColumn() > 0;
    }

    /**
     * Everything the index knows about one Inertia page, by page name
     * ("Admin/Backlinks/Index") or Vue path ("resources/js/Pages/Admin/Backlinks/Index.vue").
     *
     * @return array{page: string, vue_file: ?string, renders: list<array{file: string, line: int, owner: ?string}>, routes: list<array<string, mixed>>, route_calls: list<array{name: string, line: int, ziggy: ?string, defined: bool}>, components: list<string>, audience: list<string>}
     */
    public function page(string $nameOrPath): array
    {
        $name = str_replace('\\', '/', $nameOrPath);
        if (preg_match('#^resources/js/[Pp]ages/(.+)\.vue$#', $name, $m)) {
            $name = $m[1];
        }
        $name = preg_replace('/\.vue$/', '', $name) ?? $name;

        $vueFile = null;
        $stmt = $this->db->prepare('SELECT path FROM files WHERE path = ? OR path = ? LIMIT 1');
        $stmt->execute(['resources/js/Pages/'.$name.'.vue', 'resources/js/pages/'.$name.'.vue']);
        $found = $stmt->fetchColumn();
        if ($found !== false) {
            $vueFile = (string) $found;
        }

        $stmt = $this->db->prepare('SELECT file, line, owner FROM pages WHERE LOWER(page) = LOWER(?) ORDER BY file, line');
        $stmt->execute([$name]);
        $renders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // No static Inertia::render('X')? The name is probably data — a registry entry
        // (`'vuePage' => 'Tools/SslChecker'`) fed to Inertia::render($definition->vuePage).
        // Find the literal so the reader lands on the registry, not on a dead end.
        $literals = [];
        if ($renders === []) {
            foreach ($this->grep(["'".$name."'", '"'.$name.'"'], $this->existingDirs(['app', 'routes', 'config', 'database']), ['php'], 20) as $hit) {
                $literals[] = ['file' => $hit['file'], 'line' => (int) $hit['line'], 'content' => $hit['content']];
            }
        }

        $stmt = $this->db->prepare('SELECT name, method, uri, action, controller, action_name, middleware, file, line, page, ziggy FROM routes WHERE LOWER(page) = LOWER(?) ORDER BY uri');
        $stmt->execute([$name]);
        $routes = $this->decodeRoutes($stmt->fetchAll(\PDO::FETCH_ASSOC));

        $audience = $this->audienceOf($routes);

        $routeCalls = [];
        $components = [];
        if ($vueFile !== null) {
            $routeCalls = $this->routeCallsIn($vueFile);
            $stmt = $this->db->prepare(
                "SELECT DISTINCT r.symbol FROM refs r WHERE r.file = ? AND r.symbol GLOB '[A-Z]*' AND r.symbol NOT LIKE '%.%' AND r.symbol NOT LIKE '%/%' ORDER BY r.symbol"
            );
            $stmt->execute([$vueFile]);
            $components = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return [
            'page' => $name,
            'vue_file' => $vueFile,
            'renders' => $renders,
            'literals' => $literals,
            'routes' => $routes,
            'route_calls' => $routeCalls,
            'components' => $components,
            'audience' => $audience,
        ];
    }

    /**
     * Ziggy audience lint: every route('name') call inside a Vue page must resolve in
     * the route group(s) that page is served with, or Ziggy throws and the whole page
     * (SSR and client) dies with no console error in a prod build. Audience is derived
     * from the middleware of the route(s) that render the page: `auth:admin` → admin,
     * any Authenticate → authenticated, else guest. `guest` is always present.
     *
     * Pages the index cannot attribute to a route (dynamic `Inertia::render($x)`)
     * are reported as unattributed and not linted.
     *
     * @return array{violations: list<array{page: string, vue_file: string, audience: list<string>, route: string, ziggy: ?string, line: int}>, unattributed: list<string>, checked: int}
     */
    public function ziggyCheck(): array
    {
        $violations = [];
        $unattributed = [];
        $checked = 0;
        $guarded = 0;

        $files = $this->db->query("SELECT path FROM files WHERE type = 'vue' AND (path LIKE 'resources/js/Pages/%' OR path LIKE 'resources/js/pages/%') ORDER BY path")->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($files as $vueFile) {
            $pageName = preg_replace('#^resources/js/[Pp]ages/(.+)\.vue$#', '$1', $vueFile);
            $stmt = $this->db->prepare('SELECT name, method, uri, action, controller, action_name, middleware, file, line, page, ziggy FROM routes WHERE LOWER(page) = LOWER(?)');
            $stmt->execute([$pageName]);
            $routes = $this->decodeRoutes($stmt->fetchAll(\PDO::FETCH_ASSOC));
            if ($routes === []) {
                $unattributed[] = $vueFile;

                continue;
            }
            $checked++;
            $audience = $this->audienceOf($routes);

            foreach ($this->routeCallsIn($vueFile) as $call) {
                if ($call['guard'] === 'comment') {
                    continue;
                }
                if ($call['guard'] === 'guarded') {
                    $guarded++;

                    continue;
                }
                if (! $call['defined']) {
                    $violations[] = ['page' => $pageName, 'vue_file' => $vueFile, 'audience' => $audience, 'route' => $call['name'], 'ziggy' => null, 'line' => $call['line'], 'reason' => 'route does not exist'];

                    continue;
                }
                $groups = $call['ziggy'] === null ? [] : explode(',', $call['ziggy']);
                if ($groups === []) {
                    $violations[] = ['page' => $pageName, 'vue_file' => $vueFile, 'audience' => $audience, 'route' => $call['name'], 'ziggy' => null, 'line' => $call['line'], 'reason' => 'route is in no Ziggy group'];

                    continue;
                }
                if (array_intersect($groups, $audience) === []) {
                    $violations[] = ['page' => $pageName, 'vue_file' => $vueFile, 'audience' => $audience, 'route' => $call['name'], 'ziggy' => $call['ziggy'], 'line' => $call['line'], 'reason' => 'group not served to this audience'];
                }
            }
        }

        return ['violations' => $violations, 'unattributed' => $unattributed, 'checked' => $checked, 'guarded' => $guarded];
    }

    /**
     * Tests that exercise a class: the conventionally named `<Class>Test` first, then
     * every file under tests/ that references the class by name.
     *
     * @return list<array{file: string, line: int, how: string}>
     */
    public function tests(string $class): array
    {
        $rows = [];
        $seen = [];

        $stmt = $this->db->prepare("SELECT file, line FROM defs WHERE kind = 'class' AND LOWER(name) = LOWER(?) AND file LIKE 'tests/%' ORDER BY file");
        $stmt->execute([$class.'Test']);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['file' => $r['file'], 'line' => (int) $r['line'], 'how' => 'named '.$class.'Test'];
            $seen[$r['file']] = true;
        }

        $stmt = $this->db->prepare("SELECT file, MIN(line) AS line FROM refs WHERE LOWER(symbol) = LOWER(?) AND file LIKE 'tests/%' GROUP BY file ORDER BY file");
        $stmt->execute([$class]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (! isset($seen[$r['file']])) {
                $rows[] = ['file' => $r['file'], 'line' => (int) $r['line'], 'how' => 'references '.$class];
            }
        }

        return $rows;
    }

    /**
     * One-screen repo shape: file counts per area, route/page/test totals. Meant as the
     * first call in a fresh session instead of `ls -R` and a dozen Reads.
     *
     * @return array{areas: list<array{area: string, files: int, lines: int}>, totals: array<string, int>}
     */
    public function overview(): array
    {
        $rows = $this->db->query("
            SELECT
                CASE
                    WHEN path LIKE 'app/%' THEN 'app/' || substr(path, 5, CASE WHEN instr(substr(path, 5), '/') = 0 THEN 0 ELSE instr(substr(path, 5), '/') - 1 END)
                    WHEN path LIKE 'resources/js/%' THEN 'resources/js/' || substr(path, 14, CASE WHEN instr(substr(path, 14), '/') = 0 THEN 0 ELSE instr(substr(path, 14), '/') - 1 END)
                    WHEN path LIKE 'tests/%' THEN 'tests/' || substr(path, 7, CASE WHEN instr(substr(path, 7), '/') = 0 THEN 0 ELSE instr(substr(path, 7), '/') - 1 END)
                    WHEN instr(path, '/') = 0 THEN '(root)'
                    ELSE substr(path, 1, instr(path, '/') - 1)
                END AS area,
                COUNT(*) AS files,
                SUM(lines) AS lines
            FROM files
            GROUP BY area
            ORDER BY files DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $areas = array_map(static fn (array $r): array => ['area' => (string) $r['area'], 'files' => (int) $r['files'], 'lines' => (int) $r['lines']], $rows);

        $count = fn (string $sql): int => (int) $this->db->query($sql)->fetchColumn();

        return [
            'areas' => $areas,
            'totals' => [
                'files' => $count('SELECT COUNT(*) FROM files'),
                'classes' => $count("SELECT COUNT(*) FROM defs WHERE kind = 'class' AND generated = 0"),
                'routes' => $count('SELECT COUNT(*) FROM routes'),
                'routes_app' => $count("SELECT COUNT(*) FROM routes WHERE action LIKE 'App\\%' OR action = 'Closure'"),
                'inertia_pages' => $count('SELECT COUNT(DISTINCT page) FROM pages'),
                'vue_pages' => $count("SELECT COUNT(*) FROM files WHERE type = 'vue' AND (path LIKE 'resources/js/Pages/%' OR path LIKE 'resources/js/pages/%')"),
                'vue_components' => $count("SELECT COUNT(*) FROM files WHERE type = 'vue' AND path NOT LIKE 'resources/js/Pages/%' AND path NOT LIKE 'resources/js/pages/%'"),
                'tests' => $count("SELECT COUNT(*) FROM defs WHERE kind = 'class' AND file LIKE 'tests/%' AND name LIKE '%Test'"),
                'migrations' => $count("SELECT COUNT(*) FROM files WHERE path LIKE 'database/migrations/%'"),
            ],
        ];
    }

    /** route('name') literals inside one file, joined to the route table. */
    private function routeCallsIn(string $file): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.symbol AS name, r.line, t.ziggy, t.name IS NOT NULL AS defined
             FROM refs r
             LEFT JOIN routes t ON t.name = r.symbol
             WHERE r.file = ? AND r.symbol LIKE '%.%' AND r.symbol NOT LIKE '%/%' AND r.symbol NOT LIKE '%.vue' AND r.symbol NOT LIKE '%.js' AND r.symbol NOT LIKE '%.ts'
             ORDER BY r.line"
        );
        $stmt->execute([$file]);
        $rows = [];
        $lines = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            // A dotted symbol that is neither a known route nor route-shaped (e.g. an
            // import path) is noise; keep unknown names only when they look like routes.
            if (! $r['defined'] && ! preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', (string) $r['name'])) {
                continue;
            }
            if ($lines === null) {
                $abs = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
                $lines = is_file($abs) ? (file($abs, FILE_IGNORE_NEW_LINES) ?: []) : [];
            }
            $rows[] = [
                'name' => (string) $r['name'],
                'line' => (int) $r['line'],
                'ziggy' => $r['ziggy'],
                'defined' => (bool) $r['defined'],
                'guard' => $this->guardAt($lines, (int) $r['line']),
            ];
        }

        return $rows;
    }

    /**
     * How a route() call at $line is protected from the Ziggy audience problem:
     * `comment` (the call sits in a comment), `guarded` (an auth check — `v-if` on the
     * user, an `isAuthed ?:` ternary, `$page.props.auth.user` — appears on the line or
     * within the 25 lines above it, i.e. the enclosing element or expression), or `none`.
     *
     * @param  list<string>  $lines
     */
    private function guardAt(array $lines, int $line): string
    {
        $text = trim($lines[$line - 1] ?? '');
        if (preg_match('#^(//|/\*|\*|<!--)#', $text)) {
            return 'comment';
        }

        // A guard is an auth test on the call line itself (`isAuthed ? route(..) : ..`),
        // or a `v-if`/`v-show`/`v-else-if` on the user within the 25 lines above (the
        // enclosing element). A declaration such as `const isAuthed = computed(...)`
        // is not a guard, however close it sits. `ziggy` in the window = the page ships
        // its own route table with the props (the documented hand-off pattern for
        // pages a guest reaches before login).
        $auth = '/auth\??\.user|isAuthed|isAuthenticated|props\.auth\b|auth\.check\(\)/i';
        for ($i = $line - 1; $i >= max(0, $line - 26); $i--) {
            $candidate = $lines[$i] ?? '';
            if (preg_match('/^\s*(const|let|var|import|function)\b/', $candidate)) {
                continue;
            }
            if (preg_match('/\bziggy\b/i', $candidate)) {
                return 'guarded';
            }
            if (! preg_match($auth, $candidate)) {
                continue;
            }
            if ($i === $line - 1 || preg_match('/\bv-(if|show|else-if)\s*=/', $candidate)) {
                return 'guarded';
            }
        }

        return 'none';
    }

    /** @param list<string> $dirs  @return list<string> those that exist under the project root */
    private function existingDirs(array $dirs): array
    {
        $root = $this->rootPath();

        return array_values(array_filter($dirs, static fn (string $d): bool => is_dir($root.DIRECTORY_SEPARATOR.$d)));
    }

    /** @param list<array<string, mixed>> $routes */
    private function audienceOf(array $routes): array
    {
        $audience = ['guest'];
        foreach ($routes as $route) {
            foreach ($route['middleware'] as $mw) {
                $mw = (string) $mw;
                if (preg_match('/(^|\\\\)Authenticate(:|$)|^auth(:|$)/', $mw)) {
                    $guard = str_contains($mw, ':') ? substr($mw, strpos($mw, ':') + 1) : 'web';
                    $audience[] = $guard === 'admin' ? 'admin' : 'authenticated';
                }
            }
        }

        return array_values(array_unique($audience));
    }

    private function decodeRoutes(array $rows): array
    {
        foreach ($rows as &$r) {
            $r['middleware'] = json_decode((string) ($r['middleware'] ?? '[]'), true) ?: [];
            $r['line'] = $r['line'] === null ? null : (int) $r['line'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Fallback for projects without a route table: search routes/*.php line-by-line.
     * Useful for finding route names, controllers, URIs, or middleware without reading files.
     */
    public function routeGrep(string $search): array
    {
        $root     = $this->rootPath();
        $routeDir = $root.DIRECTORY_SEPARATOR.'routes';

        if (! is_dir($routeDir)) {
            return [];
        }

        $results = [];
        $files   = glob($routeDir.DIRECTORY_SEPARATOR.'*.php') ?: [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                if (stripos($line, $search) !== false) {
                    $results[] = [
                        'file'    => 'routes/'.basename($file),
                        'line'    => (string) ($i + 1),
                        'content' => trim($line),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Scan files under $dir (relative to project root, default = root) for a literal
     * substring, restricted to $extensions. Same exclusion rules as the indexer.
     * Use for content search QuickDex's symbol index can't answer (Blade markup,
     * config values, translation strings, etc).
     */
    /**
     * @param  list<string>  $patterns  matched with OR semantics — a line matching ANY pattern is
     *                                  returned once. Pass multiple patterns instead of `\|`/regex
     *                                  alternation syntax, which this method never supported (it's
     *                                  a literal case-insensitive substring match, not regex) and
     *                                  which crashes the .bat wrapper on Windows anyway (`|` and
     *                                  `()` are cmd.exe metacharacters — see grep case in query.php).
     */
    public function grep(array $patterns, array $dirs = [], array $extensions = ['php', 'vue', 'js', 'ts', 'blade', 'yml', 'yaml', 'go', 'py'], int $limit = 200, bool $filesOnly = false, bool $includeNoisy = false): array
    {
        $root = $this->rootPath();
        $searchRoots = $dirs === []
            ? [$root]
            : array_map(fn ($d) => $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $d), $dirs);

        // Segment excludes (any path component of this name) + compound excludes
        // (a specific nested path only) — kept in sync with SourceIndexer::$excludedDirs
        // so grep and the symbol index cover the same tree. Note plain `bootstrap` is
        // NOT excluded: bootstrap/app.php and providers are real config surface.
        $excludedSegments = ['vendor', 'node_modules', '.git', '.claude', 'storage', 'tmp', '.idea', '.vscode', 'coverage', 'dist', '.output'];
        $excludedCompound = ['bootstrap/cache', 'bootstrap/ssr', 'public/build'];
        // High-noise generated/lock files — excluded unless --all. See PLAN_IMPROVEMENTS P3.3.
        $noisyFiles = ['package-lock.json', 'composer.lock', 'yarn.lock', 'pnpm-lock.yaml'];

        $extensions = array_map('strtolower', $extensions);
        $results = [];
        $seenFiles = [];

        foreach ($searchRoots as $searchRoot) {
            if (! is_dir($searchRoot)) {
                continue; // caller (query.php) validates & reports invalid --dir
            }

            // Prune excluded directories BEFORE recursing into them, mirroring
            // SourceIndexer::collectFiles() — a plain RecursiveDirectoryIterator
            // enumerates every file inside vendor/node_modules/.git only to
            // discard each one afterwards, which made a root-wide grep (no --dir)
            // take minutes on Windows instead of seconds.
            $excluded = function (string $path) use ($root, $excludedSegments, $excludedCompound): bool {
                $rel = str_starts_with($path, $root.DIRECTORY_SEPARATOR) ? substr($path, strlen($root) + 1) : $path;
                $rel = str_replace('\\', '/', $rel);

                foreach (explode('/', $rel) as $segment) {
                    if (in_array($segment, $excludedSegments, true)) {
                        return true;
                    }
                }
                foreach ($excludedCompound as $compound) {
                    if ($rel === $compound || str_starts_with($rel, $compound.'/')) {
                        return true;
                    }
                }

                return false;
            };

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($searchRoot, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $current): bool => ! $excluded($current->getPathname()),
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $item) {
                if (! $item->isFile()) {
                    continue;
                }

                $path = $item->getPathname();
                $rel = str_starts_with($path, $root.DIRECTORY_SEPARATOR) ? substr($path, strlen($root) + 1) : $path;
                $rel = str_replace('\\', '/', $rel);

                $base = basename($path);
                if (! $includeNoisy && (in_array($base, $noisyFiles, true) || str_contains($base, '.min.'))) {
                    continue;
                }

                $lower = strtolower($path);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $matchesExt = false;
                foreach ($extensions as $wanted) {
                    if ($wanted === 'blade' ? str_ends_with($lower, '.blade.php') : $ext === $wanted) {
                        $matchesExt = true;
                        break;
                    }
                }
                if (! $matchesExt) {
                    continue;
                }

                $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

                foreach ($lines as $i => $line) {
                    foreach ($patterns as $pattern) {
                        if (stripos($line, $pattern) !== false) {
                            if ($filesOnly) {
                                if (! isset($seenFiles[$rel])) {
                                    $seenFiles[$rel] = true;
                                    $results[] = ['file' => $rel];
                                    if (count($results) >= $limit) {
                                        return $results;
                                    }
                                }
                                break 2; // next file — one hit is enough in files-only mode
                            }
                            $results[] = ['file' => $rel, 'line' => (string) ($i + 1), 'content' => trim($line)];
                            if (count($results) >= $limit) {
                                return $results;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /** Absolute project root the indexed paths are relative to (for callers that must validate paths). */
    public function projectRoot(): string
    {
        return $this->rootPath();
    }

    /** Index metadata. */
    public function meta(): array
    {
        $stmt = $this->db->query('SELECT key, value FROM meta');

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'value', 'key');
    }

    /**
     * Return the exact source lines for every def matching $name, straight from
     * the index's stored line/end_line range — no separate Read of the whole
     * file needed for the common "show me this function/class" case.
     * Caps both the number of matches shown and the size of any single
     * snippet, since a common method name (e.g. "store") can match dozens of
     * unrelated defs and a class can span thousands of lines — either would
     * defeat the point of a token-saving command if left unbounded.
     */
    public function body(string $name, int $limit = 10, int $maxLines = 400): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, kind, file, line, end_line, ns, generated FROM defs WHERE LOWER(name) = LOWER(?) ORDER BY generated, file, line'
        );
        $stmt->execute([$name]);
        $defs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = count($defs);
        $truncatedMatches = $total > $limit;
        $defs = array_slice($defs, 0, $limit);

        $root = $this->rootPath();
        $results = [];

        foreach ($defs as $def) {
            $absPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $def['file']);
            if (! file_exists($absPath)) {
                continue;
            }

            $lines = file($absPath, FILE_IGNORE_NEW_LINES) ?: [];
            $start = max(1, (int) $def['line']);
            $end = max($start, (int) $def['end_line']);
            $end = min($end, count($lines));

            $truncatedBody = ($end - $start + 1) > $maxLines;
            if ($truncatedBody) {
                $end = $start + $maxLines - 1;
            }

            $snippet = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

            $results[] = [
                'name' => $def['name'],
                'kind' => $def['kind'],
                'file' => $def['file'],
                'line' => $def['line'],
                'end_line' => $def['end_line'],
                'ns' => $def['ns'],
                'source' => $snippet,
                'truncated' => $truncatedBody,
            ];
        }

        return [
            'matches' => $results,
            'total_matches' => $total,
            'truncated_matches' => $truncatedMatches,
        ];
    }

    /** Project root the indexed paths are relative to, per the index's own meta row. */
    private function rootPath(): string
    {
        $stmt = $this->db->query("SELECT value FROM meta WHERE key = 'root'");
        $root = $stmt ? $stmt->fetchColumn() : false;

        return $root !== false && $root !== '' ? $root : dirname(__DIR__, 2);
    }
}
