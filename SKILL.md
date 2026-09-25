---
name: quickdex
description: "Use the QuickDex SQLite symbol index to locate PHP/Vue/TS/JS symbols and file ownership before reading file contents. This reduces token cost and improves search accuracy."
license: MIT
metadata:
  author: Aevonix
  version: "3.1.0"
  domain: tooling
  role: assistant
  scope: code-search
  output-format: code
---

# QuickDex Symbol Index Skill

Use this skill whenever you need to find a class, interface, trait, function, constant, component, or file in the repository. Always query the index before reading files.

## Primary workflow

1. Run the appropriate `query.php` command for the symbol or file you need.
2. For "show me this function/class," prefer `body <Name>` over `def` + a separate file read — it returns the exact source directly from the indexed line range, in one call.
3. When you do need `def`, use the returned `file` + `line`/`end_line` to read only the exact range needed.
4. If no direct match, use `search` to fuzzy-find by partial name.
5. Use `refs` to find all files (and exact lines) that use a symbol before deciding which to read.

## Shell requirement

**On Windows, always invoke QuickDex via PowerShell, never Bash.** `php` is not on the Bash PATH in Claude Code on Windows — calls silently fail and waste a turn.

If `quickdex` is on the system PATH (via the wrapper scripts in `bin/`), prefer the short form: `quickdex <command> [args]`. Otherwise fall back to `php QuickDex/bin/query.php <command> [args]`.

## Query commands

All commands: `quickdex <command> [args]`  
Fallback: `php QuickDex/bin/query.php <command> [args]`

| Command | What it answers |
|---|---|
| `def <Name>` | Where is this symbol defined? Namespace prefix stripped (`App\Models\User` → `User`). Framework-generated defs (Wayfinder) sort last, tagged `·gen` |
| `body <Name> [--limit N]` | **Prefer over Read for a single symbol.** Prints the full source from the indexed line range. Capped at 10 matches / 400 lines by default. |
| `refs <Name>` | Which files (and exact lines) reference this symbol? A **usage** graph, not just imports — catches `new X`, `X::`, `extends`/`implements`, type hints, and same-namespace siblings that have no `use`. Falls back to path-suffix match (`pages/Calendar` finds `@/pages/Calendar`) |
| `files --type vue` | List all Vue files (also: php, ts, js, blade) |
| `files --ns "App\Models"` | List all files under a namespace prefix (PHP only) |
| `files --path "app/Http"` | List all files under a path prefix (any language) |
| `hier <ClassName>` | extends / implements / traits for a class |
| `children <ClassName>` | All classes that extend this class |
| `syms <path/to/file.php>` | All symbols defined in one file |
| `deps <path/to/file.php>` | All imports/use statements in one file |
| `search <pattern>` | Fuzzy search across all symbol names. Namespace prefix stripped. (alias: `find`) |
| `uses <TraitName>` | All classes that use a given trait |
| `patch <Class\|path> <p1> …` | Check if file contains each pattern — ✅/❌ + line, no context |
| `route [search]` | **Laravel route table**: method, URI, name, `Controller@action`, Inertia page, Ziggy group, `file:line`. Exact name → also middleware + every `route('name')` caller. Use this before opening `routes/*.php` or a controller |
| `page <Name\|path.vue>` | **Start here for any Inertia page work.** Vue file, controller(s) rendering it, routes + middleware, audience, every `route()` call with its Ziggy group (❌ = Ziggy will throw on render), child components |
| `tests <Class>` | Which tests cover a class — run these first, not the suite |
| `ziggy-check` | Lint all pages for `route()` calls outside the page's Ziggy groups. Run after touching a Vue page's links or `config/ziggy.php`; exit 2 = violation |
| `overview` | Repo shape in one screen — the first call in a fresh session, instead of `ls -R` |
| `grep <p1> [p2 …] [--dir a,b] [--ext …] [--limit N] [-l] [--all]` | Scan files for a literal string (case-insensitive substring, not regex) — content search, not symbol lookup. Multiple patterns = OR. `--dir` takes a comma list and errors on a bad path; `-l`/`--files-only` lists files once; `--limit` caps rows (default 200); `--all` includes lockfiles/`*.min.*` |
| `index [--force]` | Build or rebuild the index (aliases: `build`, `reindex`, `rebuild`) |
| `meta` | Index timestamp and total file count |

## Coverage notes

