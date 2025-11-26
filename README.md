# StateFlow

A flexible and intuitive state machine library for PHP.

## Features

- Deterministic State management
- Simple and intuitive API
- Support for state transitions with guards and callbacks
- Event-driven architecture
- Extensible and customizable
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

## Usage

```php
use BenRowe\StateFlow\StateMachine;

// Coming soon - usage examples will be added as the library develops
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
