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
 * FEATURE-102 (review C7 / ADR-020): deactivating OR soft-deleting an account must immediately kill
 * ALL of its outstanding recovery artefacts (password-reset + magic-link tokens), and the reset
 * consumption flow must reject a token whose account is inactive — both realms (user and admin).
 */
final class RecoveryTokenInvalidationTest extends WebTestCase
{
    private const PREFIX = 'rectok-';

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
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $like = self::PREFIX . '%@example.com';
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement('DELETE FROM password_reset_tokens WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM admin_password_reset_tokens WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM magic_link_tokens WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);
            $this->conn->executeStatement('DELETE FROM admin WHERE email LIKE ?', [$like]);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function makeUser(string $email, string $status = 'active'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Recovery Token User');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    private function makeAdmin(string $email, array $roles = ['ROLE_ADMIN'], string $status = 'active'): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Recovery Token Admin');
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $admin->setStatus($status);
        $this->em->persist($admin);
        $this->em->flush();
        $id = $admin->getId();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->find($id);
    }

    /** @return string plaintext token */
    private function seedToken(string $table, string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert($table, [
            'email'      => $email,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function tokenUsedAt(string $table, string $email): ?string
    {
        $value = $this->conn->fetchOne('SELECT used_at FROM ' . $table . ' WHERE email = ?', [$email]);

        return $value === false ? null : ($value === null ? null : (string) $value);
    }

    private function makeAdminApiToken(Admin $admin): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $admin->getId(),
            'name'       => 'Recovery Token API',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }


    // AC1/AC5: deactivating a user (API) kills its outstanding password-reset token.
    public function testDeactivatingUserInvalidatesPasswordResetToken(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'apiadmin@example.com');
        $token = $this->makeAdminApiToken($admin);
        $user  = $this->makeUser(self::PREFIX . 'target@example.com');
        $email = $user->getEmail();
        $this->seedToken('password_reset_tokens', $email);

        $this->assertNull($this->tokenUsedAt('password_reset_tokens', $email), 'precondition: token unused');

        $this->client->request('POST', '/admin-api/users/' . $user->getId() . '/deactivate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Deactivation must mark the outstanding reset token as used'
        );
    }

    // AC7: deactivating a user (API) also kills its outstanding magic-link token.
    public function testDeactivatingUserInvalidatesMagicLinkToken(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'apiadmin2@example.com');
        $token = $this->makeAdminApiToken($admin);
        $user  = $this->makeUser(self::PREFIX . 'magic@example.com');
        $email = $user->getEmail();
        $this->seedToken('magic_link_tokens', $email);

        $this->client->request('POST', '/admin-api/users/' . $user->getId() . '/deactivate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull(
            $this->tokenUsedAt('magic_link_tokens', $email),
            'Deactivation must mark the outstanding magic-link token as used'
        );
    }

    // AC8: soft-deleting a user (admin panel) kills its outstanding reset token.
    public function testSoftDeleteUserInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'paneladmin@example.com');
        $user  = $this->makeUser(self::PREFIX . 'deltarget@example.com');
        $email = $user->getEmail();
        $userId = $user->getId();
        $this->seedToken('password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'paneladmin@example.com');

        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $userId . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/users');

        $this->assertNotNull(
            $this->tokenUsedAt('password_reset_tokens', $email),
            'Soft delete must mark the outstanding reset token as used'
        );
    }

    // AC3: the user reset-consumption flow rejects a token whose account is inactive.
    public function testResetConsumptionRejectedForInactiveUser(): void
    {
        $user  = $this->makeUser(self::PREFIX . 'inactive@example.com', 'inactive');
        $email = $user->getEmail();
        $plaintext = $this->seedToken('password_reset_tokens', $email);

        $originalHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        // GET the reset page: the deactivated account must be rejected, not shown the form.
        $crawler = $this->client->request('GET', '/reset-password/' . $plaintext);
        $this->assertStringContainsString('no longer valid', $crawler->text());

        // POST a new password anyway: it must NOT be applied.
        $this->client->request('POST', '/reset-password/' . $plaintext, ['password' => 'BrandNewPass123!']);
        $newHash = (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [$email]);

        $this->assertSame($originalHash, $newHash, 'Inactive account password must not change via reset');
    }

    // AC2/AC6: deactivating an admin (edit form -> inactive) kills its admin reset token.
    public function testDeactivatingAdminInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'super@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin(self::PREFIX . 'edittarget@example.com', ['ROLE_ADMIN']);
        $email  = $target->getEmail();
        $this->seedToken('admin_password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'super@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/edit');
        $form = $crawler->filter('form')->form();
        $form['status'] = 'inactive';
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/superadmin/admins');

        $this->assertNotNull(
            $this->tokenUsedAt('admin_password_reset_tokens', $email),
            'Deactivating an admin must mark its outstanding reset token as used'
        );
    }

    // AC8: soft-deleting an admin (delete) kills its admin reset token.
    public function testSoftDeleteAdminInvalidatesResetToken(): void
    {
        $this->makeAdmin(self::PREFIX . 'super2@example.com', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin(self::PREFIX . 'deladmin@example.com', ['ROLE_ADMIN']);
        $email  = $target->getEmail();
        $this->seedToken('admin_password_reset_tokens', $email);

        $this->loginAsAdmin(self::PREFIX . 'super2@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form = $crawler->filter('form[action$="/admins/' . $target->getId() . '/delete"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/superadmin/admins');

        $this->assertNotNull(
            $this->tokenUsedAt('admin_password_reset_tokens', $email),
            'Soft-deleting an admin must mark its outstanding reset token as used'
        );
    }

    // AC4: the admin reset-consumption flow rejects a token whose admin account is inactive.
    public function testAdminResetConsumptionRejectedForInactiveAdmin(): void
    {
        $admin = $this->makeAdmin(self::PREFIX . 'inactiveadmin@example.com', ['ROLE_ADMIN'], 'inactive');
        $email = $admin->getEmail();
        $plaintext = $this->seedToken('admin_password_reset_tokens', $email);

        $originalHash = (string) $this->conn->fetchOne('SELECT password FROM admin WHERE email = ?', [$email]);

        $crawler = $this->client->request('GET', '/admin/reset-password/' . $plaintext);
        $this->assertStringContainsString('no longer valid', $crawler->text());

        $this->client->request('POST', '/admin/reset-password/' . $plaintext, ['password' => 'BrandNewPass123!']);
        $newHash = (string) $this->conn->fetchOne('SELECT password FROM admin WHERE email = ?', [$email]);

        $this->assertSame($originalHash, $newHash, 'Inactive admin password must not change via reset');
    }
}
