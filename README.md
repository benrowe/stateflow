# StateFlow

A flexible and intuitive state machine library for PHP.

## Features

- Deterministic State transition management
- Simple and intuitive API
- Support for state transitions with Gates and Actions
- Event-driven architecture
- Extensible and customizable
- Mutex lock machanism to control race conditions
- Full test coverage
- PHP 8.2+ support

## Requirements

- PHP 8.2 or higher
- Docker (for development)

## Installation

Install via Composer:

```bash
composer require benrowe/stateflow
```

## Development Setup

This project uses Docker for development, so you don't need PHP installed locally.

Initialize the project (build Docker image and install dependencies):

```bash
make init
```

View all available commands:

```bash
make help
```

Enter the Docker workspace (interactive shell):

```bash
make workspace
```

## Documentation

Comprehensive architecture and design documentation is available in the [`docs/`](./docs) directory:

- [Architecture Overview](./docs/architecture.md) - High-level design goals and principles
- [Flow Diagrams](./docs/diagrams.md) - Visual flowcharts and sequence diagrams
- [Core Concepts](./docs/core-concepts.md) - State, Gates, Actions, Configuration
- [Observability](./docs/observability.md) - Event system and monitoring
- [Locking System](./docs/locking.md) - Mutex locks and race condition handling
- [Interface Definitions](./docs/interfaces.md) - Complete API reference
- [Usage Examples](./docs/examples.md) - Common usage patterns
- [Open Questions](./docs/open-questions.md) - Unresolved design decisions

## Usage

```php
use BenRowe\StateFlow\StateMachine;

// Coming soon - usage examples will be added as implementation progresses
// See docs/examples.md for planned usage patterns
```

## Testing

Run the test suite (parallel execution with 4 processes):

```bash
make test
```

Run tests without parallelization:

```bash
make test-single
```

Run tests with coverage:

```bash
make test-coverage
```

## Code Quality

Check code style:

```bash
make lint
```

Fix code style issues:

```bash
make lint-fix
```

Run static analysis:

```bash
make quality
```

Run all checks (lint, quality, tests):

```bash
make check
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

## Credits

- [Ben Rowe](https://github.com/benrowe)
- [All Contributors](../../contributors)
