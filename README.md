# QuickDex

Fast symbol index for PHP/Vue/TypeScript/JavaScript/Go/Python codebases. Builds a SQLite database from your source files so you can look up where any class, function, component, or type is defined — and what files use it — without reading file contents.

Designed for use with AI coding agents (Claude Code) to reduce token usage and speed up codebase navigation.

## Requirements

- PHP 8.2+ with `pdo_sqlite` extension
- No other dependencies

## Installation

QuickDex code lives in **one place**. Each project gets its own generated `quickdex.db` (gitignored). There is nothing to drop into individual projects.

### 1. Install the code once

Clone or copy the `QuickDex/` folder to a permanent location:

```sh
# example locations
~/tools/QuickDex        # macOS / Linux
C:\tools\QuickDex       # Windows
```

### 2. Put `quickdex` on your PATH

**macOS / Linux**

```sh
chmod +x ~/tools/QuickDex/bin/quickdex
ln -s ~/tools/QuickDex/bin/quickdex /usr/local/bin/quickdex
```

Or add to `~/.zshrc` / `~/.bashrc`:

```sh
export PATH="$PATH:$HOME/tools/QuickDex/bin"
```

**Windows**

Add `C:\tools\QuickDex\bin` to your user PATH (System → Advanced → Environment Variables), then restart your terminal.

### 3. Build a database for each project

Run this once from each project root:

```sh
cd /path/to/myproject
quickdex index
```

This writes `quickdex.db` into the project root. Add it to `.gitignore`:

```
/quickdex.db
```

Rebuild after significant changes:

```sh
quickdex index
```

### How it works across projects

The wrapper walks up from your current directory to find the nearest `quickdex.db` — the same way `git` finds `.git/`. Running `quickdex def Foo` from inside any project automatically hits that project's database. Six projects, six databases, one installation of the code.

## Usage

### 1. Build the index

From the project root:

```bash
quickdex index
```

Scans all `.php`, `.vue`, `.js`, `.ts` files (excluding `vendor`, `node_modules`, `.git`, `.claude`, `storage`, `tmp`, `.idea`, `.vscode`, `coverage`, `dist`, `.output`, and the compiled paths `bootstrap/cache`, `bootstrap/ssr`, `public/build`) and writes `quickdex.db` to the project root. The `bootstrap/ssr` / `public/build` / `dist` exclusions specifically matter for Vite/Inertia SSR projects — without them, compiled output gets indexed as if it were hand-written source, burying real symbols under thousands of lines of minified/bundled noise. Note these are the **specific** compiled paths, not bare `build`/`ssr` segments, so a legitimate `bootstrap/app.php` stays indexed and grep can still find it.

Custom root or output path:

```bash
quickdex index /var/www/myapp /var/www/myapp/quickdex.db   # custom root + output
```

Rebuilds are **incremental by default**: files whose mtime hasn't changed since the last build are skipped entirely, changed/new files are re-parsed, and files that were deleted get purged from the index. A full rebuild happens automatically if the on-disk schema doesn't match (e.g. right after upgrading QuickDex itself), or on demand:

```bash
quickdex index --force   # always do a full rebuild
```

**The index also self-heals during normal queries.** If `def`/`syms`/`search` (etc.) comes back stale or empty — e.g. you just created or edited a file this session — QuickDex detects it, rebuilds automatically, and retries before returning a result, printing `(index was stale — refreshed)`. You don't need to manually rebuild before trusting a "not found" result; it's already handled.

### 2. Query the index

```bash
quickdex <command> [args]
```

