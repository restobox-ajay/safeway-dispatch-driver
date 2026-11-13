<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountLockoutTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->conn->executeStatement('DELETE FROM login_attempts');
        $this->conn->executeStatement('DELETE FROM account_lockouts');
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('lockout.max_attempts', 'lockout.duration_minutes')"
        );

        $this->removeTestUser();
        $user = new User();
        $user->setEmail('lockouttest@example.com');
        $user->setName('Lockout Test');
        $user->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        $this->conn->executeStatement('DELETE FROM login_attempts');
        $this->conn->executeStatement('DELETE FROM account_lockouts');
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('lockout.max_attempts', 'lockout.duration_minutes')"
        );
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'lockouttest@example.com']);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)",
            [$key, $value]
        );
    }

    private function failedLogin(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'lockouttest@example.com',
            'password' => 'wrongpassword',
        ]);
    }

    /**
     * Seed a lockout directly in the auth-security-bundle satellite (account_lockouts), keyed by user_id
     * resolved from the email — the modern equivalent of the old manual `UPDATE user SET locked_until`.
     */
    private function lockUserByEmail(string $email, string $lockedUntil): void
    {
        $this->conn->executeStatement(
            'INSERT INTO account_lockouts (user_id, locked_until) SELECT id, ? FROM "user" WHERE email = ? '
            . 'ON CONFLICT(user_id) DO UPDATE SET locked_until = excluded.locked_until',
            [$lockedUntil, $email]
        );
    }

    /** The satellite locked_until for the email, or null when no lockout row exists. */
    private function lockedUntilForEmail(string $email): ?string
    {
        $value = $this->conn->fetchOne(
            'SELECT locked_until FROM account_lockouts WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            [$email]
        );

        return $value === false ? null : (string) $value;
    }

    public function testLockedUntilRelocatedFromUserToAccountLockoutsSatellite(): void
    {
        // The lockout state moved off `user` into the auth-security-bundle satellite (FEATURE-144 /
        // ADR-044): core `user` no longer carries locked_until, and the bundle-owned account_lockouts
        // table holds it with a NOT NULL locked_until + a user_id FK column.
        $userColumns = array_column($this->conn->fetchAllAssociative('PRAGMA table_info("user")'), 'name');
        $this->assertNotContains('locked_until', $userColumns, 'locked_until must be dropped from the user table');

        $lockoutColumns = $this->conn->fetchAllAssociative('PRAGMA table_info("account_lockouts")');
        $this->assertNotEmpty($lockoutColumns, 'account_lockouts satellite table must exist');
        $lockoutColumnNames = array_column($lockoutColumns, 'name');
        $this->assertContains('locked_until', $lockoutColumnNames, 'account_lockouts must own the locked_until column');
        $this->assertContains('user_id', $lockoutColumnNames, 'account_lockouts must key on user_id');

        $lockedUntilCol = null;
        foreach ($lockoutColumns as $col) {
            if ($col['name'] === 'locked_until') {
                $lockedUntilCol = $col;
                break;
            }
        }
        $this->assertNotNull($lockedUntilCol);
        $this->assertSame('1', (string) $lockedUntilCol['notnull'], 'account_lockouts.locked_until is NOT NULL (a row exists only while locked)');
    }

    public function testAfterXFailedAttemptsLockedUntilIsSet(): void
    {
        $this->setConfig('lockout.max_attempts', '3');
        $this->setConfig('lockout.duration_minutes', '15');

        // 3 failed login attempts — 3rd should trigger lockout
        $this->failedLogin();
        $this->failedLogin();
        $this->failedLogin();

        $lockedUntil = $this->lockedUntilForEmail('lockouttest@example.com');

        $this->assertNotNull($lockedUntil, 'a lockout row must be written after X failed attempts');
        $this->assertNotFalse($lockedUntil);

        $lockedUntilDt = new \DateTimeImmutable($lockedUntil);
        $this->assertGreaterThan(
            new \DateTimeImmutable(),
            $lockedUntilDt,
            'locked_until must be in the future'
        );
    }

    /** Issue #26: a whitespace-padded email checks the real password, so its failures must lock the real account. */
    public function testWhitespacePaddedEmailFailuresLockTheRealAccount(): void
    {
        $this->setConfig('lockout.max_attempts', '3');
        $this->setConfig('lockout.duration_minutes', '15');

        foreach (['lockouttest@example.com ', "\tlockouttest@example.com", "lockouttest@example.com\n"] as $i => $padded) {
            $this->client->setServerParameter('REMOTE_ADDR', '10.3.0.' . ($i + 1));
            $this->client->request('GET', '/staff/login');
            $this->client->submitForm('Sign in', ['email' => $padded, 'password' => 'wrongpassword']);
        }

        $lockedUntil = $this->lockedUntilForEmail('lockouttest@example.com');
        $this->assertNotNull($lockedUntil, 'padding the email must not dodge the account lockout');
        $this->assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable($lockedUntil));
    }

    public function testLockedAccountShowsErrorWithRemainingTime(): void
    {
        // Manually lock the user via the satellite (bypassing the lockout trigger mechanism)
        $futureTime = (new \DateTimeImmutable())->modify('+30 minutes')->format('Y-m-d H:i:s');
        $this->lockUserByEmail('lockouttest@example.com', $futureTime);

        // Attempt login with correct credentials — should still be blocked
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'lockouttest@example.com',
            'password' => 'correctpassword',
        ]);
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('locked', $content);
        $this->assertStringContainsString('minute', $content);
    }

    public function testLockoutExpiresAutomatically(): void
    {
        // Seed an already-expired lockout row (past locked_until)
        $pastTime = (new \DateTimeImmutable())->modify('-1 minute')->format('Y-m-d H:i:s');
        $this->lockUserByEmail('lockouttest@example.com', $pastTime);

        // Login with correct credentials — should succeed because lockout has expired
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'lockouttest@example.com',
            'password' => 'correctpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/dashboard', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
