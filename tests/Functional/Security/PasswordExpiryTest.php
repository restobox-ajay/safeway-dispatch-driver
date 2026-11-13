<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordExpiryTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key = 'password_policy.expiry_days'"
        );

        $this->removeTestUser();

        $user = new User();
        $user->setEmail('expirytest@example.com');
        $user->setName('Expiry Test');
        $user->setPassword(password_hash('CorrectPass1!', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $this->em->persist($user);
        $this->em->flush();
        // The change date lives in the auth-password-policy-bundle satellite (password_meta) now, not on
        // `user` (FEATURE-145). Seed it within the expiry window; individual tests age it via
        // setPasswordChangedAt().
        $this->conn->executeStatement(
            'INSERT INTO password_meta (user_id, password_changed_at) VALUES (?, ?)',
            [(int) $user->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key = 'password_policy.expiry_days'"
        );
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        $this->conn->executeStatement("DELETE FROM password_meta WHERE user_id IN (SELECT id FROM \"user\" WHERE email = 'expirytest_target@example.com')");
        $this->conn->executeStatement("DELETE FROM \"user\" WHERE email = 'expirytest_target@example.com'");
        $this->conn->executeStatement("DELETE FROM admin WHERE email = 'expirytest_admin@example.com'");

        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'expirytest@example.com']);
            if ($user !== null) {
                // Clear the satellite row first (unidirectional PasswordMeta -> User; the ORM does not
                // cascade from the User side).
                $this->conn->executeStatement('DELETE FROM password_meta WHERE user_id = ?', [(int) $user->getId()]);
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function setExpiryDays(int $days): void
    {
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('password_policy.expiry_days', ?)",
            [(string) $days]
        );
    }

    private function setPasswordChangedAt(string $relative): void
    {
        $date = (new \DateTimeImmutable())->modify($relative)->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE password_meta SET password_changed_at = ? '
            . 'WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            [$date, 'expirytest@example.com']
        );
    }

    // FEATURE-145: password_changed_at moved OFF `user` into the bundle-owned password_meta satellite.
    public function testPasswordChangedAtLivesOnTheSatelliteNotUser(): void
    {
        $userColumns = array_column($this->conn->fetchAllAssociative('PRAGMA table_info("user")'), 'name');
        $this->assertNotContains(
            'password_changed_at',
            $userColumns,
            'password_changed_at must NO LONGER be a column on the user table (moved to password_meta).'
        );

        $metaColumns = $this->conn->fetchAllAssociative('PRAGMA table_info("password_meta")');
        $metaNames = array_column($metaColumns, 'name');
        $this->assertContains('password_changed_at', $metaNames, 'password_meta must carry password_changed_at');
        $this->assertContains('user_id', $metaNames, 'password_meta must carry the user_id FK');

        $col = null;
        foreach ($metaColumns as $c) {
            if ($c['name'] === 'password_changed_at') {
                $col = $c;
                break;
            }
        }
        $this->assertNotNull($col);
        $this->assertSame('1', (string) $col['notnull'], 'password_meta.password_changed_at must be NOT NULL');
    }

    public function testExpiredPasswordRedirectsToForcedChangePage(): void
    {
        $this->setExpiryDays(30);
        $this->setPasswordChangedAt('-60 days');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'expirytest@example.com',
            'password' => 'CorrectPass1!',
        ]);

        // form_login redirects to /dashboard
        $this->assertResponseStatusCodeSame(302);

        // GET /dashboard — listener fires and redirects to forced change page
        $this->client->followRedirect();
        $this->assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('change-expired-password', $location);
    }

    // Security: the "impersonating" skip belongs to the impersonated user. An admin who impersonates one
    // user and then signs in as a user with an expired password in the same session must still be sent to
    // the forced change page.
    public function testImpersonationFlagDoesNotSkipTheExpiryCheckForAnotherUser(): void
    {
        $this->setExpiryDays(30);
        $this->setPasswordChangedAt('-60 days');
        $this->createTestAdmin('expirytest_admin@example.com');
        // The target's password is expired too, so this also proves impersonating it still skips ITS check.
        $target = $this->createTestUser('expirytest_target@example.com');
        $this->conn->executeStatement(
            'INSERT INTO password_meta (user_id, password_changed_at) VALUES (?, ?)',
            [(int) $target->getId(), (new \DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s')]
        );

        $this->loginAsAdmin('expirytest_admin@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action="/admin/users/' . $target->getId() . '/impersonate-start"]')->form());
        $this->client->followRedirect(); // /impersonate/start -> /dashboard
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
        $this->assertSelectorExists('.impersonation-banner');

        // Same session, still impersonating: the login form as the user whose password has expired.
        $this->client->request('POST', '/staff/login', ['email' => 'expirytest@example.com', 'password' => 'CorrectPass1!']);
        $this->assertResponseRedirects('/dashboard', null, 'the expired user password is accepted');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('change-expired-password', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAfterSettingNewPasswordUserProceedsNormally(): void
    {
        $this->setExpiryDays(30);
        $this->setPasswordChangedAt('-60 days');

        // Log in
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'expirytest@example.com',
            'password' => 'CorrectPass1!',
        ]);
        $this->client->followRedirect(); // GET /dashboard
        $this->client->followRedirect(); // GET /account/change-expired-password

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Password Expired', (string) $this->client->getResponse()->getContent());

        // Submit valid new password (the forced-change form now requires the current password)
        $this->client->submitForm('Update Password', [
            'current_password' => 'CorrectPass1!',
            'password' => 'BrandNew2Pass!',
        ]);
        $this->assertResponseStatusCodeSame(302);

        // password_changed_at should be updated to a recent time (now on the password_meta satellite)
        $updatedAt = $this->conn->fetchOne(
            'SELECT password_changed_at FROM password_meta WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            ['expirytest@example.com']
        );
        $this->assertNotNull($updatedAt);
        $updatedDt = new \DateTimeImmutable((string) $updatedAt);
        $this->assertGreaterThan(
            (new \DateTimeImmutable())->modify('-10 seconds'),
            $updatedDt,
            'password_changed_at must be updated to a recent time after forced change'
        );

        // The forced change kept the session live (the token's user was mutated in-request),
        // so log out, then confirm the new (non-expired) password authenticates and no longer
        // triggers the expiry redirect.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/staff/login');
        $this->assertResponseIsSuccessful(); // login page

        $this->client->submitForm('Sign in', [
            'email'    => 'expirytest@example.com',
            'password' => 'BrandNew2Pass!',
        ]);
        $this->assertResponseStatusCodeSame(302); // redirect to /dashboard

        // GET /dashboard — no more expiry redirect
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testNonExpiredPasswordDoesNotRedirect(): void
    {
        $this->setExpiryDays(30);
        // password_changed_at is already set to now in setUp — well within 30 days

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'expirytest@example.com',
            'password' => 'CorrectPass1!',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $this->client->followRedirect(); // GET /dashboard
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('change-expired-password', (string) $this->client->getResponse()->getContent());
    }
}
