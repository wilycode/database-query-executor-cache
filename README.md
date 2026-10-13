# WilyCode Database Query Executor Cache

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-query-executor-cache/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-query-executor-cache/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/wilycode/database-query-executor-cache)](https://packagist.org/packages/wilycode/database-query-executor-cache)

**PSR-16 caching decorator for WilyCode database query executors.** Cache read results, avoid repeated database queries, and invalidate cached results when writes are performed through the decorator. PHP 8.4+.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — independent, reusable PHP components with their own repositories, releases, and Composer installation.
>
> **[Explore the WilyCode ecosystem →](https://github.com/wilycode/wilycode)**

## Table of contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start with PDO and File Cache](#quick-start-with-pdo-and-file-cache)
- [How caching works](#how-caching-works)
- [Reading data](#reading-data)
  - [Fetch one row](#fetch-one-row)
  - [Fetch multiple rows](#fetch-multiple-rows)
  - [Fetch a single column](#fetch-a-single-column)
  - [Caching empty and null results](#caching-empty-and-null-results)
- [Cache keys](#cache-keys)
  - [Custom key prefix](#custom-key-prefix)
- [Cache time-to-live](#cache-time-to-live)
- [Writing data and invalidation](#writing-data-and-invalidation)
- [Transactions](#transactions)
  - [Transaction managed by the decorator](#transaction-managed-by-the-decorator)
  - [Externally managed transaction](#externally-managed-transaction)
- [Dependency injection](#dependency-injection)
- [API reference](#api-reference)
- [Limitations and safety](#limitations-and-safety)
- [Related packages](#related-packages)
- [Development](#development)
- [Contributing and license](#contributing-and-license)

## Overview

`CachedQueryExecutor` wraps an existing `QueryExecutorInterface` implementation and a [PSR-16](https://www.php-fig.org/psr/psr-16/) cache. It implements the **same executor interface**, so application code can use it without knowing whether queries are cached.

```text
QueryInterface (database-query)
            |
            v
CachedQueryExecutor (this package)
       |             |
       | cache hit   | cache miss / write
       v             v
 PSR-16 Cache    QueryExecutor
                       |
                       v
                Binder + Database driver
```

The decorator does **not** parse SQL, execute database commands by itself, implement a cache backend, or automatically discover query dependencies. It delegates SQL execution to the wrapped executor.

**Important:** On a successful write, the decorator calls **`CacheInterface::clear()`**. This clears *every entry in the supplied cache store*. **Use a dedicated PSR-16 cache instance/store for database-query results**, not your application's shared cache. A key prefix alone does not isolate `clear()`.

## Requirements

- PHP **8.4 or newer**.
- [`psr/simple-cache:^3.0`](https://packagist.org/packages/psr/simple-cache) — PSR-16 cache contract.
- [`wilycode/database-query:^0.1`](https://github.com/wilycode/database-query) — SQL query representation.
- [`wilycode/database-query-executor:^0.1`](https://github.com/wilycode/database-query-executor) — executor contract.
- A concrete **PSR-16 cache implementation** and a working **database driver and binder**, installed separately.

This package does not require PDO, MySQLi, or `wilycode/cache-file` at runtime.

## Installation

Install the caching decorator:

```bash
composer require wilycode/database-query-executor-cache
```

For the examples below, also install the WilyCode filesystem cache and PDO adapter:

```bash
composer require wilycode/cache-file wilycode/database-driver-pdo
```

Use `wilycode/database-driver-mysqli` and `MysqliBinder` instead if your application uses MySQLi.

## Quick start with PDO and File Cache

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\DriverPdo\PdoDatabase;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\QueryExecutorCache\CachedQueryExecutor;
use WilyCode\FileCache\FileCache;

$database = PdoDatabase::connect(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app_user',
    'app_password',
);

$inner = new QueryExecutor($database, new PdoBinder());

// Use a dedicated, private directory for cached database-query results.
$cache = new FileCache(__DIR__ . '/var/cache/database-queries');

$executor = new CachedQueryExecutor(
    executor: $inner,
    cache: $cache,
    defaultTtl: 30,
);

$query = Query::select('users', 'u')
    ->columns(['id' => 'id', 'email' => 'email'])
    ->where('u.active', true)
    ->limit(10);

$first = $executor->fetchAll($query);  // Cache miss: executes SQL.
$second = $executor->fetchAll($query); // Cache hit: returns saved rows.
```

The example assumes a reachable MySQL database with a `users` table containing `id`, `email` and `active`. Replace credentials with values from your environment; do not commit real passwords. Keep the cache directory outside the publicly served directory.

## How caching works

The decorator checks the underlying executor's transaction state for each read. Outside a transaction, it compiles the query to generate a stable cache key and consults the PSR-16 cache. On a miss, it executes the read through the inner executor and stores the result for the configured TTL.

| Operation | Cache behavior |
| --- | --- |
| `fetch($query)` | Cache one associative row outside transactions. |
| `fetchAll($query)` | Cache a list of rows outside transactions. |
| `fetchColumn($query, $columnIndex)` | Cache a scalar value, including `null`; index is part of the key. |
| `execute($query)` | Run the write; then invalidate the whole supplied cache store, subject to transaction rules. |
| `getLastInsertId()` | Delegate directly; never cached. |
| `inTransaction()` | Delegate directly to the inner executor. |
| `transaction($callback)` | Run or join a transaction; see [Transactions](#transactions). |

Caching is **not automatic for arbitrary direct SQL executed outside this decorator**. Any write that bypasses it can leave cached results stale until expiration or manual invalidation.

### What happens on a hit?

```text
First fetchAll()   -> cache miss -> database fetchAll() -> cache set()
Second fetchAll()  -> cache hit  -> return cached rows
Successful write   -> database execute() -> cache clear()
Next fetchAll()    -> cache miss -> database fetchAll() -> cache set()
```

## Reading data

### Fetch one row

```php
$user = $executor->fetch(
    Query::select('users', 'u')
        ->column('u.id', 'id')
        ->column('u.email', 'email')
        ->where('u.id', 15),
);
```

`fetch()` returns an associative array. With the WilyCode database drivers, no matching row produces `[]`.

### Fetch multiple rows

```php
$users = $executor->fetchAll(
    Query::select('users', 'u')
        ->where('u.active', true)
        ->orderBy('u.id', 'DESC')
        ->limit(20),
);
```

Results are returned as arrays of rows, just like with the wrapped executor.

### Fetch a single column

```php
$email = $executor->fetchColumn(
    Query::select('users', 'u')
        ->column('u.email')
        ->where('u.id', 15),
);

// For multiple columns, the index is zero-based:
$secondColumn = $executor->fetchColumn(
    Query::raw('SELECT id, email FROM users WHERE id = :id', ['id' => 15]),
    columnIndex: 1,
);
```

The column index is part of the cache key, so reads of column 0 and column 1 never share a cached entry. A negative index throws `InvalidArgumentException` before any cache access or SQL compilation.

### Caching empty and null results

The decorator uses a private cache-miss marker, so a cached empty array (`[]`), `false`, `0`, or `null` is a **cache hit**, not an implicit miss. This avoids repeating SQL for empty results.

## Cache keys

The default `CacheKeyGenerator` computes a SHA-256 hash from the **compiled SQL, parameter values, parameter types, operation name and operation-specific context**. This differentiates queries that are textually identical but use different parameter values, and also distinguishes `fetch()` from `fetchAll()` and different `fetchColumn()` indices.

```text
Default key: db_query_<sha256 hex digest>
```

Keys are generated **before** a cached read. The default key does **not** include the current database connection, schema, tenant, authenticated user, session or cache namespace. See [Limitations and safety](#limitations-and-safety).

### Custom key prefix

```php
use WilyCode\Database\QueryExecutorCache\CacheKeyGenerator;

$keyGenerator = new CacheKeyGenerator('myapp_db_');

$executor = new CachedQueryExecutor(
    executor: $inner,
    cache: $cache,
    keyGenerator: $keyGenerator,
    defaultTtl: 60,
);
```

The prefix changes **key names**, not the table names in SQL and not the scope of `cache->clear()`. Use cache-key-safe characters in a custom prefix; PSR-16 reserves characters such as `{ } ( ) / \ @ :`.

If you need a different key policy, implement `CacheKeyGeneratorInterface::keyFor(string $operation, CompiledQuery $query, array $context = []): string`.

## Cache time-to-live

The constructor's `defaultTtl` is an **integer number of seconds**, set to **30** by default:

```php
$executor = new CachedQueryExecutor($inner, $cache, defaultTtl: 120);
```

- `120` — entries expire after approximately two minutes, as enforced by the supplied cache backend.
- `30` — default setting; short-lived cached reads.
- `0` — allowed, but PSR-16 interprets it as immediate expiration/deletion (effectively disables storage).
- Negative TTLs throw `InvalidArgumentException` when constructing `CachedQueryExecutor`.

There is no per-query TTL argument in the current API. Use a different executor/cache instance if different queries need different TTLs.

## Writing data and invalidation

Run writes through the decorator, not directly through the inner executor:

```php
$affected = $executor->execute(
    Query::update('users')
        ->set(['active' => false])
        ->where('id', 15),
);

// Outside an active transaction: underlying cache->clear() was called.
```

The same rule applies to `INSERT`, `UPDATE`, `DELETE`, or any statement executed through `execute()`. The decorator cannot infer which tables changed, and it does not invalidate individual query keys. A successful `execute()` **always invalidates the entire supplied cache** outside decorator-managed transactions, even if the driver reports 0 affected rows.

If the write throws an exception, that `execute()` call does **not** clear the cache. Errors from the cache implementation are not swallowed; failures reported only as a boolean by `clear()`/`set()` are not separately checked by this decorator.

## Transactions

The decorator bypasses cached reads whenever the underlying executor reports an active transaction. This avoids serving previously cached data from outside that transaction.

### Transaction managed by the decorator

```php
$executor->transaction(function (\WilyCode\Database\QueryExecutor\QueryExecutorInterface $tx): void {
    $tx->execute(Query::insert('users')->values([
        'email' => 'new@example.test',
        'active' => true,
    ]));

    // Within the transaction: all reads bypass the cache.
    $tx->fetchAll(Query::select('users'));
});

// If the transaction committed successfully after a write, cache->clear()
// was called once when the transaction finished.
```

For decorator-owned transactions:

| Case | Effect |
| --- | --- |
| Read inside the transaction | Bypass cache; do not cache result. |
| No writes | Keep cache entries unchanged. |
| One or more successful `execute()` calls, then commit | Clear cache once **after** the wrapped transaction returns successfully. |
| Transaction callback throws and the transaction rolls back | Do not clear cache. |
| Nested call to this decorator's `transaction()` | Joins the active transaction; no savepoints created. |

The wrapped database driver is responsible for actual commit and rollback behavior.

### Externally managed transaction

If a transaction was started **outside** `CachedQueryExecutor`, it cannot detect when the external owner commits or rolls back. Therefore, a successful write through the decorator **clears the cache immediately**, even though the transaction may later roll back.

```php
// Example: the inner executor or database already owns a transaction.
$executor->execute(Query::update('users')->set(['active' => true])->where('id', 15));
// The cache was cleared immediately (not after commit).
```

**Concurrency warning:** other requests may refill the cache with pre-commit data between that early clear and the external commit, causing stale reads. For transaction-based writes, prefer starting transactions through the decorator. If your application manages transaction lifetimes elsewhere, coordinate cache invalidation after commit at the application level.

## Dependency injection

`CachedQueryExecutor` implements `QueryExecutorInterface`, so repositories depend on the **interface**, not on a concrete caching implementation:

```php
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

final class UserRepository
{
    public function __construct(
        private QueryExecutorInterface $executor,
    ) {}

    public function activeUsers(): array
    {
        return $this->executor->fetchAll(
            Query::select('users', 'u')->where('u.active', true),
        );
    }
}
```

Register `CachedQueryExecutor` wherever your dependency injection container expects `QueryExecutorInterface`, or inject it directly. The decorator can be composed with optional profiling or logging decorators.

## API reference

### `CachedQueryExecutor`

```php
public function __construct(
    QueryExecutorInterface $executor,
    CacheInterface $cache,
    ?CacheKeyGeneratorInterface $keyGenerator = null,
    int $defaultTtl = 30,
)
```

| Method | Return type | Behavior |
| --- | --- | --- |
| `execute(QueryInterface $query)` | `int` | Execute and invalidate cached results according to transaction state. |
| `fetch(QueryInterface $query)` | `array` | Cached single-row read outside transactions. |
| `fetchAll(QueryInterface $query)` | `array` | Cached multi-row read outside transactions. |
| `fetchColumn(QueryInterface $query, int $columnIndex = 0)` | `mixed` | Cached scalar result outside transactions. |
| `getLastInsertId()` | `string` | Delegate directly to wrapped executor. |
| `transaction(callable $callback)` | `mixed` | Run/join a transaction; callback receives this decorator. |
| `inTransaction()` | `bool` | Read transaction state from wrapped executor. |

### `CacheKeyGenerator`

```php
use WilyCode\Database\QueryExecutorCache\CacheKeyGenerator;

$generator = new CacheKeyGenerator('db_query_');

$key = $generator->keyFor('fetchAll', $compiledQuery);
$key = $generator->keyFor('fetchColumn', $compiledQuery, ['columnIndex' => 1]);
```

`CacheKeyGeneratorInterface` allows custom key-generation strategies without changing query execution.

## Limitations and safety

| Topic | Important detail |
| --- | --- |
| Shared PSR-16 stores | **Do not share** this cache backend with unrelated application data: writes call `clear()` on the whole backend. |
| Cache isolation | The default key does not include tenant, user, connection, or authorization context. Use physically/logically isolated stores for different tenants, users or databases as needed. |
| External transactions | Writes inside externally managed transactions invalidate before commit; concurrent readers can cache old data. |
| Writes bypassing the decorator | Direct writes through the driver or another process are not detected. Configure TTLs and application-level invalidation accordingly. |
| Non-deterministic queries | Avoid caching reads dependent on the current time, random values, session state or frequently changing external data unless that behavior is acceptable. |
| Failure handling | Exceptions from drivers/caches propagate; boolean cache failures are not explicitly checked. |
| Cache backends | Follow your chosen PSR-16 cache implementation's expiration, serialization, key-format and security rules. |
| Cache stampede | No locking, deduplication or stampede protection is built in. |
| Return values | Reads return the wrapped executor's values; this package does not hydrate models or objects. |

Use a dedicated storage directory for `wilycode/cache-file`, outside the public web root. File-backed cache implementations may serialize PHP values; never allow untrusted users to write cache payload files.

## Related packages

| Package | Purpose |
| --- | --- |
| [`wilycode/database-query`](https://github.com/wilycode/database-query) | Build and compile SQL statements. |
| [`wilycode/database-driver`](https://github.com/wilycode/database-driver) | Database connection, query and transaction contracts. |
| [`wilycode/database-query-executor`](https://github.com/wilycode/database-query-executor) | Execute compiled queries through the selected database driver. |
| [`wilycode/database-driver-pdo`](https://github.com/wilycode/database-driver-pdo) | PDO driver and canonical parameter binder. |
| [`wilycode/database-driver-mysqli`](https://github.com/wilycode/database-driver-mysqli) | MySQLi driver and canonical parameter binder. |
| [`wilycode/cache-file`](https://github.com/wilycode/cache-file) | Filesystem-backed PSR-16 cache implementation. |
| [`wilycode/database-query-executor-profiler`](https://github.com/wilycode/database-query-executor-profiler) | Optional query profiling decorator. |

## Development

```bash
composer install
composer validate --strict
composer test
```

GitHub Actions runs PHPUnit on PHP 8.4 for pushes and pull requests. Tests are included in the source repository but excluded from distribution archives.

## Contributing and license

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and the [GitHub issue tracker](https://github.com/wilycode/database-query-executor-cache/issues).

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
