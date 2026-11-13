<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #46: an admin action on a user must record WHICH user it affected. The rows held only the actor and the
 * action ("admin@x admin.user_reset_2fa success"), so an incident could not be reconstructed — unlike the
 * admin-realm twins, which pass the target email as the audit context. Every user-admin action, on the web and
 * on the admin API, now records the target; an edit also records what it changed (old → new).
 */
final class UserAdminAuditTargetTest extends WebTestCase
{
    use AuthenticationTestTrait;
    use TwoFactorTestTrait;

    private const ADMIN = 'audtarget-admin@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
        $this->cleanup();
        $this->createTestAdmin(self::ADMIN, 'Audit Target Admin', 'adminpass');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement("DELETE FROM invitations WHERE email LIKE 'audtarget-%@example.com'");
            $this->conn->executeStatement("DELETE FROM password_reset_tokens WHERE email LIKE 'audtarget-%@example.com'");
            $this->conn->executeStatement("DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'audtarget-%@example.com')");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'audtarget-%@example.com'");
            $this->conn->executeStatement('DELETE FROM admin_access_tokens WHERE admin_id IN (SELECT id FROM admin WHERE email = ?)', [self::ADMIN]);
            $this->conn->executeStatement('DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email = ?)', [self::ADMIN]);
            $this->conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [self::ADMIN]);
            $this->conn->executeStatement('DELETE FROM admin WHERE email = ?', [self::ADMIN]);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function context(string $action): ?string
    {
        $value = $this->conn->fetchOne(
            'SELECT context FROM audit_log WHERE actor = ? AND action = ? ORDER BY id DESC LIMIT 1',
            [self::ADMIN, $action]
        );
        self::assertNotFalse($value, "an $action row must have been written");

        return $value;
    }

    private function user(string $email): User
    {
        return $this->createTestUser($email, 'Target User', 'userpass');
    }

    private function postWithCsrf(string $uri, string $tokenId): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/' . $tokenId, 'planted-token');
        $session->save();
        $this->client->request('POST', $uri, ['_token' => 'planted-token']);
    }

    private function apiToken(): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => (int) $this->conn->fetchOne('SELECT id FROM admin WHERE email = ?', [self::ADMIN]),
            'name'       => 'Audit Target Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    /** @param array<string, mixed>|null $json */
    private function api(string $token, string $method, string $uri, ?array $json = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'application/json',
        ], $json !== null ? (string) json_encode($json) : null);
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode(), "$method $uri: " . $this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------------------------------------- web

    public function testWebUserActionsRecordTheTargetUser(): void
    {
        $this->loginAsAdmin(self::ADMIN);

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create User', [
            'email' => 'audtarget-created@example.com', 'name' => 'Created', 'password' => 'password123',
            'role' => 'ROLE_USER', 'status' => 'active',
        ]);
        self::assertSame('audtarget-created@example.com', $this->context('admin.user_create'));

        $target = $this->user('audtarget-web@example.com');
        $id = $target->getId();

        $this->postWithCsrf("/admin/users/$id/password-reset", "admin_user_password_reset_$id");
        self::assertSame('audtarget-web@example.com', $this->context('admin.user_password_reset'));

        $this->enableTwoFactor(self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $id), 'JBSWY3DPEHPK3PXP');
        $this->postWithCsrf("/admin/users/$id/reset-2fa", "admin_user_reset_2fa_$id");
        self::assertSame('audtarget-web@example.com', $this->context('admin.user_reset_2fa'));

        $this->postWithCsrf("/admin/users/$id/unlock", "admin_user_unlock_$id");
        self::assertSame('audtarget-web@example.com', $this->context('admin.user_unlock'));

        $this->postWithCsrf("/admin/users/$id/revoke-tokens", "admin_user_revoke_tokens_$id");
        self::assertSame('audtarget-web@example.com', $this->context('admin.user_revoke_tokens'));

        // An edit records the target AND what changed, old → new — an email change is the takeover step.
        $this->client->request('GET', "/admin/users/$id/edit");
        $this->client->submitForm('Save Changes', ['email' => 'audtarget-moved@example.com', 'status' => 'inactive']);
        self::assertSame(
            'audtarget-moved@example.com; email: audtarget-web@example.com → audtarget-moved@example.com; status: active → inactive',
            $this->context('admin.user_edit')
        );
        self::assertSame('audtarget-moved@example.com', $this->context('admin.user_deactivate'));

        $this->postWithCsrf("/admin/users/$id/delete", "admin_user_delete_$id");
        self::assertSame('audtarget-moved@example.com', $this->context('admin.user_delete'));

        $this->client->request('GET', '/admin/users/invite');
        $this->client->submitForm('Send Invitation', ['email' => 'audtarget-invited@example.com']);
        self::assertSame('audtarget-invited@example.com', $this->context('admin.user_invite'));

        $invitationId = (int) $this->conn->fetchOne("SELECT id FROM invitations WHERE email = 'audtarget-invited@example.com'");
        $this->postWithCsrf("/admin/users/invitations/$invitationId/resend", "admin_invite_resend_$invitationId");
        self::assertSame('audtarget-invited@example.com', $this->context('admin.user_invite_resend'));
    }

    public function testAnEditThatChangesNothingRecordsJustTheTarget(): void
    {
        $this->loginAsAdmin(self::ADMIN);
        $id = $this->user('audtarget-same@example.com')->getId();

        $this->client->request('GET', "/admin/users/$id/edit");
        $this->client->submitForm('Save Changes');

        self::assertSame('audtarget-same@example.com', $this->context('admin.user_edit'));
    }

    // ------------------------------------------------------------------------------------------------- API

    public function testAdminApiUserActionsRecordTheTargetUser(): void
    {
        $token = $this->apiToken();

        $this->api($token, 'POST', '/admin-api/users', ['email' => 'audtarget-apicreated@example.com', 'name' => 'Api', 'password' => 'password123']);
        self::assertSame('audtarget-apicreated@example.com', $this->context('admin.user_create'));

        $target = $this->user('audtarget-api@example.com');
        $id = $target->getId();

        $this->api($token, 'POST', "/admin-api/users/$id/deactivate");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_deactivate'));

        $this->api($token, 'POST', "/admin-api/users/$id/activate");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_activate'));

        $this->api($token, 'POST', "/admin-api/users/$id/force-logout");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_force_logout'));

        $this->api($token, 'POST', "/admin-api/users/$id/password-reset");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_password_reset'));

        $this->enableTwoFactor(self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $id), 'JBSWY3DPEHPK3PXP');
        $this->api($token, 'DELETE', "/admin-api/users/$id/2fa");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_reset_2fa'));

        $this->api($token, 'DELETE', "/admin-api/users/$id/tokens");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_revoke_tokens'));

        $this->api($token, 'POST', "/admin-api/users/$id/unlock");
        self::assertSame('audtarget-api@example.com', $this->context('admin.user_unlock'));

        $this->api($token, 'PATCH', "/admin-api/users/$id", ['email' => 'audtarget-apimoved@example.com']);
        self::assertSame('audtarget-apimoved@example.com; email: audtarget-api@example.com → audtarget-apimoved@example.com', $this->context('admin.user_edit'));

        $this->api($token, 'DELETE', "/admin-api/users/$id");
        self::assertSame('audtarget-apimoved@example.com', $this->context('admin.user_delete'));

        $this->api($token, 'POST', '/admin-api/invitations', ['email' => 'audtarget-apiinvited@example.com']);
        self::assertSame('audtarget-apiinvited@example.com', $this->context('admin.user_invite'));

        $invitationId = (int) $this->conn->fetchOne("SELECT id FROM invitations WHERE email = 'audtarget-apiinvited@example.com'");
        $this->api($token, 'POST', "/admin-api/invitations/$invitationId/resend");
        self::assertSame('audtarget-apiinvited@example.com', $this->context('admin.user_invite_resend'));
    }
}
