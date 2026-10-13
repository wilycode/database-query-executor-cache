<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache;

use WilyCode\Database\Query\CompiledQuery;

interface CacheKeyGeneratorInterface
{
    /** @param array<string, mixed> $context */
    public function keyFor(string $operation, CompiledQuery $query, array $context = []): string;
}
