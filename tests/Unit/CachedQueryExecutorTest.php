<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\QueryExecutorCache\CachedQueryExecutor;
use WilyCode\Database\QueryExecutorCache\Tests\Support\SpyCache;

final class CachedQueryExecutorTest extends TestCase
{
    public function testFetchCachesResultsOutsideTransaction(): void
    {
        $calls = 0;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->method('fetch')->willReturnCallback(static function () use (&$calls): array {
            return ['call' => ++$calls];
        });

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('SELECT 1');

        self::assertSame(['call' => 1], $executor->fetch($query));
        self::assertSame(['call' => 1], $executor->fetch($query));
        self::assertSame(1, $calls);
        self::assertSame(2, $cache->getCalls);
        self::assertSame(1, $cache->setCalls);
    }

    public function testReadBypassesCacheInsideTransaction(): void
    {
        $calls = 0;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(true);
        $inner->method('fetch')->willReturnCallback(static function () use (&$calls): array {
            return ['call' => ++$calls];
        });

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('SELECT 1');

        self::assertSame(['call' => 1], $executor->fetch($query));
        self::assertSame(['call' => 2], $executor->fetch($query));
        self::assertSame(0, $cache->getCalls);
        self::assertSame(0, $cache->setCalls);
    }

    public function testWriteOutsideTransactionReturnsAffectedRowsAndClearsCache(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->method('execute')->willReturn(4);

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);