| Command | Description |
|---|---|
| `def <Name>` | Where is a symbol defined? Namespace prefix stripped automatically (`App\Models\User` → `User`). Framework-generated defs (Wayfinder `resources/js/{actions,routes}`) sort last and are tagged `·gen` |
| `body <Name> [--limit N]` | Print the actual source of every matching def — no separate file read needed |
| `refs <Name>` | Which files (and lines) reference this symbol? A **usage** graph, not just imports: captures `new X`, `X::`, `extends`/`implements`, and type hints — including a sibling class in the **same namespace** that needs no `use`. Falls back to path-suffix match (`pages/Calendar` finds `@/pages/Calendar`) |
| `files [--type php\|vue\|ts\|js] [--ns Namespace] [--path prefix]` | List files with optional filters |
| `hier <ClassName>` | Show extends / implements / traits for a class (Python: bases) |
| `children <ClassName>` | Find all classes that extend a class |
| `syms <path/to/file>` | List all symbols defined in a file |
| `deps <path/to/file>` | List all imports/use statements in a file |
| `search <pattern>` | Fuzzy search across all symbol names. Namespace prefix stripped. (alias: `find`) |
| `uses <Trait>` | Classes that use a given trait |
| `patch <Class\|path> <p1> ...` | Check whether a file contains each pattern (✅/❌ + line) |
| `route [search] [--all]` | **Laravel route table** (from `php artisan route:list --json` at index time): method, URI, name, `Controller@action`, the Inertia page it renders, its Ziggy group, and `file:line`. An exact name also prints middleware and every `route('name')` caller. Falls back to a text search of `routes/*.php` when the project has no route table. (alias: `routes`) |
| `page <Name\|path.vue>` | One Inertia page end to end: Vue file, controller(s)/closure that render it, routes + middleware, audience, every `route()` call inside it with its Ziggy group (❌ where Ziggy would throw), child components |
| `tests <Class>` | Tests for a class: `<Class>Test` first, then every file under `tests/` that references it |
| `ziggy-check [-v]` | Lint every Vue page: `route()` calls vs the Ziggy groups the page is served with. Exit 2 on violations. Calls guarded by `v-if` on the user / an `isAuthed` ternary are counted, not flagged |
| `overview` | Repo shape on one screen: files and lines per area, routes, pages, components, tests, migrations |
| `grep <p1> [p2 ...] [--dir a,b] [--ext php,vue,...] [--limit N] [-l\|--files-only] [--all]` | Scan files for a literal string (case-insensitive substring, not regex). Multiple patterns = OR (separate args, not `\|`/regex — crashes the Windows `.bat`). `--dir` accepts a comma-separated list and **errors** on an invalid path (not a silent `(no matches)`). `--limit` caps rows (default 200); `-l`/`--files-only` lists matching files once; lockfiles + `*.min.*` are excluded unless `--all` |
| `index [root] [db] [--force]` | Build or rebuild the index (aliases: `build`, `reindex`, `rebuild`) |
| `meta` | Show index metadata (timestamp, file count, last build mode) |

### Examples

```bash
# Where is HourEntry defined?
quickdex def HourEntry
quickdex def "App\Models\HourEntry"        # namespace prefix stripped automatically

# Print source of HourEntry — no separate file read needed
quickdex body HourEntry

# What files use HourEntry?
quickdex refs HourEntry
quickdex refs "pages/Calendar"             # suffix match: finds @/pages/Calendar imports

# All Vue components
quickdex files --type vue

# All Eloquent models
quickdex files --ns "App\Models"

# All controllers (by path, not namespace)
quickdex files --path "app/Http/Controllers"

# What does UserController extend?
quickdex hier UserController

# What controllers extend Controller?
quickdex children Controller

# All symbols in a file
quickdex syms app/Models/HourEntry.php

# What does HourEntryController depend on?
quickdex deps app/Http/Controllers/HourEntryController.php

# Find anything with "Hour" in the name
quickdex search Hour

# Literal text search, multiple patterns = OR (not regex)
quickdex grep "SEPA" "iDEAL" --dir app --ext php

# Multiple dirs (comma-separated); just list the matching files; cap rows
quickdex grep "class" --dir app/Services,app/Models --ext php --files-only
quickdex grep "TODO" --limit 50

# yml/yaml searchable by default too — e.g. Docker/CI config
quickdex grep "prometheus" --dir monitoring_config

# A single pattern that genuinely needs shell-special characters (parens,
# pipe, ampersand) needs PowerShell's stop-parsing token first, since those
# are cmd.exe metacharacters the .bat wrapper's argument passthrough hits:
quickdex grep --% "request()->all()" --dir app --ext php
```

Override the database path with an environment variable:

```bash
QUICKDEX_DB=/path/to/quickdex.db quickdex def User
```

## What gets indexed

