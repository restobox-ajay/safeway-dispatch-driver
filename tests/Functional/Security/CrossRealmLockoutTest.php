<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-100 / review C5 — a failed login on the ADMIN firewall must never lock (or throttle)
 * a USER account that happens to share the same email. Login attempts are realm-scoped
 * (ADR-021): admin-firewall failures live in their own realm and never touch the "user" table.
 */
final class CrossRealmLockoutTest extends WebTestCase
{
    private const SHARED_EMAIL = 'crossrealm@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->cleanup();

        // A User and an Admin sharing the exact same email — the cross-realm collision case.
        $user = new User();
        $user->setEmail(self::SHARED_EMAIL);
        $user->setName('Cross Realm User');
        $user->setPassword(password_hash('userpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);

        $admin = new Admin();
        $admin->setEmail(self::SHARED_EMAIL);
        $admin->setName('Cross Realm Admin');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement('DELETE FROM login_attempts');
            $this->conn->executeStatement(
                "DELETE FROM account_lockouts WHERE user_id IN (SELECT id FROM \"user\" WHERE email = '" . self::SHARED_EMAIL . "')"
            );
            $this->conn->executeStatement(
                "DELETE FROM config WHERE config_key IN ('lockout.max_attempts', 'lockout.duration_minutes', 'rate_limit.max_attempts')"
            );
            foreach ([User::class, Admin::class] as $class) {
                $entity = $this->em->getRepository($class)->findOneBy(['email' => self::SHARED_EMAIL]);
                if ($entity) {
                    $this->em->remove($entity);
                }
            }
            $this->em->flush();
            $this->em->clear();
        } catch (\Throwable) {
            // Best-effort — next setUp re-tries.
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [$key, $value]
        );
    }

    private function failedAdminLogin(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::SHARED_EMAIL,
            'password' => 'wrongpassword',
        ]);
    }

    private function lockedUntilForUser(): mixed
    {
        // Lockout now lives in the auth-security-bundle satellite (FEATURE-144 / ADR-044). Normalise the
        // "no lockout row" case (fetchOne returns false) to null so the assertNull() checks below hold.
        $value = $this->conn->fetchOne(
            'SELECT locked_until FROM account_lockouts WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            [self::SHARED_EMAIL]
        );

        return $value === false ? null : $value;
    }

    /**
     * AC1 + AC4: failed ADMIN logins for a shared email must not set the USER's locked_until,
     * even once the lockout threshold is exceeded.
     */
    public function testFailedAdminLoginsDoNotLockSharedUserAccount(): void
    {
        $this->setConfig('lockout.max_attempts', '3');
        $this->setConfig('lockout.duration_minutes', '15');

        // Well past the threshold on the admin firewall.
        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();

        $this->assertNull(
            $this->lockedUntilForUser(),
            'A failed admin-firewall login must never write the user table\'s locked_until'
        );

        // The admin-firewall failures were recorded, but only under the admin realm.
        $adminRealmCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM login_attempts WHERE realm = 'admin' AND email = ?",
            [self::SHARED_EMAIL]
        );
        $userRealmCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM login_attempts WHERE realm = 'user' AND email = ?",
            [self::SHARED_EMAIL]
        );
        $this->assertGreaterThanOrEqual(4, $adminRealmCount, 'admin failures must be recorded under the admin realm');
        $this->assertSame(0, $userRealmCount, 'admin failures must not be recorded under the user realm');
    }

    /**
     * AC1/AC4 (positive path): after those admin failures the USER can still log in with the
     * correct password — the account was never locked.
     */
    public function testSharedUserCanStillLoginAfterFailedAdminLogins(): void
    {
        $this->setConfig('lockout.max_attempts', '3');

        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::SHARED_EMAIL,
            'password' => 'userpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/dashboard',
            (string) $this->client->getResponse()->headers->get('Location'),
            'The shared user must log in normally — admin failures must not throttle or lock them'
        );
    }

    /**
     * AC2: admin-realm failures must not count toward the USER firewall's per-account throttle.
     * With rate_limit.max_attempts=3, four admin failures would trip the throttle if counts were
     * shared; a subsequent user login with the correct password must still succeed.
     */
    public function testAdminFailuresDoNotThrottleUserLogin(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');

        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();
        $this->failedAdminLogin();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::SHARED_EMAIL,
            'password' => 'userpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/dashboard',
            (string) $this->client->getResponse()->headers->get('Location'),
            'Admin-realm failures must not throttle the user firewall for the same email'
        );
    }
}
