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

// ── Pass 6: Go and Python indexing (isolated fixture) ───────────────────────

$fixtureRoot3 = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quickdex_test3_'.uniqid();
$dbPath3 = $fixtureRoot3.DIRECTORY_SEPARATOR.'quickdex.db';
register_shutdown_function(function () use (&$fixtureRoot3) {
    rrmdir($fixtureRoot3);
});
mkdir($fixtureRoot3, 0777, true);

$goFixture = <<<'GO'
package widget

import (
	"fmt"
	"strings"
)

type GoWidget struct {
	Name string
}

func (w *GoWidget) Describe() string {
	return fmt.Sprintf("Widget: %s", w.Name)
}

func NewGoWidget(name string) *GoWidget {
	return &GoWidget{Name: strings.TrimSpace(name)}
}

type Describer interface {
	Describe() string
}
GO;

$pyFixture = <<<'PY'
import os
from collections import OrderedDict


class PyWidget:
    def __init__(self, name):
        self.name = name

    def render(self):
        return f"Widget: {self.name}"


def build_py_widget(name):
    return PyWidget(name)
PY;

$pyHierFixture = <<<'PY'
import abc
from typing import Generic, TypeVar

T = TypeVar("T")


class Base(abc.ABC):
    pass


class LoggingMixin(object):
    pass


class Repo(Generic[T], Base, metaclass=abc.ABCMeta):
    pass


class UserRepo(
    LoggingMixin,  # mixin first
    Repo[int],
):
    pass


class Plain:
    pass
PY;

writeFixture($fixtureRoot3, 'widget.go', $goFixture);
writeFixture($fixtureRoot3, 'widget.py', $pyFixture);
writeFixture($fixtureRoot3, 'repos.py', $pyHierFixture);

$indexer6 = new SourceIndexer($fixtureRoot3, $dbPath3);
$indexer6->build();
$qe6 = new QueryEngine($dbPath3);

// Go: struct, receiver method (ns = receiver type), plain function, interface, imports.
$goStructDefs = $qe6->def('GoWidget');
check('def finds the GoWidget struct', count($goStructDefs) === 1 && $goStructDefs[0]['kind'] === 'struct',
    'got '.json_encode($goStructDefs));

$goMethodDefs = $qe6->def('Describe');
check('def finds the Describe() method with its receiver type as ns',
    count($goMethodDefs) === 1 && $goMethodDefs[0]['kind'] === 'method' && $goMethodDefs[0]['ns'] === 'GoWidget',
    'got '.json_encode($goMethodDefs));

$goFuncDefs = $qe6->def('NewGoWidget');
check('def finds the top-level NewGoWidget function', count($goFuncDefs) === 1 && $goFuncDefs[0]['kind'] === 'function',
    'got '.json_encode($goFuncDefs));

$goInterfaceDefs = $qe6->def('Describer');
check('def finds the Describer interface', count($goInterfaceDefs) === 1 && $goInterfaceDefs[0]['kind'] === 'interface',
    'got '.json_encode($goInterfaceDefs));

$goDescribeBody = $qe6->body('Describe');
if ($goDescribeBody['matches']) {
    $src = $goDescribeBody['matches'][0]['source'];
    check('body(Describe) captures the method body up to its closing brace',
        str_contains($src, 'fmt.Sprintf') && str_ends_with(rtrim($src), '}'), 'source: '.$src);
}

$fmtRefs = $qe6->refs('fmt');
check('refs finds the grouped-import "fmt" package used in widget.go', count($fmtRefs) > 0, 'got '.json_encode($fmtRefs));

// Python: class, method (ns = owning class), top-level function, imports.
$pyClassDefs = $qe6->def('PyWidget');
check('def finds the PyWidget class', count($pyClassDefs) === 1 && $pyClassDefs[0]['kind'] === 'class',
    'got '.json_encode($pyClassDefs));

$pyMethodDefs = $qe6->def('render');
check('def finds the render() method with its owning class as ns',
    count($pyMethodDefs) === 1 && $pyMethodDefs[0]['kind'] === 'method' && $pyMethodDefs[0]['ns'] === 'PyWidget',
    'got '.json_encode($pyMethodDefs));

$pyFuncDefs = $qe6->def('build_py_widget');
check('def finds the top-level build_py_widget function', count($pyFuncDefs) === 1 && $pyFuncDefs[0]['kind'] === 'function',
    'got '.json_encode($pyFuncDefs));

$pyRenderBody = $qe6->body('render');
if ($pyRenderBody['matches']) {
    $src = $pyRenderBody['matches'][0]['source'];
    checkEquals('body(render) captures exactly the method (indentation-delimited, not the whole class)',
        "    def render(self):\n        return f\"Widget: {self.name}\"",
        $src
    );
}

