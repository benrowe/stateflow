# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

StateFlow is a workflow engine for PHP that orchestrates state transitions with built-in observability and race condition prevention. It's designed around delta-based transitions (specify only what changes) and supports pause/resume workflows with saga-like capabilities.

**Status:** Alpha stage - architecture is designed and documented, under active development.

## Development Commands

### Testing
```bash
# Run all tests in parallel (4 processes, no coverage)
make test

# Run a single test file
make run ARGS="composer test:single tests/Unit/Events/GateEvaluatedTest.php"

# Run tests with coverage report (HTML in coverage/ directory)
make test-coverage

# Run tests with coverage for CI (requires 100% coverage)
composer test:coverage:ci
```

### Code Quality
```bash
# Run all quality checks (lint + quality + test)
make check

# Lint code (dry-run, shows diff)
make lint

# Auto-fix code style issues
make lint-fix

# Run static analysis (PHPStan + PHPMD)
make quality
```

### Other

Tooling is ran from the docker container, you can run any php-based tooling via the `make run` and supply an argument

```bash
make run ARGS="composer update"
```

## Development Workflow

**IMPORTANT:** Every time you make code or test changes, you MUST run:

```bash
make check
```

This runs all quality checks in one command:
1. **Linting** - Ensures code style compliance (PHP-CS-Fixer)
2. **Static Analysis** - Runs PHPStan and PHPMD
3. **Tests** - Runs full test suite (175 tests, 740 assertions)

Only consider your changes complete when `make check` passes with all green checks.

## Core Architecture

### State Flow Execution Model

StateFlow uses a **two-step execution model** that separates setup from execution:

1. **Setup Phase**: `StateFlow::transition(State, array $delta)` returns a `StateWorker`
2. **Execution Phase**: `StateWorker::execute()` runs the transition

This separation enables:
- Step-through execution (`runGates()`, `runActions()`, `runNextAction()`)
- Pause/resume workflows (serialize context, resume later)
- Manual lock management
- Fine-grained control over workflow lifecycle

### Key Components

**StateFlow** (stateless service)
- Configured once with `ConfigurationProvider`, `EventDispatcher`, `LockProvider`
- Reusable across multiple entities
- Call `transition()` to create a `StateWorker` for a specific transition
- Call `fromContext()` to resume a paused workflow

**StateWorker** (manages single transition lifecycle)
- Encapsulates state, configuration, and execution for one transition
- Provides both one-shot (`execute()`) and step-by-step APIs
- Manages lock acquisition/release
- Returns `TransitionContext` with execution trace

**TransitionContext** (execution trace and state)
- Tracks current state, gate evaluations, action executions
- Serializable for pause/resume workflows
- Provides status checks: `isCompleted()`, `isPaused()`, `isStopped()`
- Contains complete audit trail

### Two-Tier Validation

**Transition Gates** - evaluated first, must ALL pass or transition stops
- Use for: permissions, business rules, preconditions
- Return `GateResult::DENY` to stop entire transition
- Return `GateResult::ALLOW` to continue
- Return `GateResult::SKIP_IDEMPOTENT` to skip actions but succeed

**Action Gates** - optional guards on individual actions
- Use for: conditional actions (e.g., "send email only if subscribed")
- Implement `Guardable` interface on your `Action`
- If gate fails, action is skipped but workflow continues

### State Management

The `State` interface gives you full control:
```php
interface State {
    public function toArray(): array;           // For serialization
    public function with(array $changes): State; // Your merge logic
}
```

Users define their own merge strategy in `with()`. The `$changes` array is the delta passed to `transition()`.

### Configuration Provider

Lazy-loaded configuration based on current state and desired delta:
```php
$configProvider = function(State $currentState, array $delta): Configuration {
    return match ($delta['status'] ?? null) {
        'processing' => new Configuration(
            transitionGates: [new HasInventoryGate()],
            actions: [new ChargePaymentAction(), new ReserveInventoryAction()],
        ),
        'shipped' => new Configuration(
            transitionGates: [new HasPaymentGate()],
            actions: [new CreateShipmentAction()],
        ),
        default => new Configuration(),
    };
};
```

