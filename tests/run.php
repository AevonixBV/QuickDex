#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * QuickDex test runner — no PHPUnit/Composer dependency, matching this repo's
 * philosophy of zero dependencies. Builds a throwaway fixture project, indexes
 * it with the real SourceIndexer, queries it with the real QueryEngine, and
 * asserts on both. Exits 0 on success, 1 if any assertion failed.
 *
 * Run: php tests/run.php
 */

require_once __DIR__.'/../src/SourceIndexer.php';
require_once __DIR__.'/../src/QueryEngine.php';

use QuickDex\QueryEngine;
use QuickDex\SourceIndexer;

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, string $detail = ''): void
{
    global $failures, $passCount;

    if ($condition) {
        $passCount++;

        return;
    }

    $failures[] = $detail !== '' ? "{$description}\n    {$detail}" : $description;
}

function checkEquals(string $description, mixed $expected, mixed $actual): void
{
    check(
        $description,
        $expected === $actual,
        'expected '.var_export($expected, true).', got '.var_export($actual, true)
    );
}

// ── Fixture project setup ───────────────────────────────────────────────────

$fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quickdex_test_'.uniqid();
$dbPath = $fixtureRoot.DIRECTORY_SEPARATOR.'quickdex.db';

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$item;
        // Suppressed: a just-closed PDO/SQLite handle can leave a brief OS-level
        // lock on Windows even after unset(). This is a temp dir either way —
        // best-effort cleanup, not worth failing the test run over.
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function writeFixture(string $root, string $relPath, string $content): string
{
    $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    @mkdir(dirname($abs), 0777, true);
    file_put_contents($abs, $content);

    return $abs;
}

// Register cleanup up front so a failed assertion (which doesn't throw) or a
// thrown exception still tidies up the temp directory.
register_shutdown_function(function () use (&$fixtureRoot) {
    rrmdir($fixtureRoot);
});

mkdir($fixtureRoot, 0777, true);

$phpFixture = <<<'PHP'
<?php

namespace App\Models;

use App\Support\Traits\HasUlid;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use HasUlid;

    const MAX_NAME_LENGTH = 255;

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function owner()
    {
        return $this->belongsTo(User::class);
    }
}

function widget_helper(int $x): int
{
    return $x * 2;
}
PHP;

$migrationFixture = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
};
PHP;

$vueFixture = <<<'VUE'
<script setup>
import { ref } from 'vue'
import Widget from '@/Components/Widget.vue'

const count = ref(0)

const increment = () => {
    count.value++
}
</script>

<template>
    <Widget :count="count" @click="increment" />
</template>
VUE;

$jsFixture = <<<'JS'
import { helper } from './helper.js'

export interface WidgetOptions {
    name: string
}

export function buildWidget({ name }: WidgetOptions) {
    return { name, built: true }
}

export class WidgetFactory {
    create(name) {
        return buildWidget({ name })
    }
}

export default function WidgetPage({ name }) {
    return buildWidget({ name })
}
JS;

$bladeFixture = <<<'BLADE'
@extends('layouts.app')

@section('content')
    <x-widget-card :title="$title" />
@endsection
BLADE;

writeFixture($fixtureRoot, 'app/Models/Widget.php', $phpFixture);
writeFixture($fixtureRoot, 'database/migrations/2026_01_01_000000_create_widgets_table.php', $migrationFixture);
writeFixture($fixtureRoot, 'resources/js/Widget.vue', $vueFixture);
writeFixture($fixtureRoot, 'resources/js/widget.js', $jsFixture);
writeFixture($fixtureRoot, 'resources/views/widget.blade.php', $bladeFixture);

// ── Pass 1: full build ───────────────────────────────────────────────────────

$indexer = new SourceIndexer($fixtureRoot, $dbPath);
$indexer->build();

$qe = new QueryEngine($dbPath);
$meta = $qe->meta();

checkEquals('first build runs in full mode', 'full', $meta['mode'] ?? null);
checkEquals('first build indexes all 5 fixture files', '5', $meta['indexed_files'] ?? null);

// def() finds the class, method, function, constant, and migration table op
$classDefs = $qe->def('Widget');
check('def finds the Widget class', count($classDefs) === 1 && $classDefs[0]['kind'] === 'class',
    'got '.json_encode($classDefs));

$methodDefs = $qe->def('owner');
check('def finds the owner() method with its owning class as ns', count($methodDefs) === 1 && ($methodDefs[0]['ns'] ?? null) === 'Widget',
    'got '.json_encode($methodDefs));

