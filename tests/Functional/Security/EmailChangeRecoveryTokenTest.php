<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #23 (review C7 reopened through the email-change path): recovery tokens store only an email and are
 * resolved by email when used. Changing an account's email frees the old address, so a reset / magic link
 * still pending for it would work for whatever account is next given that address. An email change must kill
 * the outstanding tokens of the previous address — in both realms.
 */
final class EmailChangeRecoveryTokenTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement("DELETE FROM password_reset_tokens WHERE email LIKE 'emailchg-%@example.com'");
            $this->conn->executeStatement("DELETE FROM magic_link_tokens WHERE email LIKE 'emailchg-%@example.com'");
            $this->conn->executeStatement("DELETE FROM admin_password_reset_tokens WHERE email LIKE 'emailchg-%@example.com'");
            $this->conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'emailchg-%@example.com')");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'emailchg-%@example.com'");
            $this->conn->executeStatement("DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE 'emailchg-%@example.com')");
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'emailchg-%@example.com'");
            $this->conn->executeStatement("DELETE FROM audit_log WHERE actor LIKE 'emailchg-%@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function makeAdmin(string $email, array $roles): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Email Change Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $admin->setRoles($roles);
        $this->em->persist($admin);
        $this->em->flush();
        $id = (int) $admin->getId();
        $this->em->clear();

        return $id;
    }

    private function seedToken(string $table, string $email): void
    {
        $this->conn->insert($table, [
            'email'      => $email,
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function liveTokens(string $table, string $email): int
    {
        return (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE email = ? AND used_at IS NULL", [$email]);
    }

    public function testChangingAUsersEmailKillsTheOldAddressesRecoveryTokens(): void
    {
        $user = $this->createTestUser('emailchg-old@example.com', 'Mover', 'userpass');
        $this->seedToken('password_reset_tokens', 'emailchg-old@example.com');
        $this->seedToken('magic_link_tokens', 'emailchg-old@example.com');
        $this->seedToken('password_reset_tokens', 'emailchg-bystander@example.com');
        $this->makeAdmin('emailchg-admin@example.com', ['ROLE_ADMIN']);
        $this->loginAsAdmin('emailchg-admin@example.com');

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->client->submitForm('Save Changes', ['email' => 'emailchg-new@example.com']);
        self::assertResponseRedirects('/admin/users');

        self::assertSame(0, $this->liveTokens('password_reset_tokens', 'emailchg-old@example.com'), 'reset link to the old address must be dead');
        self::assertSame(0, $this->liveTokens('magic_link_tokens', 'emailchg-old@example.com'), 'magic link to the old address must be dead');
        self::assertSame(1, $this->liveTokens('password_reset_tokens', 'emailchg-bystander@example.com'), 'other addresses are untouched');
    }

    public function testSavingAUserWithoutChangingTheEmailKeepsItsTokens(): void
    {
        $user = $this->createTestUser('emailchg-same@example.com', 'Stayer', 'userpass');
        $this->seedToken('password_reset_tokens', 'emailchg-same@example.com');
        $this->makeAdmin('emailchg-admin@example.com', ['ROLE_ADMIN']);
        $this->loginAsAdmin('emailchg-admin@example.com');

        $this->client->request('GET', '/admin/users/' . $user->getId() . '/edit');
        $this->client->submitForm('Save Changes', ['name' => 'Renamed']);
        self::assertResponseRedirects('/admin/users');

        self::assertSame(1, $this->liveTokens('password_reset_tokens', 'emailchg-same@example.com'));
    }

    public function testChangingAnAdminsEmailKillsTheOldAddressesResetTokens(): void
    {
        $this->makeAdmin('emailchg-super@example.com', ['ROLE_SUPER_ADMIN']);
        $targetId = $this->makeAdmin('emailchg-target-old@example.com', ['ROLE_ADMIN']);
        $this->seedToken('admin_password_reset_tokens', 'emailchg-target-old@example.com');
        $this->loginAsAdmin('emailchg-super@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $targetId . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'emailchg-target-new@example.com',
            'name'   => 'Target',
            'role'   => 'ROLE_ADMIN',
            'status' => 'active',
        ]);
        self::assertResponseRedirects('/admin/superadmin/admins');

        self::assertSame(0, $this->liveTokens('admin_password_reset_tokens', 'emailchg-target-old@example.com'));
    }
}
