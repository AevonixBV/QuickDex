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

        return [
            'extends'    => $row['extends'],
            'implements' => json_decode($row['implements'], true) ?? [],
            'traits'     => json_decode($row['traits'], true) ?? [],
        ];
    }

    /** All classes that extend a given class. */
    public function children(string $class): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.class, d.file, d.line FROM hierarchy h LEFT JOIN defs d ON LOWER(d.name) = LOWER(h.class) AND d.kind = \'class\' WHERE LOWER(h.extends) = LOWER(?) ORDER BY h.class'
        );
        $stmt->execute([$class]);

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
     * Search all route files (routes/*.php) for a string.
     * Useful for finding route names, controllers, URIs, or middleware without reading files.
     */
    public function route(string $search): array
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