## Implementation Progress (from checklist.md)

### Core Components Status
- ✅ StateFlow, StateWorker, TransitionContext - fully implemented
- ✅ Locking system - fully implemented with all strategies (FAIL_FAST, SKIP, WAIT, NONE)
- ✅ Pause/resume workflows - fully implemented via `StateFlow::fromContext()`
- ✅ Step-through execution - `runGates()`, `runActions()`, `runNextAction()` all implemented
- ✅ Context serialization/unserialization - fully implemented with factory-based reconstruction

### What Works Now
- ✅ Complete transition flow: `StateFlow::transition()` → `StateWorker::execute()`
- ✅ Gate evaluation with tracking (transition gates + action gates)
- ✅ Action execution with full control (CONTINUE, PAUSE, STOP states)
- ✅ Complete event system (all events dispatched throughout lifecycle)
- ✅ Step-by-step execution (`runGates()`, `runActions()`, `runNextAction()`)
- ✅ Pause/resume workflows via `StateFlow::fromContext()`
- ✅ Full locking system with race condition prevention
  - All 4 lock strategies: NONE, FAIL_FAST, SKIP, WAIT
  - Automatic lock acquisition/release
  - Lock renewal for long-running transitions
  - Lock lost detection during execution
  - Lock maintained during pause, released on stop/completion/failure
- ✅ Context serialization/persistence
  - `serialize()` - JSON encoding of complete context
  - `unserialize()` - Factory-based reconstruction of State, Action, and Gate objects
  - `getStatusMetadata()` - Access PAUSE/STOP metadata from actions
  - Complete preservation of execution state, gates, actions, and lock state

### Project Status
**StateFlow is feature-complete!** All planned functionality has been implemented and tested:
- 198 tests passing with 821 assertions
- 100% code coverage maintained
- All acceptance test scenarios completed (87 scenarios, 68+ implemented)
- Production-ready with comprehensive observability and locking mechanisms

## Testing Structure

```
tests/
├── Unit/          # Unit tests for individual components
│   ├── Events/    # Event-related tests
│   └── ...
└── Integration/   # Integration tests for complete workflows
    └── StateFlowTest.php
```

**Test file naming:** `{ClassName}Test.php`

**Running specific tests:**
```bash
# Single test file
make run ARGS="composer test:single tests/Unit/Events/GateEvaluatedTest.php"

# All tests
make test
```

## Code Style and Quality Requirements

- **PHP version:** 8.2+ (strict types enforced)
- **Coverage target:** 100% (enforced in CI)
- **Code style:** PHP-CS-Fixer (run `composer lint:fix` before committing)
- **Static analysis:** PHPStan + PHPMD (run `composer quality`)
- **PSR-4 autoloading:** `BenRowe\StateFlow\` → `src/`
  - There should only be one class per file. and the class/interface/enum should match the file name. e.g. `class MyClass` => `MyClass.php` 

## Design Principles

1. **Stateless machine** - StateFlow is a service, not per-entity
2. **Delta-based transitions** - specify only what changes, not entire state
3. **Lazy configuration** - load gates/actions based on current transition
4. **Observable orchestration** - events fired at every step
5. **Enums over booleans** - `GateResult`, `ExecutionState` for extensibility
6. **Serializable context** - pause/resume workflows across requests

## Documentation

Comprehensive docs in `docs/` directory:
- `architecture.md` - Design goals and principles (read this first!)
- `diagrams.md` - Visual flowcharts
- `core-concepts.md` - State, Gates, Actions, Configuration
- `observability.md` - Event system
- `locking.md` - Race condition handling
- `interfaces.md` - Complete API reference
- `examples.md` - Real-world patterns

Always consult `docs/architecture.md` before making architectural decisions.
- when writing or updating code, make sure there's an associated unit test