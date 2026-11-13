<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUnlockTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);

        $this->cleanup();

        $admin = new Admin();
        $admin->setEmail('unlock-admin@example.com');
        $admin->setName('Unlock Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $admin->setRoles([]);
        $this->em->persist($admin);

        $user = new User();
        $user->setEmail('unlock-user@example.com');
        $user->setName('Unlock User');
        $user->setPassword(self::hashTestPassword('userpass'));
        $user->setStatus('active');
        $this->em->persist($user);

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
            $this->conn->executeStatement(
                "DELETE FROM account_lockouts WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'unlock-%@example.com')"
            );
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'unlock-%@example.com'");
            foreach (['unlock-admin@example.com'] as $email) {
                $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
                if ($admin) {
                    $this->em->remove($admin);
                    $this->em->flush();
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function getTestUser(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => 'unlock-user@example.com']);
    }

    private function lockUser(): void
    {
        // Seed a lockout in the auth-security-bundle satellite (account_lockouts), keyed by user_id
        // resolved from the email (FEATURE-144 / ADR-044).
        $futureTime = (new \DateTimeImmutable())->modify('+30 minutes')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'INSERT INTO account_lockouts (user_id, locked_until) SELECT id, ? FROM "user" WHERE email = ? '
            . 'ON CONFLICT(user_id) DO UPDATE SET locked_until = excluded.locked_until',
            [$futureTime, 'unlock-user@example.com']
        );
    }

    private function submitUnlockForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $userId . '/unlock"]')->form();
        $this->client->submit($form);
    }

    // AC1: Locked users display a 'Locked' indicator in the admin user list
    public function testLockedUserDisplaysLockedIndicatorInList(): void
    {
        $this->lockUser();

        $this->loginAsAdmin('unlock-admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.locked-indicator');
    }

    // AC2: Admin user detail page has an 'Unlock' action for locked users
    public function testUnlockActionAppearsForLockedUser(): void
    {
        $user = $this->getTestUser();
        $this->lockUser();

        $this->loginAsAdmin('unlock-admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/admin/users/' . $user->getId() . '/unlock"]');
    }

    // AC3: Clicking Unlock removes the lockout (the account_lockouts row is deleted)
    public function testClickingUnlockClearsTheLockout(): void
    {
        $user = $this->getTestUser();
        $this->lockUser();

        $this->loginAsAdmin('unlock-admin@example.com');
        $this->submitUnlockForm($user->getId());

        $this->assertResponseRedirects('/admin/users');

        $remaining = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_lockouts WHERE user_id = ?',
            [$user->getId()]
        );

        $this->assertSame(0, (int) $remaining, 'the lockout row must be gone after unlock');
    }

    // AC4: After unlocking, the user can log in again immediately
    public function testAfterUnlockUserCanLoginImmediately(): void
    {
        $user = $this->getTestUser();
        $this->lockUser();

        // Verify user is blocked before unlock
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'unlock-user@example.com',
            'password' => 'userpass',
        ]);
        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertSelectorExists('.error', 'Locked user should see an error on login page');

        // Unlock via admin
        $this->loginAsAdmin('unlock-admin@example.com');
        $this->submitUnlockForm($user->getId());
        $this->assertResponseRedirects('/admin/users');

        // Now user should be able to log in
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'unlock-user@example.com',
            'password' => 'userpass',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/dashboard', $location, 'Unlocked user should be redirected to /dashboard');
    }
}
