---
name: quickdex
description: "Look up code through the QuickDex SQLite index BEFORE Read/grep/ls. Use for: where is X defined, show me function/class/method X, who uses/calls/imports X, find a file or component, class hierarchy, does file contain Y, routes, Inertia pages, which tests cover X, repo overview, text search. Covers PHP, Laravel, Blade, Vue, TS/JS, Go, Python. Saves tokens: returns exact lines instead of whole files."
license: MIT
metadata:
  author: Aevonix
  version: "3.2.0"
  domain: tooling
  role: assistant
  scope: code-search
  output-format: code
---

# QuickDex

Query the index first; Read a file only when the index can't answer. Run `quickdex <command> [args]` (fallback: `php QuickDex/bin/query.php <command> [args]`).

**Windows: run it from PowerShell, not Bash.** `php` is usually not on Bash's PATH there, so the call fails silently and wastes a turn.

## Instead of X → run Y

| Instead of | Run |
|---|---|
| `ls -R` / browsing a fresh repo | `overview` |
| Reading a whole file for one class/method | `body <Name>` (exact source, one call) |
| grep for `class Foo` / `function foo` | `def <Name>` (fuzzy: `search <part>`) |
| `grep -rn Foo` to find usages | `refs <Name>` |
| `grep -rn "use TraitName"` | `uses <Trait>` |
| `grep -A5` / N greps just to check presence | `patch <Class\|path> "p1" "p2" …` (✅/❌ + line) |
| Reading a file to list what's in it | `syms <path>` / `deps <path>` |
| `find`/`ls` for files of a kind | `files --type vue` · `--ns "App\Models"` · `--path app/Http` |
| `grep -rl Foo tests/`, or running the whole suite | `tests <Class>` |
| `ls routes/` → grep → Read route file → Read controller | `route <name\|search>` |
| Route grep → controller → Vue → `config/ziggy.php` | `page <Name>` |
| `rg`/`grep` for plain text | `grep "a" "b" [--dir x,y] [--ext php] [-l] [--limit N]` |

## Rules

- **Trust "not found".** A stale or empty result rebuilds the index and retries automatically (`(index was stale — refreshed)`). Don't run `index` before a query, and don't re-check with grep.
- `body` caps at 10 matches / 400 lines. For a common name (`store`, `index`) use `--limit N`, or `def` first to pick the class. Use `def` + a ranged Read only when you need lines around the symbol.
- Run independent lookups as parallel calls in one message.
- "Is feature X built?" → `git log --oneline -20`, then one `ls` of the expected files, then `patch` only the 2–3 riskiest points. Don't verify every item.
- QuickDex `grep` is a case-insensitive literal substring match, not a regex. Use separate args for OR (`"a|b"` is also split). Lockfiles/`*.min.*` are skipped unless `--all`. A pattern with `( ) | &` needs PowerShell's `--%` first: `quickdex grep --% "request()->all()"`.
- `ziggy-check` after editing a Vue page's `route()` calls or `config/ziggy.php`. Exit 2 = a page will throw.

## What the index knows

- `def`/`syms`/`search` include methods (`kind=method`, `ns=<owning class>`), so `def store` lists every `store()` labeled by class. Arrow-function consts count as functions.
- `refs` is a usage graph built with PHP's tokenizer: `new X`, `X::`, extends/implements, type hints, same-namespace siblings without a `use`, plus Vue template tags (`<app-button>` → `AppButton`) and `route('name')` literals. Comments and strings never match. A thin result is real.
- Blade: `@include`/`@extends`/`<x-…>` are refs (`refs x-email.layout`), and `@section` is a def.
- Migrations: `def invoices` → every migration touching the `invoices` table; `body invoices` → its column changes.
- Vue `defineProps`/`defineEmits` → `kind=prop`/`emit`, `ns=<component>`.
- Wayfinder output (`resources/js/{actions,routes}`) sorts last, tagged `·gen`.
- `hier` covers PHP extends/implements/traits and Python bases. `children <Class>` lists subclasses.

## Maintenance

`quickdex index` is incremental (mtime-based). `--force` does a full rebuild, which also happens automatically after a schema upgrade. `meta` shows the last build time and file count.