$fnDefs = $qe->def('widget_helper');
check('def finds the top-level widget_helper function', count($fnDefs) === 1 && $fnDefs[0]['kind'] === 'function',
    'got '.json_encode($fnDefs));

$tableDefs = $qe->def('widgets');
check('def finds the widgets migration table op', count($tableDefs) === 1 && str_starts_with($tableDefs[0]['kind'], 'table:'),
    'got '.json_encode($tableDefs));

// body() returns the exact class source, closing brace included, nothing extra
$widgetBody = $qe->body('Widget');
check('body returns exactly one match for the Widget class', count($widgetBody['matches']) === 1,
    'got '.count($widgetBody['matches']).' matches');

if ($widgetBody['matches']) {
    $src = $widgetBody['matches'][0]['source'];
    check('body(Widget) source starts at the class line', str_starts_with(trim($src), 'class Widget extends Model'),
        "source started with: ".substr($src, 0, 60));
    check('body(Widget) source ends at the class closing brace', str_ends_with(rtrim($src), '}') && ! str_contains($src, 'function widget_helper'),
        'source: '.$src);
    check('body(Widget) source includes the owner() method', str_contains($src, 'function owner()'), 'source missing owner() method');
}

$ownerBody = $qe->body('owner');
if ($ownerBody['matches']) {
    $src = $ownerBody['matches'][0]['source'];
    checkEquals('body(owner) captures exactly the method, not the whole class',
        "public function owner()\n    {\n        return \$this->belongsTo(User::class);\n    }",
        trim($src)
    );
}

// Migration closure body should be captured too (Schema::create(...) treated like a def with a body)
$tableBody = $qe->body('widgets');
if ($tableBody['matches']) {
    $src = $tableBody['matches'][0]['source'];
    check('body(widgets) captures the migration closure content', str_contains($src, "\$table->ulid('id')"),
        'source: '.$src);
}

// refs() now carries line numbers
$modelRefs = $qe->refs('Model');
check('refs(Model) returns at least one file:line row', count($modelRefs) > 0 && isset($modelRefs[0]['line']) && $modelRefs[0]['line'] > 0,
    'got '.json_encode($modelRefs));

// Vue: arrow-function const indexed as a def, script-relative offset converted to a whole-file line number
$incrementDefs = $qe->def('increment');
check('def finds the Vue <script setup> arrow-function const "increment"', count($incrementDefs) === 1,
    'got '.json_encode($incrementDefs));
if ($incrementDefs) {
    // The fixture's <script setup> opens on line 1, so `const increment = ...` is on line 7 of the file.
    checkEquals('Vue arrow-function const line number accounts for the <script> block offset', 7, (int) $incrementDefs[0]['line']);
}

// JS/TS: interface, function-with-destructured-param end-detection, class
$optsDefs = $qe->def('WidgetOptions');
check('def finds the WidgetOptions interface', count($optsDefs) === 1 && $optsDefs[0]['kind'] === 'interface', 'got '.json_encode($optsDefs));

$buildWidgetBody = $qe->body('buildWidget');
if ($buildWidgetBody['matches']) {
    $src = $buildWidgetBody['matches'][0]['source'];
    // The critical regression this guards: a naive "first { after name" search would
    // stop at the destructured parameter's `{ name }` instead of the function body.
    check('body(buildWidget) is not truncated at the destructured parameter brace', str_contains($src, 'built: true'),
        'source: '.$src);
}

// `export default function Name()` is the standard Next.js/React page and component
// export shape — regression guard for the bug where only `export default class` (not
// `export default function`) was indexed, silently missing every such component.
$widgetPageDefs = $qe->def('WidgetPage');
check('def finds the "export default function WidgetPage" declaration', count($widgetPageDefs) === 1 && $widgetPageDefs[0]['kind'] === 'function',
    'got '.json_encode($widgetPageDefs));

$widgetPageBody = $qe->body('WidgetPage');
if ($widgetPageBody['matches']) {
    $src = $widgetPageBody['matches'][0]['source'];
    check('body(WidgetPage) captures the default-exported function body, not just its signature',
        str_contains($src, 'buildWidget({ name })'), 'source: '.$src);
}

