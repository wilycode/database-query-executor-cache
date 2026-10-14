# Changelog

All notable changes to WilyCode Database Query Executor Cache are documented here.

## [Unreleased]

### Changed

- Updated the installation examples and related-package links to the renamed database packages.

## [0.1.0] - 2026-10-13

### Added

- Initial PSR-16 cache decorator implementing `QueryExecutorInterface`.
- Deterministic cache keys for compiled SQL, parameters, types, read operation and column index.
- Configurable cache TTL and optional cache key generator.
- Cache bypass for reads within transactions.
- Cache invalidation after writes and after committed, decorator-managed write transactions.
- Unit tests and file-cache integration tests.
- Automated PHPUnit validation on PHP 8.4.
- Expanded English documentation, including cache isolation and transaction limitations.
