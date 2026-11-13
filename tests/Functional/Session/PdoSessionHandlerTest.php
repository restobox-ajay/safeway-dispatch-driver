<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerTest extends KernelTestCase
{
    private const SHIPPED_DEFAULT = '@.env';

    /**
     * Regression tests for a long-standing bug and its first (wrong) fix (ADR-061). `app.session.dsn` was
     * hardcoded to `var/data.db`, a file DATABASE_URL never named, so in dev/prod the session handler
     * silently read/wrote a stray file while Doctrine used the real one. An interim fix derived
     * `var/data_<env>.db` from the environment name — right by default, but wrong the moment an operator
     * points DATABASE_URL at persistent storage (which the README tells them to do): sessions would go to a
     * file the migrations never touched. The only invariant that cannot drift is that the session DSN IS
     * DATABASE_URL, so that is what is asserted — for the default AND a custom URL, in dev AND prod. The
     * real kernels are booted (bootKernel() gives the `test` one, whose own override hid the original bug).
     *
     * @return iterable<string,array{string,string}>
     */
    public static function environmentsAndDatabaseUrls(): iterable
    {
        foreach (['dev', 'prod'] as $environment) {
            yield "$environment, the shipped default from .env" => [$environment, self::SHIPPED_DEFAULT];
            yield "$environment, custom DATABASE_URL on persistent storage" => [$environment, 'sqlite:////srv/persistent/app-data.db'];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('environmentsAndDatabaseUrls')]
    public function testSessionDsnIsDatabaseUrlSoSessionsAndDataShareOneFile(string $environment, string $urlCase): void
    {
        // Inside PHPUnit .env.test is already in effect, so "the shipped default" must be loaded from .env
        // itself and put in force explicitly — otherwise a dev/prod kernel would just see the test database.
        $customUrl = $urlCase === self::SHIPPED_DEFAULT ? null : $urlCase;
        $databaseUrl = $customUrl ?? (new \Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents(\dirname(__DIR__, 3) . '/.env'))['DATABASE_URL'];

        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null, getenv('DATABASE_URL')];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $databaseUrl;
        putenv('DATABASE_URL=' . $databaseUrl);

        $kernel = new Kernel($environment, true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $projectDir = (string) $container->getParameter('kernel.project_dir');
            $sessionDsn = (string) $container->getParameter('app.session.dsn');

            // What file does each side mean? (ConsoleCookie::sqlitePath is the repo's single DATABASE_URL-to-path
            // resolver, used by the db-console gateway for the same "same file as the app" guarantee.)
            $expected = \App\Security\ConsoleCookie::sqlitePath($databaseUrl, $projectDir, $environment);
            $actual = \App\Security\ConsoleCookie::sqlitePath($sessionDsn, $projectDir, $environment);

            self::assertNotNull($expected, 'DATABASE_URL must be a SQLite file URL (the app is SQLite-only).');
            self::assertSame($expected, $actual, "In '$environment' the session handler must use the SAME SQLite file as DATABASE_URL.");
            if ($customUrl !== null) {
                self::assertSame('/srv/persistent/app-data.db', $actual, 'a custom DATABASE_URL must be honoured by the session handler too');
            } else {
                self::assertSame("$projectDir/var/data_$environment.db", $actual);
            }
        } finally {
            $kernel->shutdown();
            $this->restoreDatabaseUrl($previous);
        }
    }

    /** @param array{0:mixed,1:mixed,2:string|false} $previous what $_SERVER / $_ENV / getenv() held before */
    private function restoreDatabaseUrl(array $previous): void
    {
        [$server, $env, $getenv] = $previous;

        if ($server === null) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $server;
        }
        if ($env === null) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $env;
        }
        putenv($getenv === false ? 'DATABASE_URL' : 'DATABASE_URL=' . $getenv);
    }

    public function testPdoSessionHandlerAcceptsTheDatabaseUrlFormAndWritesToThatFile(): void
    {
        // The handler is handed DATABASE_URL verbatim ("sqlite:///<path>", DBAL URL style). Prove Symfony's
        // handler really parses that form and lands in that file — not merely that the parameter looks right.
        $file = tempnam(sys_get_temp_dir(), 'sess-url-test-');
        self::assertNotFalse($file);

        try {
            $handler = new PdoSessionHandler('sqlite:///' . $file, ['db_table' => 'sessions']);
            $handler->createTable();
            $handler->open('', 'PHPSESSID');
            $handler->write('abc123', 'payload-xyz');
            $handler->close();

            $pdo = new \PDO('sqlite:' . $file);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE sess_id = 'abc123'")->fetchColumn());
        } finally {
            @unlink($file);
        }
    }

    /**
     * PdoSessionHandler opens its OWN PDO — outside Doctrine — so Doctrine's pragma middleware never reaches
     * it (ADR-061). Compare the live session connection with Doctrine's: every baseline pragma must read back
     * the same, and both must be looking at the same database file.
     */
    public function testTheSessionConnectionHasTheSameSqliteBaselineAsDoctrineAndTheSameFile(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);
        /** @var \PDO $sessionPdo */
        $sessionPdo = (new \ReflectionProperty(PdoSessionHandler::class, 'pdo'))->getValue($handler);
        self::assertInstanceOf(\PDO::class, $sessionPdo, 'the session handler must have been handed a connection');

        /** @var \Doctrine\DBAL\Connection $doctrine */
        $doctrine = self::getContainer()->get('doctrine.dbal.default_connection');

        foreach (['journal_mode', 'busy_timeout', 'foreign_keys', 'locking_mode', 'synchronous'] as $pragma) {
            self::assertEquals(
                $doctrine->fetchOne("PRAGMA $pragma"),
                $sessionPdo->query("PRAGMA $pragma")->fetchColumn(),
                "PRAGMA $pragma differs between the session connection and Doctrine's.",
            );
        }
        self::assertSame(5000, (int) $sessionPdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame(1, (int) $sessionPdo->query('PRAGMA foreign_keys')->fetchColumn());

        $sessionFile = $sessionPdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC)[0]['file'];
        $doctrineFile = $doctrine->fetchAllAssociative('PRAGMA database_list')[0]['file'];
        self::assertSame(realpath($doctrineFile), realpath($sessionFile), 'sessions and data must live in one SQLite file');
    }

    /**
     * With the default LOCK_TRANSACTIONAL the session connection holds a SQLite write lock (BEGIN IMMEDIATE) for
     * the whole request, so Doctrine's own writes in that request — login_history, audit_log — queue behind it
     * on a second connection to the same file until busy_timeout expires, then fail ("database is locked").
     * Reproduced with a real admin login: ~8 s, then a 500. That only went unnoticed in production while sessions
     * wrongly lived in a separate stray file (ADR-061). There is ONE handler definition for every environment
     * (no dev/acceptance override left), so pinning it here pins production too.
     */
    public function testSessionHandlerUsesNoRowLockSoSameRequestDoctrineWritesCannotDeadlockOnIt(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);

        $lockMode = (new \ReflectionProperty(PdoSessionHandler::class, 'lockMode'))->getValue($handler);

        self::assertSame(PdoSessionHandler::LOCK_NONE, $lockMode);
    }

    public function testPdoSessionHandlerIsRegistered(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(PdoSessionHandler::class);
        $this->assertInstanceOf(PdoSessionHandler::class, $handler);
    }

    public function testSessionsTableExists(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'"
        )->fetchOne();
        $this->assertSame('sessions', $row, 'sessions table must exist after migrations');
    }

    public function testSessionsTableHasExpectedColumns(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $columns = $conn->executeQuery('PRAGMA table_info(sessions)')->fetchAllAssociative();
        $names = array_column($columns, 'name');

        $this->assertContains('sess_id', $names);
        $this->assertContains('sess_data', $names);
        $this->assertContains('sess_time', $names);
        $this->assertContains('sess_lifetime', $names);
    }

    public function testSessionsTableAcceptsInsertAndDelete(): void
    {
        self::bootKernel();
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        $testId = 'test_session_' . bin2hex(random_bytes(8));
        $conn->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$testId, 'data', time(), 3600]
        );

        $found = $conn->executeQuery('SELECT sess_id FROM sessions WHERE sess_id = ?', [$testId])->fetchOne();
        $this->assertSame($testId, $found);

        $conn->executeStatement('DELETE FROM sessions WHERE sess_id = ?', [$testId]);
        $gone = $conn->executeQuery('SELECT sess_id FROM sessions WHERE sess_id = ?', [$testId])->fetchOne();
        $this->assertFalse($gone);
    }
}