// Blade: @section has no computed body (end_line === line), refs capture @extends/<x-component>
$sectionDefs = $qe->def('content');
check('Blade @section("content") indexed with end_line equal to line (no brace-scan into markup)',
    count($sectionDefs) === 1 && $sectionDefs[0]['line'] === $sectionDefs[0]['end_line'],
    'got '.json_encode($sectionDefs));

$xComponentRefs = $qe->refs('x-widget-card');
check('refs finds the <x-widget-card> Blade component reference', count($xComponentRefs) > 0, 'got '.json_encode($xComponentRefs));

// ── Pass 2: incremental rebuild — one file changed, one unchanged, one deleted ──

// Ensure the changed file's mtime actually advances (some filesystems have 1s mtime resolution).
sleep(1);

$widgetPath = writeFixture($fixtureRoot, 'app/Models/Widget.php', $phpFixture."\n\nfunction extra_helper() { return 1; }\n");
$deletedPath = $fixtureRoot.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'widget.blade.php';
unlink($deletedPath);

// Release the pass-1 PDO handles before reusing the same db path — in real
// usage each `quickdex index` run is a separate process, so this mimics that
// process boundary instead of relying on the source's Windows-lock fallback.
unset($qe, $indexer);

$indexer2 = new SourceIndexer($fixtureRoot, $dbPath);
$indexer2->build();

$qe2 = new QueryEngine($dbPath);
$meta2 = $qe2->meta();

checkEquals('second build runs incrementally (compatible schema already on disk)', 'incremental', $meta2['mode'] ?? null);
checkEquals('second build re-parses only the one changed file', '1', $meta2['indexed_files'] ?? null);
checkEquals('second build skips the 3 unchanged files (deleted one is neither indexed nor skipped)', '3', $meta2['skipped_unchanged'] ?? null);

$extraDefs = $qe2->def('extra_helper');
check('incremental rebuild picks up the newly-added function in the changed file', count($extraDefs) === 1, 'got '.json_encode($extraDefs));

$sectionDefsAfterDelete = $qe2->def('content');
check('deleted file\'s defs are purged from the index', count($sectionDefsAfterDelete) === 0, 'got '.json_encode($sectionDefsAfterDelete));

$stillThereDefs = $qe2->def('WidgetOptions');
check('unchanged file\'s defs survive an incremental rebuild untouched', count($stillThereDefs) === 1, 'got '.json_encode($stillThereDefs));

// ── Pass 3: --force always does a full rebuild ──────────────────────────────

unset($qe2, $indexer2);

$indexer3 = new SourceIndexer($fixtureRoot, $dbPath);
$indexer3->build(true);

$qe3 = new QueryEngine($dbPath);
$meta3 = $qe3->meta();
checkEquals('build(true) forces full mode even with a compatible existing db', 'full', $meta3['mode'] ?? null);
checkEquals('forced full rebuild only sees the 4 files that currently exist', '4', $meta3['indexed_files'] ?? null);

// ── Pass 4: a stale schema version forces an automatic full rebuild ─────────

unset($qe3, $indexer3);

$staleDb = new \PDO('sqlite:'.$dbPath);
$staleDb->exec("UPDATE meta SET value = '1.1' WHERE key = 'version'");
unset($staleDb);

$indexer4 = new SourceIndexer($fixtureRoot, $dbPath);
$indexer4->build();

$qe4 = new QueryEngine($dbPath);
$meta4 = $qe4->meta();
checkEquals('a stale schema version forces a full rebuild even without --force', 'full', $meta4['mode'] ?? null);

// ── Pass 5: P1.3/P1.4/P1.5/P3.2 feature coverage (isolated fixture) ─────────────

unset($qe4, $indexer4);

$fixtureRoot2 = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quickdex_test2_'.uniqid();
$dbPath2 = $fixtureRoot2.DIRECTORY_SEPARATOR.'quickdex.db';
register_shutdown_function(function () use (&$fixtureRoot2) {
    rrmdir($fixtureRoot2);
});
mkdir($fixtureRoot2, 0777, true);

// Two classes in the SAME namespace: Beta references Alpha with NO `use` (type hint,
// new, static call). P1.5: refs(Alpha) must find Beta despite the missing import.
writeFixture($fixtureRoot2, 'app/Services/Alpha.php', <<<'PHP'
<?php
namespace App\Services;
class Alpha
{
    public static function make(): self { return new self(); }
    public function run(): void {}
}
PHP);

