<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\SqlitePragmaMiddleware;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use PHPUnit\Framework\TestCase;

/**
 * This app is SQLite-only (ADR-001 / ADR-013): a DATABASE_URL pointed at any other engine must fail
 * loudly at the first connection, not silently run without the standard PRAGMA baseline. Exercises
 * the guard directly against a fake driver/connection so the assertion holds without needing an
 * actual MySQL/PostgreSQL server to connect to.
 */
final class SqlitePragmaMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        $this->tempFiles = [];
    }

    public function testConnectingWithANonSqliteNativeConnectionThrows(): void
    {
        $fakeDriver = new class implements DriverInterface {
            public function connect(array $params): ConnectionInterface
            {
                return new class implements ConnectionInterface {
                    public function prepare(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function query(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function quote(string $value): string
                    {
                        return $value;
                    }

                    public function exec(string $sql): int|string
                    {
                        return 0;
                    }

                    public function lastInsertId(): int|string
                    {
                        return 0;
                    }

                    public function beginTransaction(): void
                    {
                    }

                    public function commit(): void
                    {
                    }

                    public function rollBack(): void
                    {
                    }

                    public function getServerVersion(): string
                    {
                        return '1.0';
                    }

                    /** Deliberately NOT a PDO — stands in for a non-SQLite driver's native handle. */
                    public function getNativeConnection(): object
                    {
                        return new \stdClass();
                    }
                };
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): never
            {
                throw new \LogicException('unused in this test');
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                throw new \LogicException('unused in this test');
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite-only');

        (new SqlitePragmaMiddleware())->wrap($fakeDriver)->connect([]);
    }

    public function testConnectingWithARealSqlitePdoConnectionAppliesThePragmasWithoutThrowing(): void
    {
        // WAL mode is a no-op on `:memory:` (SQLite silently keeps it in-memory journaling), so a
        // real temp file is needed to actually observe journal_mode flip to 'wal'.
        $path = tempnam(sys_get_temp_dir(), 'sqlite-pragma-test-');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;

        $fakeDriver = new class($path) implements DriverInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function connect(array $params): ConnectionInterface
            {
                $path = $this->path;

                return new class(new \PDO('sqlite:' . $path)) implements ConnectionInterface {
                    public function __construct(private readonly \PDO $pdo)
                    {
                    }

                    public function prepare(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function query(string $sql): never
                    {
                        throw new \LogicException('unused in this test');
                    }

                    public function quote(string $value): string
                    {
                        return $this->pdo->quote($value);
                    }

                    public function exec(string $sql): int|string
                    {
                        return $this->pdo->exec($sql);
                    }

                    public function lastInsertId(): int|string
                    {
                        return $this->pdo->lastInsertId();
                    }

                    public function beginTransaction(): void
                    {
                    }

                    public function commit(): void
                    {
                    }

                    public function rollBack(): void
                    {
                    }

                    public function getServerVersion(): string
                    {
                        return '3';
                    }

                    public function getNativeConnection(): \PDO
                    {
                        return $this->pdo;
                    }
                };
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): never
            {
                throw new \LogicException('unused in this test');
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                throw new \LogicException('unused in this test');
            }
        };

        $connection = (new SqlitePragmaMiddleware())->wrap($fakeDriver)->connect([]);
        $native = $connection->getNativeConnection();

        self::assertInstanceOf(\PDO::class, $native);
        self::assertSame('wal', strtolower((string) $native->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame(1, (int) $native->query('PRAGMA foreign_keys')->fetchColumn());
    }
}
