<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-110 (ADR-020): "deleting" a user or admin is a SOFT delete — set status='inactive',
 * never a physical row removal. The row and all its satellite history stay intact; the account
 * simply can no longer authenticate.
 */
final class SoftDeleteTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $adminToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement('DELETE FROM personal_access_tokens');
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'softdel-%@example.com'");
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'softdel-%@example.com'");
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Soft Delete User');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    private function makeAdmin(string $email, array $roles = ['ROLE_ADMIN']): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Soft Delete Admin');
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $this->em->persist($admin);
        $this->em->flush();
        $id = $admin->getId();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->find($id);
    }

    private function seedLoginHistory(int $userId): void
    {
        $this->conn->insert('login_history', [
            'user_id'    => $userId,
            'ip'         => '203.0.113.7',
            'user_agent' => 'SoftDeleteTest/1.0',
            'fingerprint' => hash('sha256', '203.0.113.7SoftDeleteTest/1.0'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }


    private function submitUserDeleteForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/delete"]')->form();
        $this->client->submit($form);
    }

    // AC1/AC3/AC4/AC6: admin-panel delete soft-deletes, preserves satellite rows, blocks login.
    public function testAdminPanelDeleteSoftDeletesUserAndPreservesSatellite(): void
    {
        $admin = $this->makeAdmin('softdel-admin@example.com');
        $user  = $this->makeUser('softdel-target@example.com');
        $userId = $user->getId();
        $this->seedLoginHistory($userId);

        $this->loginAsAdmin('softdel-admin@example.com');
        $this->submitUserDeleteForm($userId);
        $this->assertResponseRedirects('/admin/users');

        // AC1/AC5: row still present, status flipped to inactive (no new state, no removal).
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($userId);
        $this->assertNotNull($reloaded, 'Soft delete must keep the users row');
        $this->assertSame('inactive', $reloaded->getStatus());

        // AC3: the satellite login_history row is still joinable to the surviving user.
        $historyCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_history WHERE user_id = ?',
            [$userId]
        );
        $this->assertSame(1, $historyCount, 'Satellite history must survive a soft delete');

        // AC4: the soft-deleted user can no longer authenticate on the user firewall.
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'softdel-target@example.com',
            'password' => 'userpass',
        ]);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringNotContainsString('/dashboard', $location, 'Inactive user must not reach the dashboard');
    }

    // AC2/AC6: admin API delete soft-deletes (204 + row retained inactive).
    public function testApiDeleteSoftDeletesUser(): void
    {
        $admin = $this->makeAdmin('softdel-apiadmin@example.com');
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $admin->getId(),
            'name'       => 'Soft Delete API Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $user = $this->makeUser('softdel-apitarget@example.com');
        $userId = $user->getId();

        $this->client->request('DELETE', '/admin-api/users/' . $userId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
        ]);

        $this->assertResponseStatusCodeSame(204);

        $row = $this->conn->fetchAssociative('SELECT * FROM "user" WHERE id = ?', [$userId]);
        $this->assertNotFalse($row, 'Soft delete must keep the users row');
        $this->assertSame('inactive', $row['status']);
    }

    // AC7/AC8/AC9: deleting an admin soft-deletes and blocks admin-firewall login.
    public function testAdminDeleteSoftDeletesAdminAndBlocksLogin(): void
    {
        $this->makeAdmin('softdel-super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin('softdel-victim@example.com', ['ROLE_ADMIN']);
        $targetId = $target->getId();

        $this->loginAsAdmin('softdel-super@example.com', 'adminpass');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $targetId . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/superadmin/admins');

        // AC7/AC9: admin row retained, status inactive.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Admin::class)->find($targetId);
        $this->assertNotNull($reloaded, 'Soft delete must keep the admin row');
        $this->assertSame('inactive', $reloaded->getStatus());

        // AC8: the soft-deleted admin can no longer authenticate on the admin firewall.
        // Drop the superadmin session first so /admin/login renders the form instead of redirecting.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'softdel-victim@example.com',
            'password' => 'adminpass',
        ]);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringNotContainsString('/admin/dashboard', $location, 'Inactive admin must not reach the admin dashboard');
    }
}
