<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Doctrine\SqlitePdoFactory;
use App\Service\ConfigService;
use App\Session\ConfigAwarePdoSessionHandler;
use App\Session\SessionTtlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * ADR-051 end-to-end: proves the resolved TTL actually reaches the `sessions` row, against a real
 * SQLite database. `sess_lifetime` holds an ABSOLUTE expiry (time() + ttl), and PdoSessionHandler
 * re-stamps it on every write — which is what makes the window slide on activity.
 */
final class ConfigAwarePdoSessionHandlerTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/ttl_' . bin2hex(random_bytes(6)) . '.db';
    }

    protected function tearDown(): void
    {
        // WAL mode (the app's connection baseline) leaves side files next to the database.
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dbFile . $suffix);
        }
    }

    /** @param array<string,string> $config */
    private function handler(array $config, bool $longSession): ConfigAwarePdoSessionHandler
    {
        $configService = new class ($config) extends ConfigService {
            /** @param array<string,string> $values */
            public function __construct(private array $values) {}

            public function getInt(string $key, int $default = 0): int
            {
                return isset($this->values[$key]) ? (int) $this->values[$key] : $default;
            }
        };

        $request = Request::create('/admin/dashboard');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        if ($longSession) {
            $session->set(SessionTtlResolver::LONG_SESSION_KEY, true);
        }
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        return new ConfigAwarePdoSessionHandler(
            // Built the way production builds it (ADR-061): the same factory, hence the same SQLite baseline.
            (new SqlitePdoFactory(sys_get_temp_dir(), 'test'))->create('sqlite:///' . $this->dbFile),
            new SessionTtlResolver($configService, $stack),
            ['db_table' => 'sessions', 'lock_mode' => 0],
        );
    }

    /** Writes one session and returns the absolute expiry stored for it. */
    private function writeAndReadExpiry(ConfigAwarePdoSessionHandler $handler, string $sid): int
    {
        $handler->createTable();
        $handler->open('', 'test');
        $handler->write($sid, 'payload');
        $handler->close();

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('SELECT sess_lifetime FROM sessions WHERE sess_id = :id');
        $stmt->execute(['id' => $sid]);

        return (int) $stmt->fetchColumn();
    }

    public function testBaselineSessionGetsTheIdleWindow(): void
    {
        $before = time();
        $expiry = $this->writeAndReadExpiry($this->handler([], false), 'baseline');

        // 3 hours, not PHP's 24-minute default.
        self::assertGreaterThanOrEqual($before + (180 * 60), $expiry);
        self::assertLessThanOrEqual(time() + (180 * 60) + 5, $expiry);
    }

    public function testRememberedSessionGetsTheLongWindow(): void
    {
        $before = time();
        $expiry = $this->writeAndReadExpiry($this->handler([], true), 'remembered');

        self::assertGreaterThanOrEqual($before + (21 * 86400), $expiry);
        self::assertLessThanOrEqual(time() + (21 * 86400) + 5, $expiry);
    }

    public function testConfiguredLifetimeIsHonoured(): void
    {
        $before = time();
        $expiry = $this->writeAndReadExpiry(
            $this->handler(['session.remember_me_lifetime_days' => '2'], true),
            'configured',
        );

        self::assertGreaterThanOrEqual($before + (2 * 86400), $expiry);
        self::assertLessThanOrEqual(time() + (2 * 86400) + 5, $expiry);
    }
}
