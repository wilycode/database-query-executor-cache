<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache;

use Psr\SimpleCache\CacheInterface;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

final class CachedQueryExecutor implements QueryExecutorInterface
{
    private readonly CacheKeyGeneratorInterface $keyGenerator;
    private readonly object $cacheMiss;
    private bool $managesTransaction = false;
    private bool $dirty = false;

    public function __construct(
        private readonly QueryExecutorInterface $executor,
        private readonly CacheInterface $cache,
        ?CacheKeyGeneratorInterface $keyGenerator = null,
        private readonly int $defaultTtl = 30,
    ) {
        if ($defaultTtl < 0) {
            throw new \InvalidArgumentException('Default cache TTL cannot be negative.');
        }

        $this->keyGenerator = $keyGenerator ?? new CacheKeyGenerator();
        $this->cacheMiss = new \stdClass();
    }

    public function execute(QueryInterface $query): int
    {
        $affectedRows = $this->executor->execute($query);

        if ($this->managesTransaction) {
            $this->dirty = true;
        } else {
            $this->cache->clear();
        }

        return $affectedRows;
    }

    public function fetch(QueryInterface $query): array
    {
        return $this->cachedRead(
            'fetch',
            $query,
            fn (): array => $this->executor->fetch($query),
        );
    }

    public function fetchAll(QueryInterface $query): array
    {
        return $this->cachedRead(
            'fetchAll',
            $query,
            fn (): array => $this->executor->fetchAll($query),
        );
    }

    public function fetchColumn(QueryInterface $query, int $columnIndex = 0): mixed
    {
        if ($columnIndex < 0) {
            throw new \InvalidArgumentException('Column index cannot be negative.');
        }

        return $this->cachedRead(
            'fetchColumn',
            $query,
            fn (): mixed => $this->executor->fetchColumn($query, $columnIndex),
            ['columnIndex' => $columnIndex],
        );
    }

    public function getLastInsertId(): string
    {
        return $this->executor->getLastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->executor->inTransaction()) {
            return $callback($this);
        }

        $this->managesTransaction = true;
        $this->dirty = false;

        try {
            $result = $this->executor->transaction(
                fn (QueryExecutorInterface $_): mixed => $callback($this),
            );

            $this->clearDirtyCacheAfterCommit();
            return $result;
        } finally {
            $this->dirty = false;
            $this->managesTransaction = false;
        }
    }

    public function inTransaction(): bool
    {
        return $this->executor->inTransaction();
    }

    /**
     * @template T
     * @param callable():T $loader
     * @param array<string, mixed> $context
     * @return T
     */
    private function cachedRead(
        string $operation,
        QueryInterface $query,
        callable $loader,
        array $context = [],
    ): mixed {
        if ($this->executor->inTransaction()) {
            return $loader();
        }

        $key = $this->keyGenerator->keyFor($operation, $query->compile(), $context);
        $cached = $this->cache->get($key, $this->cacheMiss);

        if ($cached !== $this->cacheMiss) {
            return $cached;
        }

        $result = $loader();
        $this->cache->set($key, $result, $this->defaultTtl);
        return $result;
    }

    private function clearDirtyCacheAfterCommit(): void
    {
        if ($this->dirty) {
            $this->cache->clear();
        }

        $this->dirty = false;
    }
}
