# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Recombinator is a PHP library for automatic code refactoring via AST manipulation. Unlike typical linters, it fully rewrites the AST to optimize code along its real execution flow. Built on nikic/php-parser. Documentation and comments are in Russian.

Three-phase refactoring approach:
1. **"Unfolding" (развёртка)** — remove abstractions, execute pure (side-effect-free) blocks
2. **"Folding" (свёртка)** — reduce cognitive/cyclomatic complexity by creating new abstractions grouped by side effect type
3. **Interactive** — propose non-automatable improvements (e.g. variable naming) via CLI

## Commands

```bash
# Run tests (Pest)
composer test

# Run tests with coverage (requires XDEBUG)
composer coverage

# Static analysis + rector dry-run + code sniffer
composer check

# Auto-fix code style (rector + phpcbf)
composer fix

# Run tests in Docker for specific PHP versions
make run82    # PHP 8.2
make run83    # PHP 8.3
make run84    # PHP 8.4
make run_all  # all versions
```

To run a single test file: `./vendor/bin/pest tests/path/to/TestFile.php`
To run a single test by name: `./vendor/bin/pest --filter="test name"`

## Architecture

**Namespace:** `Recombinator\` → `src/` (PSR-4)

### Key Modules

- **Core/** — `Parser` is the main orchestrator: parses PHP files, applies visitor pipeline, manages caching. `ExecutionCache` handles memoization. `PrettyDumper` for AST debug output.

- **Analysis/** — Code analysis: `SideEffectClassifier` classifies AST nodes by effect type (Pure, IO, Database, HTTP, NonDeterministic, GlobalState). `CognitiveComplexityCalculator` and `CyclomaticComplexityCalculator` implement `ComplexityCalculatorInterface`. `PureBlockFinder` locates effect-free blocks. `EffectDependencyGraph` builds dependency graphs between effects.

- **Transformation/** — Code transformations. `SideEffectSeparator` splits code into effect groups and pure computations. `FunctionExtractor` extracts code into functions. `NestedConditionSimplifier` flattens nested conditions. `AbstractionRecovery` finds opportunities for new abstractions.

- **Transformation/Visitor/** — ~27 AST visitors extending `BaseVisitor` (nikic/php-parser visitor pattern). Key visitors: `ConstFoldVisitor` (constant folding), `PreExecutionVisitor` (pre-execute pure code), `VarToScalarVisitor` (replace variables with literals), `SideEffectMarkerVisitor` (annotate nodes with effect types), `ClassInlinerVisitor` (inline classes).

- **Domain/** — Value objects: `SideEffectType` (enum), `ComplexityMetrics`, `SeparationResult`, `ScopeStore` (symbol table implementing `SymbolTableInterface`), `FunctionCandidate`.

- **Interactive/** — CLI for interactive refactoring: `InteractiveCLI`, `EditSession`, `ChangeHistory`, `InteractiveEditAnalyzer`.

- **Support/** — Utilities: `Sandbox` (safe code execution), `ColorDiffer` (colored diffs), `Inliner`.

- **Contract/** — Interfaces: `ComplexityCalculatorInterface`, `EffectClassifierInterface`, `SymbolTableInterface`, `CacheInterface`.

### Adding New Visitors

Extend `BaseVisitor` in `src/Transformation/Visitor/`, implement `enterNode`/`leaveNode`. Register in the `Parser` visitor pipeline.

## Code Standards

- PHP 8.4+, `declare(strict_types=1)` everywhere
- PSR-12 with 150 char line limit (200 absolute)
- PHPStan max level
- Pest for testing (feature tests use fixtures in `tests/Feature/fixtures/` and snapshots in `tests/Feature/snapshots/`)