        self::assertSame(4, $executor->execute($this->query('UPDATE users SET active = 1')));
        self::assertSame(1, $cache->clearCalls);
    }

    public function testSuccessfulTransactionClearsCacheOnceWhenItWrites(): void
    {
        $inTransaction = false;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $inner->method('execute')->willReturn(1);
        $inner->method('transaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction, $inner): mixed {
                $inTransaction = true;
                try {
                    return $callback($inner);
                } finally {
                    $inTransaction = false;
                }
            },
        );

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('UPDATE users SET active = 1');

        $result = $executor->transaction(function (CachedQueryExecutor $transaction) use ($query): string {
            self::assertSame(1, $transaction->execute($query));
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $cache->clearCalls);
    }

    public function testReadOnlyTransactionDoesNotClearCache(): void
    {
        $inTransaction = false;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $inner->method('transaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction, $inner): mixed {
                $inTransaction = true;
                try {
                    return $callback($inner);
                } finally {
                    $inTransaction = false;
                }
            },
        );

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);

        self::assertSame('read-only', $executor->transaction(static fn (QueryExecutorInterface $_): string => 'read-only'));
        self::assertSame(0, $cache->clearCalls);
    }

    public function testFailedTransactionDoesNotClearCache(): void
    {
        $inTransaction = false;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $inner->method('execute')->willReturn(1);
        $inner->method('transaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction, $inner): mixed {
                $inTransaction = true;
                try {
                    return $callback($inner);
                } finally {
                    $inTransaction = false;
                }
            },
        );

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('UPDATE users SET active = 1');

        try {
            $executor->transaction(function (CachedQueryExecutor $transaction) use ($query): never {
                $transaction->execute($query);
                throw new RuntimeException('rollback');
            });
            self::fail('Expected transaction failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        self::assertSame(0, $cache->clearCalls);
    }

    public function testNestedTransactionJoinsCurrentTransaction(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(true);
        $inner->expects(self::never())->method('transaction');

        $executor = new CachedQueryExecutor($inner, new SpyCache());

        self::assertSame(123, $executor->transaction(static fn (QueryExecutorInterface $_): int => 123));
    }

    public function testFetchColumnUsesColumnIndexInCacheKey(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->method('fetchColumn')->willReturnCallback(
            static fn (QueryInterface $query, int $column): string => 'column-' . $column,
        );

        $executor = new CachedQueryExecutor($inner, new SpyCache());
        $query = $this->query('SELECT id, name FROM users');

        self::assertSame('column-0', $executor->fetchColumn($query, 0));
        self::assertSame('column-1', $executor->fetchColumn($query, 1));
        self::assertSame('column-0', $executor->fetchColumn($query, 0));
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CachedQueryExecutor(
            $this->createStub(QueryExecutorInterface::class),
            new SpyCache(),
            defaultTtl: -1,
        );
    }

    public function testFetchAllCachesEmptyArray(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->expects(self::once())->method('fetchAll')->willReturn([]);
        $executor = new CachedQueryExecutor($inner, new SpyCache());
        $query = $this->query('SELECT * FROM missing_table');

        self::assertSame([], $executor->fetchAll($query));
        self::assertSame([], $executor->fetchAll($query));
    }

    public function testFetchColumnCachesNullAndFalseValues(): void
    {
        $calls = 0;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->method('fetchColumn')->willReturnCallback(static function () use (&$calls): mixed {
            return ++$calls === 1 ? null : false;
        });
        $executor = new CachedQueryExecutor($inner, new SpyCache());

        self::assertNull($executor->fetchColumn($this->query('SELECT maybe_null')));
        self::assertNull($executor->fetchColumn($this->query('SELECT maybe_null')));
        self::assertFalse($executor->fetchColumn($this->query('SELECT another')));
        self::assertFalse($executor->fetchColumn($this->query('SELECT another')));
        self::assertSame(2, $calls);
    }

    public function testFetchAndFetchAllHaveDifferentCacheKeys(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->expects(self::once())->method('fetch')->willReturn(['id' => 1]);
        $inner->expects(self::once())->method('fetchAll')->willReturn([['id' => 1]]);
        $executor = new CachedQueryExecutor($inner, new SpyCache());
        $query = $this->query('SELECT id FROM users');

        self::assertSame(['id' => 1], $executor->fetch($query));
        self::assertSame([['id' => 1]], $executor->fetchAll($query));
        self::assertSame(['id' => 1], $executor->fetch($query));
        self::assertSame([['id' => 1]], $executor->fetchAll($query));
    }

    public function testSuccessfulWriteInvalidatesPreviousCachedRead(): void
    {
        $calls = 0;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->method('fetch')->willReturnCallback(static function () use (&$calls): array {
            return ['version' => ++$calls];
        });
        $inner->expects(self::once())->method('execute')->willReturn(0);
        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('SELECT version FROM users');

        self::assertSame(['version' => 1], $executor->fetch($query));
        self::assertSame(['version' => 1], $executor->fetch($query));
        self::assertSame(0, $executor->execute($this->query('UPDATE users SET active = 1')));
        self::assertSame(['version' => 2], $executor->fetch($query));
        self::assertSame(1, $cache->clearCalls);
    }

    public function testFailedWriteDoesNotInvalidateCachedRead(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->expects(self::once())->method('fetch')->willReturn(['id' => 1]);
        $inner->method('execute')->willThrowException(new RuntimeException('write failed'));
        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('SELECT id FROM users');

        self::assertSame(['id' => 1], $executor->fetch($query));
        try {
            $executor->execute($this->query('UPDATE users SET id = 2'));
            self::fail('Expected failed write.');
        } catch (RuntimeException $exception) {
            self::assertSame('write failed', $exception->getMessage());
        }

        self::assertSame(['id' => 1], $executor->fetch($query));
        self::assertSame(0, $cache->clearCalls);
    }

    public function testManagedTransactionBypassesCacheAndDefersInvalidationUntilCommit(): void
    {
        $inTransaction = false;
        $reads = 0;
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $inner->method('fetch')->willReturnCallback(static function () use (&$reads): array {
            return ['read' => ++$reads];
        });
        $inner->method('execute')->willReturn(1);
        $inner->method('transaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction, $inner): mixed {
                $inTransaction = true;
                try {
                    return $callback($inner);
                } finally {
                    $inTransaction = false;
                }
            },
        );

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);
        $query = $this->query('SELECT id FROM users');
        self::assertSame(['read' => 1], $executor->fetch($query));

        $executor->transaction(function (QueryExecutorInterface $tx) use ($query, $cache): void {
            self::assertSame(['read' => 2], $tx->fetch($query));
            self::assertSame(['read' => 3], $tx->fetch($query));
            $tx->execute($this->query('UPDATE users SET id = 2'));
            $tx->execute($this->query('UPDATE users SET id = 3'));
            self::assertSame(0, $cache->clearCalls);
        });

        self::assertSame(1, $cache->clearCalls);
        self::assertSame(['read' => 4], $executor->fetch($query));
        self::assertSame(['read' => 4], $executor->fetch($query));
    }

    public function testDelegatesLastInsertIdAndTransactionState(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('getLastInsertId')->willReturn('42');
        $inner->expects(self::once())->method('inTransaction')->willReturn(true);
        $executor = new CachedQueryExecutor($inner, new SpyCache());

        self::assertSame('42', $executor->getLastInsertId());
        self::assertTrue($executor->inTransaction());
    }

    public function testNegativeColumnIndexRejectedBeforeCompilingAndAccessingCache(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::never())->method('fetchColumn');
        $cache = new SpyCache();
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::never())->method('compile');
        $executor = new CachedQueryExecutor($inner, $cache);

        try {
            $executor->fetchColumn($query, -1);
            self::fail('Expected invalid column index.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Column index cannot be negative.', $exception->getMessage());
        }
        self::assertSame(0, $cache->getCalls);
    }

    private function query(string $sql): QueryInterface
    {
        $query = $this->createStub(QueryInterface::class);
        $query->method('compile')->willReturn(new CompiledQuery($sql, [], []));

        return $query;
    }

    public function testWriteInsideExternallyManagedTransactionClearsCacheImmediately(): void
    {
        $query = $this->query('UPDATE users SET active = 1');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(true);
        $inner->expects(self::once())->method('execute')->with($query)->willReturn(2);

        $cache = new SpyCache();
        $executor = new CachedQueryExecutor($inner, $cache);

        self::assertSame(2, $executor->execute($query));
        self::assertSame(1, $cache->clearCalls);
    }
}
