<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiInvitationTest extends WebTestCase
{
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
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'api%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apiinv%@example.com'");
            $this->conn->executeStatement("DELETE FROM invitations WHERE email LIKE 'apiinv%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement("DELETE FROM config WHERE config_key IN ('login_notifications.enabled', 'invitation.expiry_days')");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('apiinvadmin@example.com');
        $admin->setName('API Invitation Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'apiinvadmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => $hash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Disable login notifications so token-auth LoginSuccessEvent doesn't send extra emails
        $this->conn->insert('config', [
            'config_key'   => 'login_notifications.enabled',
            'config_value' => '0',
        ]);

        return $plaintext;
    }

    private function apiPost(string $url, ?array $body = null, ?string $token = null): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->adminToken)];
        if ($body !== null) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request('POST', $url, [], [], $headers, $body !== null ? (string) json_encode($body) : '');
    }

    // AC1: POST /admin-api/invitations sends an invitation and returns 201
    public function testSendInvitationReturns201(): void
    {
        $this->apiPost('/admin-api/invitations', ['email' => 'apiinvtarget@example.com']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertEmailCount(1);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('apiinvtarget@example.com', $data['email']);
        $this->assertArrayHasKey('expires_at', $data);

        $count = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM invitations WHERE email = 'apiinvtarget@example.com'"
        );
        $this->assertEquals(1, $count);
    }

    // AC2: POST /admin-api/invitations/{id}/resend generates a new token and returns 200
    public function testResendExpiredInvitationReturns200WithNewToken(): void
    {
        $oldTokenHash = hash('sha256', 'old-expired-token');
        $this->conn->insert('invitations', [
            'email'      => 'apiinvresend@example.com',
            'token_hash' => $oldTokenHash,
            'expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'),
        ]);
        $invId = (int) $this->conn->fetchOne(
            "SELECT id FROM invitations WHERE email = 'apiinvresend@example.com'"
        );

        $this->apiPost('/admin-api/invitations/' . $invId . '/resend');

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);

        $row = $this->conn->fetchAssociative('SELECT * FROM invitations WHERE id = ?', [$invId]);
        $this->assertNotEquals($oldTokenHash, $row['token_hash'], 'Token hash must be regenerated');
        $this->assertNull($row['used_at'], 'used_at must be null after resend');
        // expiry_days defaults to 7; expiry should be at least 1 hour in the future
        $this->assertGreaterThan(time() + 3600, strtotime($row['expires_at']), 'New expiry must be in the future');
    }

    // Resending a non-expired UNUSED invitation rotates the token (200). Expiry does not
    // block resend — web and API agree on a single guard (used-ness). See ADR-022 /
    // FEATURE-101: this reconciles the prior API-only "non-expired => 409" behavior, which
    // contradicted the web resend contract (FEATURE-071 AC5 rotates a non-expired invite).
    public function testResendNonExpiredUnusedInvitationRotatesToken(): void
    {
        $oldTokenHash = hash('sha256', 'not-expired-token');
        $this->conn->insert('invitations', [
            'email'      => 'apiinvnotexp@example.com',
            'token_hash' => $oldTokenHash,
            'expires_at' => (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $invId = (int) $this->conn->fetchOne(
            "SELECT id FROM invitations WHERE email = 'apiinvnotexp@example.com'"
        );

        $this->apiPost('/admin-api/invitations/' . $invId . '/resend');

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);

        $row = $this->conn->fetchAssociative('SELECT * FROM invitations WHERE id = ?', [$invId]);
        $this->assertNotEquals($oldTokenHash, $row['token_hash'], 'Token hash must be regenerated');
        $this->assertNull($row['used_at'], 'used_at must remain null after resend');
    }

    // C6 / AC1: resending an already-used invitation returns 409; token unchanged, no email
    public function testResendUsedInvitationReturns409(): void
    {
        $oldTokenHash = hash('sha256', 'apiinv-used-token');
        // Used AND expired — the resurrection hole the expiry gate alone would miss.
        $this->conn->insert('invitations', [
            'email'      => 'apiinvused@example.com',
            'token_hash' => $oldTokenHash,
            'expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
            'used_at'    => (new \DateTimeImmutable('-12 hours'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'),
        ]);
        $invId = (int) $this->conn->fetchOne(
            "SELECT id FROM invitations WHERE email = 'apiinvused@example.com'"
        );

        $this->apiPost('/admin-api/invitations/' . $invId . '/resend');

        $this->assertResponseStatusCodeSame(409);
        $this->assertEmailCount(0);

        $row = $this->conn->fetchAssociative('SELECT * FROM invitations WHERE id = ?', [$invId]);
        $this->assertSame($oldTokenHash, $row['token_hash'], 'A used invitation must not be regenerated.');
        $this->assertNotNull($row['used_at'], 'used_at must remain set for a used invitation.');
    }

    // AC4: Both endpoints return 401 without a valid admin token
    public function testBothEndpointsReturn401WithoutValidToken(): void
    {
        $this->conn->insert('invitations', [
            'email'      => 'apiinv401@example.com',
            'token_hash' => hash('sha256', 'no-auth-token'),
            'expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $invId = (int) $this->conn->fetchOne(
            "SELECT id FROM invitations WHERE email = 'apiinv401@example.com'"
        );

        $endpoints = [
            ['POST', '/admin-api/invitations', json_encode(['email' => 'new@example.com'])],
            ['POST', '/admin-api/invitations/' . $invId . '/resend', ''],
        ];

        foreach ($endpoints as [$method, $url, $body]) {
            $this->client->request($method, $url, [], [], ['CONTENT_TYPE' => 'application/json'], $body);
            $this->assertResponseStatusCodeSame(401, "Expected 401 on $url without token");
            $this->assertJson((string) $this->client->getResponse()->getContent());
        }
    }
}
