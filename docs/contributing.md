# Contributing to StateFlow

Thank you for considering contributing to StateFlow! This guide will help you get started with local development.

## Development Setup

This project uses Docker for development, so you don't need PHP installed locally.

### Prerequisites

- Docker
- Make (optional but recommended)

### Getting Started

1. **Initialize the project** (build Docker image and install dependencies):

```bash
make init
```

2. **View all available commands**:

```bash
make help
```

3. **Enter the Docker workspace** (interactive shell):

```bash
make workspace
```

Once in the workspace, you have access to all PHP tools (composer, phpunit, phpstan, etc.).

## Testing

### Run Tests

**Parallel execution** (4 processes):
```bash
make test
```

**Single process** (useful for debugging):
```bash
make test-single
```

**With coverage**:
```bash
make test-coverage
```

### Writing Tests

- All tests should be in the `tests/` directory
- Follow PSR-12 coding standards
- Aim for high test coverage
- Use descriptive test method names

## Code Quality

### Code Style

**Check code style**:
```bash
make lint
```

**Fix code style issues automatically**:
```bash
make lint-fix
```

We use PHP-CS-Fixer with PSR-12 standard and PHP 8.1+ migration rules.

### Static Analysis

**Run PHPStan** (level max):
```bash
make quality
```

All code must pass static analysis at the maximum level.

### Run All Checks

**Run lint, quality, and tests in one command**:
```bash
make check
```

This is what CI runs, so make sure this passes before submitting a PR.

## Development Workflow

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run `make check` to ensure quality
5. Commit your changes with descriptive messages
6. Push to your fork
7. Open a Pull Request

## Project Structure

```
stateflow/
├── src/              # Source code
├── tests/            # Test files
├── docs/             # Documentation
├── .github/          # GitHub workflows
├── Dockerfile        # Development container
├── Makefile          # Development commands
├── composer.json     # Dependencies
├── phpunit.xml.dist  # PHPUnit configuration
└── phpstan.neon.dist # PHPStan configuration
```

## Coding Standards

- **PHP Version**: 8.2+
- **Style**: PSR-12
- **Type Safety**: Strict types, use type hints everywhere
- **Documentation**: PHPDoc for public APIs
- **Testing**: Comprehensive unit tests for all features

## Making Changes

### Adding New Features

1. Review the [architecture documentation](./architecture.md)
2. Check [open questions](./open-questions.md) for design decisions
3. Write tests first (TDD approach)
4. Implement the feature
5. Update documentation
6. Ensure all checks pass

### Fixing Bugs

1. Write a failing test that reproduces the bug
2. Fix the bug
3. Ensure the test passes
4. Ensure no regressions

## Documentation

If your change affects the public API:

- Update relevant documentation in `docs/`
- Update code examples if needed
- Add to `CHANGELOG.md` under `Unreleased`

## Questions?

If you have questions about contributing:

- Open an issue for discussion
- Check existing documentation in `docs/`
- Reach out to maintainers

## Code of Conduct

Be respectful, constructive, and professional. We're all here to build something great together.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
