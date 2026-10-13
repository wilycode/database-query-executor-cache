# Contributing to WilyCode Database Query Executor Cache

Thank you for helping improve the WilyCode PHP ecosystem.

## Getting started

1. Fork the repository and create a feature branch.
2. Install development dependencies with `composer install`.
3. Run `composer validate --strict` and `composer test`.
4. Add regression tests for changes to cache keys, invalidation and transaction behavior.
5. Update `README.md` and `CHANGELOG.md` when changing documented behavior.
6. Open a pull request with a clear description of the change and test results.

## Guidelines

- Target PHP 8.4 or newer and use strict types.
- Preserve `QueryExecutorInterface` compatibility.
- Keep the decorator independent of individual PDO and MySQLi adapters.
- Do not cache reads while a database transaction is active.
- Document any behavior that can affect stale data, invalidation or isolation.
- Keep credentials and application secrets out of fixtures and commits.

See the [WilyCode PHP Ecosystem](https://github.com/wilycode/wilycode) for related libraries.
