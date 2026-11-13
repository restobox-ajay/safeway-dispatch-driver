<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-099 (review C4): every mutating admin-API endpoint must write an audit_log row
 * recording the acting admin, actorType 'admin', ip, action, and outcome.
 */
final class AdminApiAuditLoggingTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'apiauditadmin@example.com';

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
        $this->adminToken = $this->createAdminToken();
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
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'apiaudit%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apiaudit%@example.com'");
            $this->conn->executeStatement("DELETE FROM invitations WHERE email LIKE 'apiaudit%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement('DELETE FROM user_sessions');
            $this->conn->executeStatement('DELETE FROM password_reset_tokens');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setName('API Audit Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne('SELECT id FROM admin WHERE email = ?', [self::ADMIN_EMAIL]);

        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createUserFixture(string $email, string $status = 'active'): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Audit Test User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    private function createInvitationFixture(string $email): int
    {
        $invitation = new Invitation($email, hash('sha256', bin2hex(random_bytes(32))), new \DateTimeImmutable('+7 days'));
        $this->em->persist($invitation);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne('SELECT id FROM invitations WHERE email = ?', [$email]);
    }

    private function request(string $method, string $url, array $body = []): void
    {
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
            'CONTENT_TYPE'       => 'application/json',
        ];
        $this->client->request($method, $url, [], [], $headers, $body === [] ? null : json_encode($body));
    }

    private function assertAuditRow(string $action): void
    {
        $row = $this->conn->fetchAssociative(
            'SELECT * FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1',
            [$action]
        );

        $this->assertNotFalse($row, "Expected an audit_log row for action '$action'");
        $this->assertSame(self::ADMIN_EMAIL, $row['actor'], "audit_log.actor must be the acting admin for '$action'");
        $this->assertSame('admin', $row['actor_type'], "audit_log.actor_type must be 'admin' for '$action'");
        $this->assertSame('success', $row['outcome'], "audit_log.outcome must be 'success' for '$action'");
        $this->assertNotEmpty($row['ip'], "audit_log.ip must be recorded for '$action'");
    }

    public function testCreateWritesAuditRow(): void
    {
        $this->request('POST', '/admin-api/users', [
            'email'    => 'apiauditcreate@example.com',
            'name'     => 'Created',
            'password' => 'Str0ng!Passw0rd',
            'status'   => 'active',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertAuditRow('admin.user_create');
    }

    public function testUpdateWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditupdate@example.com');
        $this->request('PATCH', '/admin-api/users/' . $userId, ['name' => 'Renamed']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_edit');
    }

    public function testDeleteWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditdelete@example.com');
        $this->request('DELETE', '/admin-api/users/' . $userId);
        $this->assertResponseStatusCodeSame(204);
        $this->assertAuditRow('admin.user_delete');
    }

    public function testActivateWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditactivate@example.com', 'inactive');
        $this->request('POST', '/admin-api/users/' . $userId . '/activate');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_activate');
    }

    public function testDeactivateWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditdeactivate@example.com');
        $this->request('POST', '/admin-api/users/' . $userId . '/deactivate');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_deactivate');
    }

    public function testForceLogoutWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditforcelogout@example.com');
        $this->request('POST', '/admin-api/users/' . $userId . '/force-logout');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_force_logout');
    }

    public function testPasswordResetWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditpwreset@example.com');
        $this->request('POST', '/admin-api/users/' . $userId . '/password-reset');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_password_reset');
    }

    public function testUnlockWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditunlock@example.com');
        $this->request('POST', '/admin-api/users/' . $userId . '/unlock');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_unlock');
    }

    public function testRevokeTokensWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiauditrevoke@example.com');
        $this->request('DELETE', '/admin-api/users/' . $userId . '/tokens');
        $this->assertResponseStatusCodeSame(204);
        $this->assertAuditRow('admin.user_revoke_tokens');
    }

    public function testResetTwoFactorWritesAuditRow(): void
    {
        $userId = $this->createUserFixture('apiaudit2fa@example.com');
        $this->request('DELETE', '/admin-api/users/' . $userId . '/2fa');
        $this->assertResponseStatusCodeSame(204);
        $this->assertAuditRow('admin.user_reset_2fa');
    }

    public function testInvitationSendWritesAuditRow(): void
    {
        $this->request('POST', '/admin-api/invitations', ['email' => 'apiauditinvite@example.com']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertAuditRow('admin.user_invite');
    }

    public function testInvitationResendWritesAuditRow(): void
    {
        $invitationId = $this->createInvitationFixture('apiauditresend@example.com');
        $this->request('POST', '/admin-api/invitations/' . $invitationId . '/resend');
        $this->assertResponseStatusCodeSame(200);
        $this->assertAuditRow('admin.user_invite_resend');
    }

    public function testNoAuditRowOnFailedMutation(): void
    {
        // A 404 (unknown user) must not write an audit row.
        $this->request('POST', '/admin-api/users/999999/deactivate');
        $this->assertResponseStatusCodeSame(404);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log');
        $this->assertSame(0, $count, 'A failed (404) mutation must not write an audit_log row');
    }
}
