<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\SqliteConnectionBaseline;
use App\Doctrine\SqlitePdoFactory;
use PHPUnit\Framework\TestCase;

/**
 * The session handler opens its own PDO (PdoSessionHandler does not use Doctrine), so the DBAL pragma
 * middleware never reaches it. This factory is what gives that connection the same baseline (ADR-061).
 */
final class SqlitePdoFactoryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sqlite-pdo-factory-') ?: self::fail('no temp file');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
    }

    private function factory(): SqlitePdoFactory
    {
        return new SqlitePdoFactory('/srv/app', 'prod');
    }

    public function testTheConnectionGetsTheFullBaseline(): void
    {
        $pdo = $this->factory()->create('sqlite:///' . $this->file);

        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn(), "tighter than PHP's 60 s default");
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame('normal', strtolower((string) $pdo->query('PRAGMA locking_mode')->fetchColumn()));
        self::assertSame(2, (int) $pdo->query('PRAGMA synchronous')->fetchColumn(), '2 = FULL');
    }

    public function testItThrowsOnErrorsBecausePdoSessionHandlerRequiresThat(): void
    {
        $pdo = $this->factory()->create('sqlite:///' . $this->file);

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testItOpensTheFileNamedByDatabaseUrl(): void
    {
        $pdo = $this->factory()->create('sqlite:///' . $this->file . '?cache=shared');

        $pdo->exec('CREATE TABLE probe (id INTEGER)');
        $files = array_column($pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC), 'file');

        self::assertSame([realpath($this->file)], array_map('realpath', $files));
    }

    public function testTheBaselineListIsExactlyTheFivePragmasAndAllOfThemAreApplied(): void
    {
        self::assertSame(
            ['journal_mode = WAL', 'busy_timeout = 5000', 'foreign_keys = ON', 'locking_mode = NORMAL', 'synchronous = FULL'],
            array_map(static fn (string $p): string => substr($p, \strlen('PRAGMA ')), SqliteConnectionBaseline::PRAGMAS),
        );

        $pdo = new \PDO('sqlite:' . $this->file);
        SqliteConnectionBaseline::applyTo($pdo);

        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    /** @return array<string,array{string}> */
    public static function notASqliteFile(): array
    {
        return [
            'mysql' => ['mysql://app:pw@127.0.0.1:3306/app'],
            'postgres' => ['postgresql://app:pw@127.0.0.1:5432/app'],
            'in-memory' => ['sqlite:///:memory:'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notASqliteFile')]
    public function testAnythingButAFileBackedSqliteUrlIsRefused(string $url): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite-only');

        $this->factory()->create($url);
    }
}
