<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache;

use WilyCode\Database\Query\CompiledQuery;

final readonly class CacheKeyGenerator implements CacheKeyGeneratorInterface
{
    public function __construct(private string $prefix = 'db_query_') {}

    public function keyFor(string $operation, CompiledQuery $query, array $context = []): string
    {
        return $this->prefix . hash('sha256', serialize([
            'operation' => $operation,
            'sql' => $query->sql,
            'params' => $query->params,
            'types' => $query->types,
            'context' => $context,
        ]));
    }
}
