<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\QueryExecutorCache\CacheKeyGenerator;

final class CacheKeyGeneratorTest extends TestCase
{
    public function testKeyIsDeterministicAndPrefixed(): void
    {
        $generator = new CacheKeyGenerator('test_');
        $query = new CompiledQuery('SELECT :id', [':id' => 1], [':id' => null]);

        self::assertSame(
            $generator->keyFor('fetch', $query),
            $generator->keyFor('fetch', $query),
        );
        self::assertStringStartsWith('test_', $generator->keyFor('fetch', $query));
    }

    public function testKeyChangesWithParameterValuesAndTypes(): void
    {
        $generator = new CacheKeyGenerator();
        $first = new CompiledQuery('SELECT :id', [':id' => 1], []);
        $second = new CompiledQuery('SELECT :id', [':id' => 2], []);
        $third = new CompiledQuery(
            'SELECT :id',
            [':id' => 1],
            [':id' => \WilyCode\Database\Query\Enum\ParameterType::STRING],
        );
        self::assertNotSame($generator->keyFor('fetch', $first), $generator->keyFor('fetch', $second));
        self::assertNotSame($generator->keyFor('fetch', $first), $generator->keyFor('fetch', $third));
    }

    public function testKeyChangesWithOperationQueryOrContext(): void
    {
        $generator = new CacheKeyGenerator('test_');
        $query = new CompiledQuery('SELECT :id', [':id' => 1], [':id' => null]);

        $base = $generator->keyFor('fetch', $query);

        self::assertNotSame($base, $generator->keyFor('fetchAll', $query));
        self::assertNotSame(
            $base,
            $generator->keyFor('fetch', new CompiledQuery('SELECT :id + 1', [':id' => 1], [':id' => null])),
        );
        self::assertNotSame($base, $generator->keyFor('fetch', $query, ['columnIndex' => 1]));
    }
}
