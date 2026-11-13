<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-106 / review C10 — the flush() footguns.
 *
 * Guards the audit-write contract that {@see AuditLogTest} (happy-path, exactly-one-row) does not:
 *  - a durable log() must NOT commit the whole unit of work (no collateral commit — AC4/AC1);
 *  - a deferred audit persisted inside a business transaction rolls back WITH it (AC2/AC6);
 *  - a login failure is still recorded via the independent durable write (AC3/AC7).
 */
final class AuditFlushFootgunTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        $this->client         = static::createClient();
        $this->em             = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn           = $this->em->getConnection();
        $this->userRepository = self::getContainer()->get(UserRepository::class);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $ids = $this->conn->fetchFirstColumn(
                "SELECT id FROM \"user\" WHERE email LIKE 'footgun-%@example.com'"
            );
            foreach ($ids as $id) {
                $this->conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'footgun-%@example.com'");
            $this->conn->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'footgun-%' OR context LIKE 'footgun-%'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $name): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->userRepository->findByEmail($email);
    }

    // AC1/AC4: an independent durable log() write must not flush the unit of work, so an unrelated
    // entity dirtied earlier in the request is NOT silently committed as a side effect of logging.
    public function testLoggingDoesNotCommitUnrelatedDirtyEntity(): void
    {
        $this->createUser('footgun-collateral@example.com', 'Original Name');

        // Load the user as a managed entity and dirty it in memory WITHOUT flushing.
        $managed = $this->userRepository->findByEmail('footgun-collateral@example.com');
        $managed->setName('DIRTY_UNCOMMITTED');

        // An unrelated audit write happens (as a login/security listener would do mid-request).
        self::getContainer()->get(AuditLogger::class)
            ->log('footgun-actor@example.com', 'user', '127.0.0.1', 'login', 'success');

        // Drop the in-memory change; if log() had flushed the UoW it would already be in the DB.
        $this->em->clear();

        $reloaded = $this->userRepository->findByEmail('footgun-collateral@example.com');
        $this->assertSame(
            'Original Name',
            $reloaded->getName(),
            'log() must not commit an unrelated dirty entity via a whole-unit-of-work flush (AC4).'
        );

        // The audit row itself WAS written (the independent durable write did its job).
        $this->assertSame(
            1,
            (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE actor = 'footgun-actor@example.com' AND action = 'login'"
            )
        );
    }

    // AC2/AC6: a deferred audit persisted inside a business transaction that later fails leaves NO
    // 'success' audit row (and no business row) — both or neither.
    public function testBusinessRollbackLeavesNoSuccessAuditRow(): void
    {
        $email       = 'footgun-rollback@example.com';
        $auditLogger = self::getContainer()->get(AuditLogger::class);
        $caught      = false;

        try {
            $this->em->wrapInTransaction(function () use ($auditLogger, $email): void {
                $user = new User();
                $user->setEmail($email);
                $user->setName('Rollback User');
                $user->setPassword(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));
                $user->setStatus('active');
                $this->em->persist($user);

                $auditLogger->logDeferred('footgun-admin@example.com', 'admin', '127.0.0.1', 'admin.user_create', 'success', $email);

                // Both rows are now written INSIDE the open transaction...
                $this->em->flush();

                // ...then the business step fails, so the whole transaction must roll back.
                throw new \RuntimeException('forced failure after audit persist');
            });
        } catch (\RuntimeException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'The forced failure should propagate.');
        $this->em->clear();

        $this->assertNull(
            $this->userRepository->findByEmail($email),
            'The business change must have rolled back.'
        );
        $this->assertSame(
            0,
            (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.user_create' AND context = :ctx",
                ['ctx' => $email]
            ),
            'No success audit row may survive a rolled-back business change (AC2/AC6).'
        );
    }

    // AC3/AC7: a login FAILURE is recorded via the independent durable write even though no business
    // change succeeded.
    public function testLoginFailureStillWritesAuditRow(): void
    {
        $this->createUser('footgun-user@example.com', 'Footgun User');
        $this->conn->executeStatement('DELETE FROM audit_log');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'footgun-user@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertGreaterThanOrEqual(
            1,
            (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND outcome = 'failure' AND actor = 'footgun-user@example.com'"
            ),
            'A failed login must still be audited (AC7).'
        );
    }
}
