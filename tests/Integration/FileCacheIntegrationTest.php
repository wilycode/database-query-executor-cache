<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorCache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\QueryExecutorCache\CachedQueryExecutor;
use WilyCode\FileCache\FileCache;

final class FileCacheIntegrationTest extends TestCase
{
    public function testItWorksWithWilyCodeFileCache(): void
    {
        $directory = sys_get_temp_dir() . '/wilycode_dbqex_' . bin2hex(random_bytes(6));
        $fetchCalls = 0;

        $database = $this->createMock(DatabaseInterface::class);
        $database->method('inTransaction')->willReturn(false);
        $database->method('fetch')->willReturnCallback(static function () use (&$fetchCalls): array {
            return ['n' => ++$fetchCalls];
        });

        $binder = $this->createMock(BinderInterface::class);
        $binder->method('bind')->willReturn(new BoundQuery('SELECT 1'));

        $query = $this->createMock(QueryInterface::class);
        $query->method('compile')->willReturn(new CompiledQuery('SELECT 1'));

        try {
            $inner = new QueryExecutor($database, $binder);
            $first = new CachedQueryExecutor($inner, new FileCache($directory), defaultTtl: 3600);
            $firstResult = $first->fetch($query);

            $second = new CachedQueryExecutor($inner, new FileCache($directory), defaultTtl: 3600);
            $secondResult = $second->fetch($query);

            self::assertSame($firstResult, $secondResult);
            self::assertSame(1, $fetchCalls, 'Second fetch should use the filesystem cache.');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir()
                ? rmdir($file->getPathname())
                : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