$orderedDictRefs = $qe6->refs('OrderedDict');
check('refs finds the "from collections import OrderedDict" name', count($orderedDictRefs) > 0, 'got '.json_encode($orderedDictRefs));

$collectionsRefs = $qe6->refs('collections');
check('refs finds the "from collections import ..." module', count($collectionsRefs) > 0, 'got '.json_encode($collectionsRefs));

// Python class bases → hier/children. First base = extends, the rest = further bases.
$baseHier = $qe6->hier('Base');
check('hier: dotted base reduced to its last segment (abc.ABC → ABC)',
    ($baseHier['extends'] ?? null) === 'ABC' && $baseHier['implements'] === [], 'got '.json_encode($baseHier));

$mixinHier = $qe6->hier('LoggingMixin');
check('hier: explicit object base is dropped', $mixinHier !== null && $mixinHier['extends'] === null && $mixinHier['implements'] === [],'got '.json_encode($mixinHier));

$repoHier = $qe6->hier('Repo');
check('hier: generic reduced to its name, metaclass= keyword skipped',
    ($repoHier['extends'] ?? null) === 'Generic' && $repoHier['implements'] === ['Base'], 'got '.json_encode($repoHier));

$userRepoHier = $qe6->hier('UserRepo');
check('hier: multi-line header with a comment and a subscripted base',
    ($userRepoHier['extends'] ?? null) === 'LoggingMixin' && $userRepoHier['implements'] === ['Repo'], 'got '.json_encode($userRepoHier));

$plainHier = $qe6->hier('Plain');
check('hier: class without bases has no parents', $plainHier !== null && $plainHier['extends'] === null && $plainHier['implements'] === [],
    'got '.json_encode($plainHier));

$baseChildren = array_column($qe6->children('Base'), 'class');
check('children finds a subclass that lists the class as a later base', $baseChildren === ['Repo'], 'got '.json_encode($baseChildren));

$repoChildren = array_column($qe6->children('Repo'), 'class');
check('children finds a subclass that uses a subscripted base (Repo[int])', $repoChildren === ['UserRepo'], 'got '.json_encode($repoChildren));

$baseRefs = array_column($qe6->refs('Base'), 'file');
check('refs finds a class used as a Python base', in_array('repos.py', $baseRefs, true), 'got '.json_encode($baseRefs));

unset($qe6, $indexer6);

// ── Laravel layer (schema 3.0): routes, Inertia pages, Vue template/props/emits, Ziggy ──
//
// A fixture `artisan` script stands in for the framework: `php artisan route:list --json`
// prints a canned route list, exactly the shape Laravel emits, so the route table
// path is exercised end to end without booting an app.

$laravelRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quickdex_laravel_'.uniqid();
$laravelDb = $laravelRoot.DIRECTORY_SEPARATOR.'quickdex.db';
register_shutdown_function(function () use (&$laravelRoot) {
    rrmdir($laravelRoot);
});
mkdir($laravelRoot, 0777, true);

writeFixture($laravelRoot, 'artisan', <<<'PHP'
<?php
if (($argv[1] ?? '') === 'route:list') {
    echo json_encode([
        ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'sites', 'name' => 'sites.index', 'action' => 'App\Http\Controllers\SiteController@index', 'middleware' => ['web', 'Illuminate\Auth\Middleware\Authenticate'], 'path' => null],
        ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'features', 'name' => 'features.show', 'action' => 'App\Http\Controllers\FeaturesController', 'middleware' => ['web'], 'path' => null],
        ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'about', 'name' => 'about.show', 'action' => 'Closure', 'middleware' => ['web'], 'path' => 'routes/web.php:3'],
        ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'admin/users', 'name' => 'admin.users.index', 'action' => 'App\Http\Controllers\Admin\UserController@index', 'middleware' => ['web', 'Illuminate\Auth\Middleware\Authenticate:admin'], 'path' => null],
    ]);
    exit(0);
}
exit(1);
PHP);

writeFixture($laravelRoot, 'config/ziggy.php', <<<'PHP'
<?php
return ['groups' => ['guest' => ['features.show', 'about.show', 'register'], 'authenticated' => ['sites.*'], 'admin' => ['admin.*']]];
PHP);

writeFixture($laravelRoot, 'routes/web.php', <<<'PHP'
<?php
use Inertia\Inertia;
Route::get('/about', function () {
    return Inertia::render('About');
})->name('about.show');
PHP);

writeFixture($laravelRoot, 'app/Http/Controllers/SiteController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;

use Inertia\Inertia;

class SiteController extends Controller
{
    public function index()
    {
        return Inertia::render('Sites/Index', ['sites' => []]);
    }

    public function show()
    {
        return redirect()->route('sites.index');
    }
}
PHP);

