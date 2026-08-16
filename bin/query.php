#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/../src/QueryEngine.php';

use QuickDex\QueryEngine;

/**
 * Resolve the database path: explicit QUICKDEX_DB wins, else walk up from the
 * current directory to the nearest quickdex.db. Returns null when none is found —
 * we deliberately do NOT fall back to a repo-relative path, which previously served
 * a stray multi-project index sitting at Development/quickdex.db. See P1.2.
 */
function resolveDbPath(): ?string
{
    $env = getenv('QUICKDEX_DB');
    if ($env !== false && $env !== '') {
        return $env;
    }

    $dir = getcwd();
    while ($dir !== false) {
        $candidate = $dir.DIRECTORY_SEPARATOR.'quickdex.db';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
}

/**
 * If the on-disk index predates the current schema, rebuild it once before querying.
 * Without this, a query issued against an older DB would hit a "no such column" fatal
 * the moment the schema gains a column (e.g. defs.generated in 2.1). The full rebuild
 * is the same operation `--force` triggers, and only runs on an actual version mismatch.
 */
function ensureSchemaCurrent(?string $dbPath): void
{
    if ($dbPath === null || ! is_file($dbPath)) {
        return;
    }

    try {
        $raw = new \PDO('sqlite:'.$dbPath);
        $raw->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ver  = (string) ($raw->query("SELECT value FROM meta WHERE key = 'version'")->fetchColumn() ?: '');
        $root = (string) ($raw->query("SELECT value FROM meta WHERE key = 'root'")->fetchColumn() ?: '');
    } catch (\Throwable) {
        return;
    }
    $raw = null;

    require_once __DIR__.'/../src/SourceIndexer.php';
    if ($ver !== \QuickDex\SourceIndexer::SCHEMA_VERSION && $root !== '' && is_dir($root)) {
        (new \QuickDex\SourceIndexer($root, $dbPath))->build(true);
        echo "(index schema updated — rebuilt)\n";
    }
}

$dbPath  = resolveDbPath();
$argv    = array_values($argv);
$command = $argv[1] ?? 'help';
$args    = array_slice($argv, 2);

function arg(array $args, int $idx, string $flag = ''): ?string
{
    if ($flag !== '') {
        $i = array_search($flag, $args, true);
        if ($i !== false) {
            return $args[$i + 1] ?? null;
        }

        return null;
    }

    return $args[$idx] ?? null;
}

function table(array $rows, array $cols): void
{
    if (! $rows) {
        echo "(no results)\n";

        return;
    }

    $widths = array_map('strlen', $cols);
    foreach ($rows as $row) {
        foreach ($cols as $i => $col) {
            $widths[$i] = max($widths[$i], strlen((string) ($row[$col] ?? '')));
        }
    }

    $header = implode('  ', array_map(fn ($c, $w) => str_pad(strtoupper($c), $w), $cols, $widths));
    echo $header."\n".str_repeat('-', strlen($header))."\n";

    foreach ($rows as $row) {
        echo implode('  ', array_map(fn ($c, $w) => str_pad((string) ($row[$c] ?? ''), $w), $cols, $widths))."\n";
    }
}

/**
 * Called when a query returned no rows. Checks if the index is stale (default
 * >30 min old, override via QUICKDEX_STALE_SECONDS):
 * - Stale: rebuilds incrementally, re-runs $runQuery on a fresh engine, returns results.
 * - Current: prints a hint and returns null — caller must skip any further output.
 *
 * The rebuild is guarded by a file lock so parallel invocations hitting the same
 * stale DB at once (e.g. an agent firing several quickdex calls in one batch)
 * don't each pay for a duplicate full directory walk — the second call blocks,
 * then sees the DB freshly rebuilt by the first and skips straight to the query.
 */
function autoRefreshQuery(string $dbPath, callable $runQuery): ?array
{
    if (! file_exists($dbPath)) {
        return null;
    }

    $staleSeconds = (int) (getenv('QUICKDEX_STALE_SECONDS') ?: 1800);

    if ((time() - (int) filemtime($dbPath)) > $staleSeconds) {
        $lock = fopen($dbPath.'.lock', 'c');

        if ($lock !== false && flock($lock, LOCK_EX)) {
            clearstatcache(true, $dbPath);

            if ((time() - (int) filemtime($dbPath)) > $staleSeconds) {
                $root = '';
                try {
                    $raw  = new \PDO('sqlite:'.$dbPath);
                    $stmt = $raw->query("SELECT value FROM meta WHERE key = 'root'");
                    $root = $stmt ? (string) $stmt->fetchColumn() : '';
                } catch (\Throwable) {
                }

                if ($root !== '' && is_dir($root)) {
                    require_once __DIR__.'/../src/SourceIndexer.php';
                    (new \QuickDex\SourceIndexer($root, $dbPath))->build();
                }
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }

        clearstatcache(true, $dbPath);
        if ((time() - (int) filemtime($dbPath)) <= $staleSeconds) {
            echo "(index was stale — refreshed)\n";

            return $runQuery(new \QuickDex\QueryEngine($dbPath));
        }
    }

    echo "(no results — index is current; try quickdex grep for content search)\n";

    return null;
}

/** Strip namespace/path prefix so "App\Models\User" and "@/pages/Foo" both resolve to the bare name. */
function bareSymbol(string $name): string
{
    return (string) (preg_replace('/.*[\\\\\/]/', '', $name) ?: $name);
}

/** Tag framework-generated defs so they read as e.g. "const·gen" in def/search output. */
function markGenerated(array $rows): array
{
    foreach ($rows as &$r) {
        if (! empty($r['generated'])) {
            $r['kind'] = ($r['kind'] ?? '').'·gen';
        }
    }
    unset($r);

    return $rows;
}

if ($command === 'help' || $command === '--help' || $command === '-h') {
    echo <<<'HELP'
    QuickDex — fast symbol index for PHP/Vue/TS/JS/Go/Python codebases

    Commands:
      def      <name>                  Where is a symbol defined
      body     <name> [--limit N]      Print the actual source of every matching def
                                        (uses the indexed line range — no separate Read
                                        needed for the common case). Capped at 10 matches
                                        and 400 lines per match by default.
      refs     <symbol>                Files+lines that reference a symbol or import path
      files    [--type php|vue|ts|js|go|py]  List files (optional filters)
               [--ns Namespace\Prefix]
               [--path path/prefix]
      hier     <class>                 Class hierarchy (extends/implements/traits)
      children <class>                 Subclasses that extend a class
      syms     <path>                  All symbols defined in a file
      deps     <path>                  All imports/refs made by a file
      search   <pattern>               Fuzzy search symbol names  (alias: find)
      uses     <trait>                 Classes that use a given trait
      patch    <class|path> <p1> ...   Check if file contains each pattern (✅/❌ + line)
      route    <search>                Search route files for name/controller/URI/middleware
      grep     <p1> [p2 ...] [--dir a,b] [--ext php,vue,...]  Scan files for a literal string
               [--limit N] [-l|--files-only] [--all]           (multi-pattern = OR; -l lists files;
                                                                --all includes lockfiles/*.min.*)
      index    [root] [output.db] [--force]  Build or rebuild the index
                                        (aliases: build, reindex, rebuild)
      meta                             Show index metadata

    Notes:
      def/body/search strip namespace prefixes — "App\Models\User" is treated as "User"
      refs falls back to suffix matching — "pages/Calendar" matches "@/pages/Calendar" imports
      files --path filters by path prefix (complements --ns which is PHP-namespace-only)
      On empty results quickdex checks staleness (>30 min, or $QUICKDEX_STALE_SECONDS)
        and auto-refreshes the index if so
      def/syms/search index class methods (kind=method, ns=owning class)
        and JS/TS/Vue arrow-function consts
      Blade files: @include/@extends/<x-component> become refs; @section() becomes a def
      Migration Schema::create/table/dropIfExists/drop are indexed by table name —
        use `def <table_name>` to find every migration touching a table
      Indexing is incremental by default (skips unchanged files by mtime);
        pass --force for a full rebuild

    Environment:
      QUICKDEX_DB             Path to database (default: ./quickdex.db)
      QUICKDEX_STALE_SECONDS  Auto-refresh threshold on empty results (default: 1800)

    Examples:
      quickdex def HourEntry
      quickdex def "App\Models\HourEntry"      # namespace prefix stripped automatically
      quickdex body HourEntry
      quickdex body store --limit 3
      quickdex refs User
      quickdex refs "pages/Calendar"            # suffix match: finds "@/pages/Calendar" imports
      quickdex files --type vue
      quickdex files --path "app/Http/Controllers"
      quickdex files --ns "App\Models"
      quickdex hier UserController
      quickdex children Controller
      quickdex syms app/Models/User.php
      quickdex search "Hour"
      quickdex uses BelongsToTenant
      quickdex patch BillingController "loadCount" "translatedFormat"
      quickdex route "expenses.approve"
      quickdex grep "SEPA" --dir app --ext php
      quickdex index                            # incremental rebuild
      quickdex index . quickdex.db --force      # force full rebuild
    HELP;
    exit(0);
}

// --- Reindex aliases: handle before QueryEngine so they work even when no DB exists yet ---
if (in_array($command, ['index', 'build', 'reindex', 'rebuild'], true)) {
    require_once __DIR__.'/../src/SourceIndexer.php';
    $indexRoot = arg($args, 0) ?? '';
    $rawDbArg  = arg($args, 1) ?? '';
    $force     = in_array('--force', $args, true);
    $indexDb   = ($rawDbArg !== '' && ! str_starts_with($rawDbArg, '--'))
        ? $rawDbArg
        : ($dbPath ?? getcwd().DIRECTORY_SEPARATOR.'quickdex.db');

    if ($indexRoot === '' || ! is_dir($indexRoot)) {
        $indexRoot = '';
        if (file_exists($indexDb)) {
            try {
                $raw  = new \PDO('sqlite:'.$indexDb);
                $stmt = $raw->query("SELECT value FROM meta WHERE key = 'root'");
                $indexRoot = (string) ($stmt ? $stmt->fetchColumn() : '');
            } catch (\Throwable) {
            }
        }
        if ($indexRoot === '' || ! is_dir($indexRoot)) {
            $indexRoot = (string) getcwd();
        }
    }

    $start = microtime(true);
    (new \QuickDex\SourceIndexer($indexRoot, $indexDb))->build($force);
    printf("QuickDex built: %s (%.2fs)\n", $indexDb, microtime(true) - $start);
    exit(0);
}

try {
    if ($dbPath === null) {
        echo 'No quickdex.db found from '.getcwd()." upward — cd into the project or run: quickdex index\n";
        exit(1);
    }

    ensureSchemaCurrent($dbPath);

    $qe = new QueryEngine($dbPath);

    // Wrong-project tripwire: the DB's indexed root should contain the CWD. If it
    // doesn't, we're querying some other project's index — surface it instead of
    // returning confidently wrong answers. See P1.2.
    $indexedRoot = $qe->projectRoot();
    $nr = rtrim(str_replace('\\', '/', $indexedRoot), '/');
    $nc = rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    if ($nr !== '' && $nc !== '' && ! str_starts_with($nc.'/', $nr.'/')) {
        echo "db: {$dbPath} (root: {$indexedRoot})\n";
    }

    switch ($command) {
        case 'def':
            $name = bareSymbol(arg($args, 0) ?? '');
            if ($name === '') {
                echo "Usage: quickdex def <name>\n";
                exit(1);
            }
            $rows = $qe->def($name);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->def($name));
                if ($rows === null) break;
            }
            table(markGenerated($rows), ['kind', 'name', 'file', 'line', 'end_line', 'ns']);
            break;

        case 'refs':
            $symbol = arg($args, 0) ?? '';
            if ($symbol === '') {
                echo "Usage: quickdex refs <symbol>\n";
                exit(1);
            }
            $rows = $qe->refs($symbol);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->refs($symbol));
                if ($rows === null) break;
            }
            if (! $rows) {
                echo "(no references)\n";
            } else {
                foreach ($rows as $r) {
                    echo "[{$r['file']}:{$r['line']}]\n";
                }
            }
            break;

        case 'body':
            $name = bareSymbol(arg($args, 0) ?? '');
            $lim  = max(1, (int) (arg($args, 0, '--limit') ?? 10));
            if ($name === '') {
                echo "Usage: quickdex body <symbol> [--limit N]\n";
                exit(1);
            }
            $result = $qe->body($name, $lim);
            if (! $result['matches']) {
                $newMatches = autoRefreshQuery($dbPath, fn ($q) => $q->body($name, $lim)['matches']);
                if ($newMatches === null) {
                    exit(1);
                }
                $result['matches'] = $newMatches;
            }
            if (! $result['matches']) {
                echo "(not found)\n";
                exit(1);
            }
            foreach ($result['matches'] as $m) {
                $nsPart = $m['ns'] ? "{$m['ns']}::" : '';
                echo "── [{$m['kind']}] {$nsPart}{$m['name']} — {$m['file']}:{$m['line']}-{$m['end_line']} ──\n";
                echo $m['source']."\n";
                if ($m['truncated']) {
                    echo "... (truncated — use Read for the full range)\n";
                }
                echo "\n";
            }
            if ($result['truncated_matches']) {
                printf("Showing %d of %d matches — narrow with --limit or a more specific name.\n", count($result['matches']), $result['total_matches']);
            }
            break;

        case 'files':
            $type = arg($args, 0, '--type') ?? '';
            $ns   = arg($args, 0, '--ns') ?? '';
            $path = arg($args, 0, '--path') ?? '';
            table($qe->files($type, $ns, $path), ['type', 'lines', 'path', 'ns']);
            break;

        case 'hier':
            $class = arg($args, 0) ?? '';
            if ($class === '') {
                echo "Usage: quickdex hier <class>\n";
                exit(1);
            }
            $h = $qe->hier($class);
            if (! $h) {
                $nh = autoRefreshQuery($dbPath, fn ($q) => $q->hier($class) ?? []);
                if ($nh === null) {
                    exit(1);
                }
                $h = $nh ?: null;
            }
            if (! $h) {
                echo "(not found)\n";
                exit(1);
            }
            echo 'extends:    '.($h['extends'] ?? 'none')."\n";
            echo 'implements: '.(implode(', ', $h['implements']) ?: 'none')."\n";
            echo 'traits:     '.(implode(', ', $h['traits']) ?: 'none')."\n";
            break;

        case 'children':
            $class = arg($args, 0) ?? '';
            if ($class === '') {
                echo "Usage: quickdex children <class>\n";
                exit(1);
            }
            $rows = $qe->children($class);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->children($class));
                if ($rows === null) break;
            }
            table($rows, ['class', 'file', 'line']);
            break;

        case 'syms':
            $path = arg($args, 0) ?? '';
            if ($path === '') {
                echo "Usage: quickdex syms <path>\n";
                exit(1);
            }
            $rows = $qe->fileSymbols($path);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->fileSymbols($path));
                if ($rows === null) break;
            }
            table($rows, ['kind', 'name', 'line', 'end_line', 'ns']);
            break;

        case 'deps':
            $path = arg($args, 0) ?? '';
            if ($path === '') {
                echo "Usage: quickdex deps <path>\n";
                exit(1);
            }
            $refs = $qe->fileRefs($path);
            if (! $refs) {
                echo "(none)\n";
            } else {
                foreach ($refs as $r) {
                    echo $r."\n";
                }
            }
            break;

        case 'find':
        case 'search':
            $raw = arg($args, 0) ?? '';
            if ($raw === '') {
                echo "Usage: quickdex search <pattern>\n";
                exit(1);
            }
            if ($command === 'find') {
                echo "(note: 'find' is an alias for 'search')\n";
            }
            $pattern = bareSymbol($raw);
            $rows    = $qe->search($pattern);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->search($pattern));
                if ($rows === null) break;
            }
            table(markGenerated($rows), ['kind', 'name', 'file', 'line', 'end_line']);
            if (count($rows) >= 50) {
                echo "(showing 50 — refine pattern for fewer results)\n";
            }
            break;

        case 'uses':
            $trait = arg($args, 0) ?? '';
            if ($trait === '') {
                echo "Usage: quickdex uses <trait>\n";
                exit(1);
            }
            $rows = $qe->uses($trait);
            if (! $rows) {
                $rows = autoRefreshQuery($dbPath, fn ($q) => $q->uses($trait));
                if ($rows === null) break;
            }
            table($rows, ['class', 'file', 'line']);
            break;

        case 'patch':
            $target   = arg($args, 0) ?? '';
            $patterns = array_slice($args, 1);
            if ($target === '' || $patterns === []) {
                echo "Usage: quickdex patch <class|path> <pattern1> [pattern2 ...]\n";
                exit(1);
            }
            table($qe->patch($target, $patterns), ['file', 'found', 'line', 'pattern']);
            break;

        case 'route':
            $search = arg($args, 0) ?? '';
            if ($search === '') {
                echo "Usage: quickdex route <search>\n";
                exit(1);
            }
            $rows = $qe->route($search);
            if (! $rows) {
                echo "(no matches)\n";
            } else {
                foreach ($rows as $r) {
                    echo "[{$r['file']}:{$r['line']}] {$r['content']}\n";
                }
            }
            break;

        case 'grep':
            $patterns = [];
            foreach ($args as $a) {
                if (str_starts_with($a, '-')) {
                    break; // first flag (-l / --dir / --ext / --limit / --all) ends the pattern list
                }
                $patterns[] = $a;
            }
            $dirArg    = arg($args, 0, '--dir') ?? '';
            $extArg    = arg($args, 0, '--ext') ?? '';
            $limit     = max(1, (int) (arg($args, 0, '--limit') ?? 200));
            $filesOnly = in_array('--files-only', $args, true) || in_array('-l', $args, true);
            $allFiles  = in_array('--all', $args, true);
            $exts      = $extArg !== '' ? array_map('trim', explode(',', $extArg)) : ['php', 'vue', 'js', 'ts', 'blade', 'yml', 'yaml', 'go', 'py'];
            if ($patterns === []) {
                echo "Usage: quickdex grep <pattern1> [pattern2 ...] [--dir a,b] [--ext php,vue,blade,...] [--limit N] [-l|--files-only] [--all]\n";
                echo "Multiple patterns match with OR semantics — use separate args, not '|' (crashes the Windows .bat wrapper; also grep never supported regex).\n";
                exit(1);
            }

            // Validate --dir (comma-separated allowed) so an invalid path errors
            // instead of masquerading as "(no matches)". See P1.1.
            $dirs = [];
            if ($dirArg !== '') {
                $root = $qe->projectRoot();
                foreach (array_filter(array_map('trim', explode(',', $dirArg))) as $d) {
                    $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $d);
                    if (! is_dir($abs)) {
                        echo "Error: --dir '{$d}' is not a directory under {$root}\n";
                        exit(1);
                    }
                    $dirs[] = $d;
                }
            }

            $rows = $qe->grep($patterns, $dirs, $exts, $limit, $filesOnly, $allFiles);
            if (! $rows) {
                echo "(no matches)\n";
            } else {
                foreach ($rows as $r) {
                    echo $filesOnly ? "{$r['file']}\n" : "[{$r['file']}:{$r['line']}] {$r['content']}\n";
                }
                if (count($rows) >= $limit) {
                    echo "(truncated at {$limit} — refine or raise --limit)\n";
                }
            }
            break;

        case 'meta':
            foreach ($qe->meta() as $k => $v) {
                echo "{$k}: {$v}\n";
            }
            break;

        default:
            echo "Unknown command: {$command}\nRun: quickdex help\n";
            exit(1);
    }
} catch (\Exception $e) {
    fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
    exit(1);
}
