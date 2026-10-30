# recombinator — для агентов

## Project Overview

Recombinator is a PHP library for automatic code refactoring via AST manipulation. Unlike typical linters, it fully rewrites the AST to optimize code along its real execution flow. Built on nikic/php-parser. Documentation and comments are in Russian.

Three-phase refactoring approach:
1. **"Unfolding" (развёртка)** — remove abstractions, execute pure (side-effect-free) blocks
2. **"Folding" (свёртка)** — reduce cognitive/cyclomatic complexity by creating new abstractions grouped by side effect type
3. **Interactive** — propose non-automatable improvements (e.g. variable naming) via CLI

## Conventions

- New visitors extend `BaseVisitor` in `src/Transformation/Visitor/` and must be added to both pipelines: `public/api.php` (web UI) and `bin/zoo.php`. `Parser` holds only a legacy subset — do not register there.
- `declare(strict_types=1)` everywhere.
- Feature tests use fixtures in `tests/Feature/fixtures/` and snapshots in `tests/Feature/snapshots/`.

## Команды

Обслуживание — как в pretty-php: `composer test`, `composer check`, `composer fix`.

- `check` — phpstan (baseline), rector --dry-run, phpcs; ничего не меняет
- `fix` — rector, phpcbf
- `test` — pest

Цели Makefile одинаковые во всех проектах `~/projects` (`make help` — список).