writeFixture($laravelRoot, 'app/Http/Controllers/FeaturesController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;

use Inertia\Inertia;

class FeaturesController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Features');
    }
}
PHP);

writeFixture($laravelRoot, 'app/Http/Controllers/Admin/UserController.php', <<<'PHP'
<?php
namespace App\Http\Controllers\Admin;

use Inertia\Inertia;

class UserController
{
    public function index()
    {
        return Inertia::render('Admin/Users/Index');
    }
}
PHP);

writeFixture($laravelRoot, 'resources/js/Pages/Features.vue', <<<'VUE'
<script setup lang="ts">
import { computed } from 'vue'
import StatTile from '@/Components/StatTile.vue'
import FrontLayout from '@/Layouts/FrontLayout.vue'

const isAuthed = computed(() => !!usePage().props.auth.user)
</script>

<template>
    <FrontLayout>
        <StatTile label="Sites" :href="isAuthed ? route('sites.index') : route('register')" />
        <a :href="route('features.show')">Features</a>
        <a :href="route('admin.users.index')">Admin</a>
        <app-button>Go</app-button>
    </FrontLayout>
</template>
VUE);

writeFixture($laravelRoot, 'resources/js/Pages/Sites/Index.vue', <<<'VUE'
<script setup>
const props = defineProps({ sites: Array, total: { type: Number, default: 0 } })
</script>
<template><div>{{ props.total }}</div></template>
VUE);

writeFixture($laravelRoot, 'resources/js/Components/StatTile.vue', <<<'VUE'
<script setup lang="ts">
interface Props {
    label: string
    href?: string
    tone?: 'neutral' | 'warn'
    meta?: { a: string; b: number }
}
withDefaults(defineProps<Props>(), { tone: 'neutral' })
const emit = defineEmits<{
    (e: 'select', id: number): void
    (e: 'close'): void
}>()
</script>
<template><button @click="emit('select', 1)">{{ label }}</button></template>
VUE);

writeFixture($laravelRoot, 'tests/Unit/SiteControllerTest.php', <<<'PHP'
<?php
namespace Tests\Unit;

use App\Http\Controllers\SiteController;

class SiteControllerTest extends TestCase {}
PHP);

writeFixture($laravelRoot, 'tests/Feature/SitesPageTest.php', <<<'PHP'
<?php
namespace Tests\Feature;

class SitesPageTest extends TestCase
{
    public function test_it_lists(): void
    {
        $this->get(route('sites.index'))->assertOk();
    }
}
PHP);

$indexer6 = new SourceIndexer($laravelRoot, $laravelDb);
$indexer6->build(true);
$qe6 = new QueryEngine($laravelDb);

check('indexer left no notes for a bootable fixture app', $indexer6->notes === [], implode(' | ', $indexer6->notes));
check('route table filled from artisan route:list', $qe6->hasRoutes() && count($qe6->routes()) === 4, 'got '.count($qe6->routes()));

$sitesRoute = $qe6->routes('sites.index')[0] ?? null;
check('route resolves controller@action to file:line via defs',
    $sitesRoute !== null && $sitesRoute['file'] === 'app/Http/Controllers/SiteController.php' && $sitesRoute['line'] === 8,
    json_encode($sitesRoute));
checkEquals('route knows the Inertia page its action renders', 'Sites/Index', $sitesRoute['page'] ?? null);
checkEquals('route carries its Ziggy group (wildcard pattern sites.*)', 'authenticated', $sitesRoute['ziggy'] ?? null);

$featuresRoute = $qe6->routes('features.show')[0] ?? null;
check('invokable controller maps to __invoke', ($featuresRoute['action_name'] ?? null) === '__invoke' && ($featuresRoute['page'] ?? null) === 'Features', json_encode($featuresRoute));

$aboutRoute = $qe6->routes('about.show')[0] ?? null;
check('closure route resolves to routes/web.php:line and its render call', ($aboutRoute['file'] ?? null) === 'routes/web.php' && ($aboutRoute['line'] ?? null) === 3 && ($aboutRoute['page'] ?? null) === 'About', json_encode($aboutRoute));

check('route() string literals are refs (PHP and Vue callers)',
    count(array_filter($qe6->refs('sites.index'), static fn (array $r): bool => str_ends_with($r['file'], 'SiteController.php'))) === 1
    && count(array_filter($qe6->refs('sites.index'), static fn (array $r): bool => str_ends_with($r['file'], 'Features.vue'))) === 1
    && count(array_filter($qe6->refs('sites.index'), static fn (array $r): bool => str_ends_with($r['file'], 'SitesPageTest.php'))) === 1,
    json_encode($qe6->refs('sites.index')));