| Symbol type | PHP | Vue | JS/TS | Blade | Go | Python |
|---|---|---|---|---|---|---|
| Classes | yes | yes | yes | — | — | yes |
| Structs / Interfaces | yes (interfaces) | yes (interfaces) | yes (interfaces) | — | yes (structs + interfaces) | — |
| Traits | yes | — | — | — | — | — |
| Methods | yes (`kind=method`, `ns=owning class`) | — | — | — | yes (`kind=method`, `ns=receiver type`) | yes (`kind=method`, `ns=owning class`) |
| Functions | top-level only | yes | top-level (incl. arrow-function consts) | — | yes | yes (incl. `async def`) |
| Constants | `const UPPER` | — | exported `const` only | — | — | — |
| Types | — | — | exported `type` | — | — | — |
| Exports | — | yes | yes | — | — | — |
| Sections | — | — | — | `@section(...)` | — | — |
| Imports / refs | `use` statements **+ inline usages** (`new X`, `X::`, `extends`/`implements`, type hints — incl. same-namespace siblings, via the native tokenizer so comments/strings are skipped) | `import` | `import` | `@include`/`@extends`/`@component`/`<x-component>` | `import` (single-line + grouped block) | `import` / `from ... import ...` |
| Class hierarchy | extends + implements + traits | — | — | — | — | bases (incl. mixins) |
| Migrations | `Schema::create/table/dropIfExists/drop` indexed as `kind=table:<op>`, `name=<table>` — `def <table>` finds every migration touching it | — | — | — |
| Props / emits | — | `defineProps` (`kind=prop`) and `defineEmits` (`kind=emit`), `ns=<component>`; TS generic, interface-backed, object and array forms | — | — |
| Template usage | — | every child component tag in `<template>` (`<StatTile>`, `<app-button>` → `AppButton`) is a ref | — | — |
| Route names | `route('x')` / `to_route('x')` literals are refs on `x` | `route('x')` in script and template | same | — |
| Inertia pages | `Inertia::render('X')` / `inertia('X')` → `pages` table with the owning `Class@method` | — | — | — |
| Routes | `php artisan route:list --json` → `routes` table (needs `./artisan`; `QUICKDEX_NO_ARTISAN=1` skips); Ziggy groups from `config/ziggy.php` | — | — | — |

JS/TS indexing intentionally skips inner-scope variables — only exported and top-level symbols are indexed to avoid noise. `.blade.php` files are indexed as `type=blade` — `refs x-email.layout` finds every view using that component.

## Database schema

```sql
files      (path, type, lines, ns, summary, mtime)              -- mtime drives incremental rebuilds
defs       (name, kind, file, line, end_line, ns, generated)    -- indexed on name; end_line backs `body`; generated=1 downranks Wayfinder output
refs       (symbol, file, line)                                 -- usage index (imports + inline class refs), with line numbers
hierarchy  (class, extends, implements, traits)
routes     (name, method, uri, action, controller, action_name, middleware, file, line, page, ziggy)  -- Laravel only
pages      (page, file, line, owner)                            -- Inertia::render sites; owner = Class@method
meta       (key, value)                                          -- schema version (currently 3.0); a bump forces a full rebuild on upgrade
```

The route table is rebuilt whenever an index pass touched or purged at least one
file (or the table is empty); a no-op incremental pass leaves it alone. Booting the
app takes a second or two; if it fails (no `.env` in a fresh worktree, say) the
indexer prints a `note:` and keeps the previous rows.

Schema version bumps are handled transparently: a query issued against an
older-schema `quickdex.db` auto-rebuilds once (`(index schema updated — rebuilt)`)
before returning, so upgrading QuickDex never surfaces a "no such column" error.
The database path is resolved by walking up from the current directory (like `git`);
there is **no** repo-relative fallback, so a query outside any indexed project fails
loudly rather than silently serving another project's index.

## Gitignore

Add to your `.gitignore` — the database is a generated artifact:

```
/quickdex.db
```

## Project structure

```
QuickDex/
  bin/
    index.php        CLI entry point — builds the SQLite index
    query.php        CLI entry point — queries the index
    ziggy-groups.php Helper: prints config/ziggy.php groups as JSON (run in a subprocess by the indexer)
    quickdex         Unix wrapper (Linux + macOS) — add to PATH
    quickdex.bat     Windows wrapper — add bin/ to PATH
  src/
    SourceIndexer.php   Walks source files and writes to SQLite
    QueryEngine.php     Reads from SQLite and returns results
  SKILL.md           Claude Code agent skill definition
  README.md          This file
```

## Claude Code integration

### 1. Install the skill

Copy `SKILL.md` to `.claude/skills/read-reducer/SKILL.md` in each project:

```bash
mkdir -p .claude/skills/read-reducer
cp /path/to/QuickDex/SKILL.md .claude/skills/read-reducer/SKILL.md
```

### 2. Add the QuickDex rule to AGENTS.md

Add the following block to your project's `AGENTS.md` (or `CLAUDE.md`):

```
Always use QuickDex before reading any file. Run `quickdex <command>` to locate symbols, find usages, and identify relevant files. Never search or read files blindly when the index can answer first.

Common lookups:
- Symbol location: `quickdex def <Name>`
- Who uses it: `quickdex refs <Name>`
- File list: `quickdex files --type vue` or `--ns "App\Models"`
- Hierarchy: `quickdex hier <ClassName>`
- Fuzzy search: `quickdex search <pattern>`
```

### 3. Allow `quickdex` in Claude Code permissions

Add to your global `~/.claude/settings.json`:

```json
"Bash(quickdex *)",
"PowerShell(quickdex *)"
```

### 4. Build the index and gitignore the db

```bash
cd /path/to/myproject
quickdex index
echo "/quickdex.db" >> .gitignore
```

Rebuild after significant repository changes:

```bash
quickdex index
```
