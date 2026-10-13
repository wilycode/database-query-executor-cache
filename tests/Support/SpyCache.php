<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Tiny in-memory PSR-16 cache with counters.
 *
 * Used for unit tests where we want to assert exact cache interactions.
 */
final class SpyCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $deleteCalls = 0;
    public int $clearCalls = 0;
    public int $hasCalls = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->getCalls++;
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->setCalls++;
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->deleteCalls++;
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->clearCalls++;
        $this->values = [];
        return true;
    }

    /** @param iterable<string> $keys */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get((string) $key, $default);
        }
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set((string) $k, $v, $ttl);
        }
        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->hasCalls++;
        return array_key_exists($key, $this->values);
    }
}