$featuresPage = $qe6->page('Features');
checkEquals('page finds its Vue file', 'resources/js/Pages/Features.vue', $featuresPage['vue_file']);
checkEquals('page audience from a web-only route is guest', ['guest'], $featuresPage['audience']);
check('page lists the controller that renders it', count($featuresPage['renders']) === 1 && $featuresPage['renders'][0]['owner'] === 'FeaturesController@__invoke', json_encode($featuresPage['renders']));
check('page lists template components incl. kebab-case tags as PascalCase',
    in_array('StatTile', $featuresPage['components'], true) && in_array('AppButton', $featuresPage['components'], true) && in_array('FrontLayout', $featuresPage['components'], true),
    json_encode($featuresPage['components']));
$adminCall = array_values(array_filter($featuresPage['route_calls'], static fn (array $c): bool => $c['name'] === 'admin.users.index'))[0] ?? null;
check('page flags an admin route called from a guest page', $adminCall !== null && $adminCall['ziggy'] === 'admin' && $adminCall['guard'] === 'none', json_encode($adminCall));
$sitesCall = array_values(array_filter($featuresPage['route_calls'], static fn (array $c): bool => $c['name'] === 'sites.index'))[0] ?? null;
check('page recognises an isAuthed ternary as a guard', $sitesCall !== null && $sitesCall['guard'] === 'guarded', json_encode($sitesCall));

check('page accepts a Vue path too', $qe6->page('resources/js/Pages/Sites/Index.vue')['routes'][0]['name'] === 'sites.index');

$z = $qe6->ziggyCheck();
check('ziggy-check reports exactly the unguarded cross-audience call',
    count($z['violations']) === 1 && $z['violations'][0]['route'] === 'admin.users.index' && $z['guarded'] === 1,
    json_encode($z));

$tileSyms = $qe6->fileSymbols('resources/js/Components/StatTile.vue');
$props = array_column(array_filter($tileSyms, static fn (array $s): bool => $s['kind'] === 'prop'), 'name');
$emits = array_column(array_filter($tileSyms, static fn (array $s): bool => $s['kind'] === 'emit'), 'name');
checkEquals('defineProps<Props>() via interface yields top-level keys only (nested object keys excluded)', ['label', 'href', 'tone', 'meta'], array_values($props));
checkEquals('defineEmits call signatures yield event names', ['select', 'close'], array_values($emits));
$indexSyms = $qe6->fileSymbols('resources/js/Pages/Sites/Index.vue');
checkEquals('defineProps({...}) object form yields keys', ['sites', 'total'], array_values(array_column(array_filter($indexSyms, static fn (array $s): bool => $s['kind'] === 'prop'), 'name')));
$firstProp = array_values(array_filter($tileSyms, static fn (array $s): bool => $s['kind'] === 'prop'))[0] ?? null;
check('prop defs are owned by the component name', ($firstProp['ns'] ?? null) === 'StatTile', json_encode($firstProp));

$tests = $qe6->tests('SiteController');
check('tests: <Class>Test first, then referencing tests',
    count($tests) === 1 && $tests[0]['file'] === 'tests/Unit/SiteControllerTest.php' && str_starts_with($tests[0]['how'], 'named'),
    json_encode($tests));

$ov = $qe6->overview();
check('overview totals', $ov['totals']['routes'] === 4 && $ov['totals']['vue_pages'] === 2 && $ov['totals']['vue_components'] === 1 && $ov['totals']['tests'] === 2, json_encode($ov['totals']));

// Incremental pass with nothing changed must not re-run artisan (routes kept, no notes).
$indexer6b = new SourceIndexer($laravelRoot, $laravelDb);
$indexer6b->build(false);
check('incremental no-op keeps the route table', (new QueryEngine($laravelDb))->hasRoutes());

// A project without artisan: route table empty, no notes, everything else works.
$plainRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quickdex_plain_'.uniqid();
register_shutdown_function(function () use (&$plainRoot) {
    rrmdir($plainRoot);
});
mkdir($plainRoot, 0777, true);
writeFixture($plainRoot, 'src/Thing.php', "<?php\nclass Thing {}\n");
$indexer7 = new SourceIndexer($plainRoot, $plainRoot.DIRECTORY_SEPARATOR.'quickdex.db');
$indexer7->build(true);
$qe7 = new QueryEngine($plainRoot.DIRECTORY_SEPARATOR.'quickdex.db');
check('non-Laravel project: no route table, no notes', ! $qe7->hasRoutes() && $indexer7->notes === [], implode(' | ', $indexer7->notes));

unset($qe6, $indexer6, $indexer6b, $qe7, $indexer7);

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
