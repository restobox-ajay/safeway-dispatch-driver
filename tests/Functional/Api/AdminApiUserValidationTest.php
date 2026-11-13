<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-115 (review C22): the PATCH/POST validation contract must reject unknown
 * role/status values with a 422 rather than silently dropping/coercing them to 200 OK.
 */
final class AdminApiUserValidationTest extends WebTestCase
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
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'apival%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apival%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('apivaladmin@example.com');
        $admin->setName('API Val Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'apivaladmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function apiRequest(string $method, string $url, ?array $json = null): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken];
        if ($json !== null) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $url, [], [], $headers, $json !== null ? json_encode($json) : null);
    }

    private function createUserFixture(string $email): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Val User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $this->em->persist($user);
        $this->em->flush();
        $id = (int) $user->getId();
        $this->em->clear();

        return $id;
    }

    public function testPatchInvalidStatusIsRejectedNotSilentlyDropped(): void
    {
        $id = $this->createUserFixture('apival-status@example.com');

        $this->apiRequest('PATCH', '/admin-api/users/' . $id, ['status' => 'banned']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('status', $data['errors']);

        // The invalid edit must NOT have been silently applied.
        $row = $this->conn->fetchAssociative('SELECT status FROM "user" WHERE id = :id', ['id' => $id]);
        self::assertSame('active', $row['status']);
    }

    public function testPatchInvalidRoleIsRejected(): void
    {
        $id = $this->createUserFixture('apival-role@example.com');

        $this->apiRequest('PATCH', '/admin-api/users/' . $id, ['role' => 'ROLE_ADMIN']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('role', $data['errors']);
    }

    public function testPatchValidStatusStillApplies(): void
    {
        $id = $this->createUserFixture('apival-ok@example.com');

        $this->apiRequest('PATCH', '/admin-api/users/' . $id, ['status' => 'inactive']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT status FROM "user" WHERE id = :id', ['id' => $id]);
        self::assertSame('inactive', $row['status']);
    }

    public function testCreateInvalidStatusIsRejected(): void
    {
        $this->apiRequest('POST', '/admin-api/users', [
            'email'    => 'apival-create@example.com',
            'name'     => 'Create Val',
            'password' => 'Password1!',
            'status'   => 'banned',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('status', $data['errors']);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM "user" WHERE email = :email',
            ['email' => 'apival-create@example.com']
        );
        self::assertSame(0, $count);
    }
}
