<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiBundleEndpointsTest extends WebTestCase
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
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apibundle%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement('DELETE FROM user_sessions');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('apibundleadmin@example.com');
        $admin->setName('API Bundle Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'apibundleadmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Bundle API Token',
            'token_hash' => $hash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createUserFixture(string $email): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Bundle Test User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    public function testUnlockClearsLockedUntilAndReturns200(): void
    {
        $userId = $this->createUserFixture('apibundleunlock@example.com');
        // Lockout lives in the auth-security-bundle satellite account_lockouts (FEATURE-144 / ADR-044).
        $lockedUntil = (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'INSERT INTO account_lockouts (user_id, locked_until) VALUES (?, ?)',
            [$userId, $lockedUntil]
        );

        $this->client->request('POST', '/admin-api/users/' . $userId . '/unlock', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);

        $remaining = $this->conn->fetchOne('SELECT COUNT(*) FROM account_lockouts WHERE user_id = ?', [$userId]);
        $this->assertSame(0, (int) $remaining, 'the lockout row must be gone after unlock');
    }

    public function testRevokeTokensRevokesAllUserPatsAndReturns204(): void
    {
        $userId = $this->createUserFixture('apibundletokens@example.com');

        // seed two active PATs for the user
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => 'Token A',
            'token_hash' => hash('sha256', 'plaintext-a'),
            'created_at' => $now,
        ]);
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => 'Token B',
            'token_hash' => hash('sha256', 'plaintext-b'),
            'created_at' => $now,
        ]);

        $this->client->request('DELETE', '/admin-api/users/' . $userId . '/tokens', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
        ]);

        $this->assertResponseStatusCodeSame(204);

        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
        $this->assertEquals(0, (int) $count, 'All user PATs must be revoked');
    }

    public function testResetTwoFactorClearsTotpFieldsAndReturns204(): void
    {
        $userId = $this->createUserFixture('apibundle2fa@example.com');
        // The user's TOTP state now lives in the auth-2fa-bundle satellite (two_factor_settings), not on
        // the user row (FEATURE-143 / ADR-043) — seed it there.
        $this->conn->executeStatement(
            'INSERT INTO two_factor_settings (user_id, totp_secret, is_totp_enabled, last_totp_counter) VALUES (?, ?, 1, NULL)',
            [$userId, 'ABCDEFGHIJKLMNOP']
        );

        $this->client->request('DELETE', '/admin-api/users/' . $userId . '/2fa', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
        ]);

        $this->assertResponseStatusCodeSame(204);

        $row = $this->conn->fetchAssociative('SELECT totp_secret, is_totp_enabled FROM two_factor_settings WHERE user_id = ?', [$userId]);
        $this->assertNull($row['totp_secret'], 'totp_secret must be null after 2FA reset');
        $this->assertEquals(0, (int) $row['is_totp_enabled'], 'is_totp_enabled must be 0 after 2FA reset');
    }

    public function testAllBundleEndpointsReturn401WithoutValidToken(): void
    {
        $userId = $this->createUserFixture('apibundle401@example.com');

        $endpoints = [
            ['POST',   '/admin-api/users/' . $userId . '/unlock'],
            ['DELETE', '/admin-api/users/' . $userId . '/tokens'],
            ['DELETE', '/admin-api/users/' . $userId . '/2fa'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $this->client->request($method, $url);
            $this->assertResponseStatusCodeSame(401, "Expected 401 on $method $url without token");
            $this->assertJson($this->client->getResponse()->getContent());
        }
    }
}