writeFixture($fixtureRoot2, 'app/Services/Beta.php', <<<'PHP'
<?php
namespace App\Services;
class Beta
{
    public function __construct(private Alpha $alpha) {}
    public function go(): void
    {
        $a = new Alpha();
        Alpha::make();
        // NotAClass is mentioned only in this comment and must NOT become a ref.
    }
}
PHP);

// Cross-namespace caller that DOES import (control — must still be found).
writeFixture($fixtureRoot2, 'app/Http/Gamma.php', <<<'PHP'
<?php
namespace App\Http;
use App\Services\Alpha;
class Gamma
{
    public function __construct(private Alpha $alpha) {}
}
PHP);

// Generated (Wayfinder-style) def vs a hand-written one with the same name.
writeFixture($fixtureRoot2, 'resources/js/actions/WidgetController.ts', "export const store = (args) => ({ url: '/widgets' })\n");
writeFixture($fixtureRoot2, 'resources/js/handwritten.ts', "export const store = (x) => x\n");

// Build artifact that must be excluded entirely (P1.3).
writeFixture($fixtureRoot2, 'dist/bundle.js', "export const store = () => 'compiled'\n");

$indexer5 = new SourceIndexer($fixtureRoot2, $dbPath2);
$indexer5->build();
$qe5 = new QueryEngine($dbPath2);

// P1.5 — same-namespace reference found despite no `use`.
$alphaRefFiles = array_column($qe5->refs('Alpha'), 'file');
check('P1.5 refs(Alpha) finds same-namespace caller Beta (no use statement)',
    in_array('app/Services/Beta.php', $alphaRefFiles, true), 'got '.json_encode($alphaRefFiles));
check('P1.5 refs(Alpha) still finds the importing caller Gamma',
    in_array('app/Http/Gamma.php', $alphaRefFiles, true), 'got '.json_encode($alphaRefFiles));

// P1.5 false-positive guard — comment-only mention is not a ref.
check('P1.5 a class named only in a comment is NOT recorded as a ref',
    count($qe5->refs('NotAClass')) === 0, 'got '.json_encode($qe5->refs('NotAClass')));

// P1.4 — generated flag + ordering.
$storeDefs = $qe5->def('store');
$genByFile = [];
foreach ($storeDefs as $d) {
    $genByFile[$d['file']] = (int) $d['generated'];
}
check('P1.4 def in resources/js/actions/ is flagged generated',
    ($genByFile['resources/js/actions/WidgetController.ts'] ?? null) === 1, 'got '.json_encode($genByFile));
check('P1.4 hand-written def is not flagged generated',
    ($genByFile['resources/js/handwritten.ts'] ?? null) === 0, 'got '.json_encode($genByFile));
check('P1.4 generated defs sort after hand-written ones',
    count($storeDefs) === 2 && (int) $storeDefs[0]['generated'] === 0 && (int) $storeDefs[1]['generated'] === 1,
    'got '.json_encode(array_map(fn ($d) => [$d['file'], $d['generated']], $storeDefs)));

// P1.3 — dist/ excluded from the index.
check('P1.3 dist/ build artifacts are not indexed',
    count($qe5->fileSymbols('dist/bundle.js')) === 0, 'dist/bundle.js should be excluded');

// P3.2 — grep files-only + limit.
$grepRows = $qe5->grep(['namespace App'], [], ['php'], 200, false, false);
check('grep finds a literal across indexed php files', count($grepRows) === 3, 'got '.count($grepRows).' rows');
$grepFiles = $qe5->grep(['namespace App'], [], ['php'], 200, true, false);
check('grep --files-only returns one row per file, no line field',
    count($grepFiles) === 3 && isset($grepFiles[0]['file']) && ! isset($grepFiles[0]['line']),
    'got '.json_encode($grepFiles));
check('grep --limit caps returned rows', count($qe5->grep(['namespace App'], [], ['php'], 2, false, false)) === 2, 'limit not honoured');

// P1.3/P3.3 — grep must not descend into dist/.
check('grep excludes dist/ build artifacts', count($qe5->grep(['compiled'], [], ['js'], 200, false, false)) === 0,
    'grep found a match inside dist/');

unset($qe5, $indexer5);

// ── Report ───────────────────────────────────────────────────────────────────

echo "\n".str_repeat('─', 60)."\n";

if ($failures) {
    printf("FAILED: %d passed, %d failed\n\n", $passCount, count($failures));
    foreach ($failures as $f) {
        echo "✗ {$f}\n";
    }
    exit(1);
}

printf("PASSED: %d assertions\n", $passCount);
exit(0);