- `def`/`syms`/`search` index class **methods**, not just top-level functions. Method rows have `kind=method` and `ns=<owning class>` (not the file namespace), so `def store` returns every `store()` across all controllers, disambiguated by class.
- JS/TS/Vue arrow-function consts (`const foo = () => {...}`) are indexed as `kind=function`, same as `function foo() {}`.
- `.blade.php` files are indexed as `type=blade`. `@include`/`@extends`/`@component`/`<x-component>` become refs (`refs x-email.layout` finds every view using that component); `@section(...)` becomes a def (`kind=section`).
- Migration table operations (`Schema::create/table/dropIfExists/drop`) are indexed as `kind=table:<op>`, `name=<table>` — `def invoices` lists every migration touching the `invoices` table without opening `database/migrations/`. `body invoices` prints the actual migration closure content (columns added/changed), not just the location.
- Every def carries an `end_line` (its body's closing brace, or the same line for body-less declarations like constants/type aliases) — this is what `body` reads to print exact source without over- or under-shooting the symbol.
- `refs` is a **usage** index, not just an import list. It records inline class references — `new X`, `X::` (static call / `::class` / class const), `extends`/`implements`, and type hints (`X $var`, incl. promoted properties and `?X`) — resolved via PHP's native tokenizer, so comments and string literals never produce false positives. Crucially this covers a class referencing a **sibling in the same namespace** (no `use` needed) — which the old import-only index missed, occasionally making a built feature look unused. A thin `refs` result (e.g. only a test file) is no longer a reason to distrust it, but grep remains the ground truth for non-symbol text.
- Framework-generated defs under `resources/js/actions/` and `resources/js/routes/` (Laravel Wayfinder) are flagged `generated` and sort **after** hand-written symbols in `def`/`search`/`body`, tagged `·gen`, so a real controller method isn't buried under generated `store`/`index`/`update` stubs.

## Examples

```bash
# Find where HourEntry is defined (namespace prefix stripped automatically)
quickdex def HourEntry
quickdex def "App\Models\HourEntry"

# Print HourEntry's actual source — prefer this over Read for a single symbol
quickdex body HourEntry
quickdex body store --limit 3       # first 3 store() methods across all controllers

# Find all files that use HourEntry
quickdex refs HourEntry
quickdex refs "pages/Calendar"      # suffix match: finds @/pages/Calendar imports

# Find all Vue pages
quickdex files --type vue

# Find all models
quickdex files --ns "App\Models"

# Find all controllers by path prefix (works for any language, unlike --ns)
quickdex files --path "app/Http/Controllers"

# Class hierarchy
quickdex hier UserController

# What extends Controller?
quickdex children Controller

# What symbols are in a file?
quickdex syms app/Models/HourEntry.php

# What does HourEntryController import?
quickdex deps app/Http/Controllers/HourEntryController.php

# Find anything with "Hour" in the name
quickdex search Hour

# Which models use BelongsToTenant?
quickdex uses BelongsToTenant

# Verify multiple fixes were applied — no grep, no context lines
quickdex patch BillingController "loadCount" "translatedFormat" "users_count"

# grep with multiple patterns (OR) — use separate args, not "foo\|bar" regex syntax
# (grep is a literal substring match, never regex; "|" also crashes the Windows
# .bat wrapper since it's a cmd.exe metacharacter, regardless of grep semantics)
quickdex grep "SEPA" "iDEAL" --dir app --ext php

# comma-separated dirs, list matching files only, or cap the row count
quickdex grep "class" --dir app/Services,app/Models --ext php --files-only
quickdex grep "TODO" --limit 50

# grep also searches yml/yaml by default now (e.g. Docker compose, monitoring
# config) — no --ext override needed for infra-as-code lookups
quickdex grep "prometheus" --dir monitoring_config

# A single pattern that genuinely needs shell-special characters (parens, pipe,
# ampersand) still needs PowerShell's stop-parsing token before it, since those
# are cmd.exe metacharacters the .bat wrapper's argument passthrough hits:
quickdex grep --% "request()->all()" --dir app --ext php

# Route → controller@action → Inertia page → Ziggy group, plus every caller
quickdex route "expenses.approve"

# Everything about one Inertia page (backend and frontend) in one call
quickdex page "Admin/Backlinks/Index"

# Which tests exercise a class
quickdex tests GeoScoreService

# Will any page throw in Ziggy because it links a route its audience never receives?
quickdex ziggy-check

# Incremental rebuild (automatic on stale empty results; or force manually)
quickdex index
```

## Token reduction

See `TOKEN_REDUCTION.md` for the full ruleset. Quick summary:

- `body` replaces `def` + a full-file (or generously-ranged) `Read` — the most common "find it, then read it" round trip collapses into one call
- `patch` replaces N existence-greps — returns ✅/❌ per pattern, zero context lines
- `uses` replaces `grep -rn "use TraitName"` across the whole tree
- `route` replaces the `ls routes/` → grep → Read chain, and the controller Read that used to follow it
- `page` replaces the route grep → controller Read → Vue Read → `config/ziggy.php` Read chain for Inertia work
- `tests <Class>` replaces `grep -rl ClassName tests/`
- No `-A`/`-B`/`-C` grep flags when the answer is yes/no
- For plan/feature done checks: `git log` → parallel `ls` of expected files → `patch` only HIGH-risk items

## Rebuilding the index

Run after significant repository changes:

```bash
quickdex index
```

This is **incremental by default** — only files whose mtime changed since the last build get re-parsed; unchanged files are skipped and deleted files are purged from the index, so rebuilds after a small edit are fast even on large repos. Force a full rebuild (also happens automatically if the on-disk schema is out of date, e.g. right after upgrading QuickDex):

```bash
quickdex index --force
```

Optional positional arguments: `quickdex index [root] [output.db] [--force]`

## Index schema (SQLite: quickdex.db)

- `files` — path, type, lines, ns, summary, mtime (drives incremental rebuilds)
- `defs` — name, kind, file, line, end_line, ns, generated (indexed on name; end_line backs `body`; generated=1 downranks Wayfinder output)
- `refs` — symbol, file, line (usage index: imports **+ inline class references**, with line numbers)
- `hierarchy` — class, extends, implements (JSON), traits (JSON)
- `meta` — generated_at, version (schema 2.1), root, total_files, indexed_files, skipped_unchanged, mode

A query against an older-schema DB auto-rebuilds once (`(index schema updated — rebuilt)`) before returning — upgrading QuickDex never surfaces a "no such column" error. The DB is found by walking up from CWD; there is no repo-relative fallback, so querying outside any indexed project fails loudly instead of serving another project's stale index.

## Why SQLite over JSON

- Targeted O(log n) lookups — no full-file load into context
- `refs` inverted index answers "what uses X?" without grep
- `hierarchy` answers class tree questions in one query
- ~60% smaller than equivalent JSON
