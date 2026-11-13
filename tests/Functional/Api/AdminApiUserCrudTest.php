<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApiUserCrudTest extends WebTestCase
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
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'api%@example.com'");
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
        $admin->setEmail('apiadmin@example.com');
        $admin->setName('API Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'apiadmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => $hash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function apiRequest(string $method, string $url, ?array $json = null, ?string $token = null): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->adminToken)];
        if ($json !== null) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        $content = $json !== null ? json_encode($json) : null;
        $this->client->request($method, $url, [], [], $headers, $content);
    }

    private function createUserFixture(string $email, string $name = 'Test User'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function testGetUsersReturnsPaginatedJson(): void
    {
        $this->apiRequest('GET', '/admin-api/users');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('total', $data['meta']);
        $this->assertArrayHasKey('page', $data['meta']);
        $this->assertArrayHasKey('total_pages', $data['meta']);
        $this->assertIsArray($data['data']);
    }

    public function testPostCreatesUserAndReturns201(): void
    {
        $this->apiRequest('POST', '/admin-api/users', [
            'email'    => 'apicreated@example.com',
            'name'     => 'API Created User',
            'password' => 'Password1!',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('apicreated@example.com', $data['email']);
        $this->assertEquals('API Created User', $data['name']);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('roles', $data);

        $row = $this->conn->fetchAssociative("SELECT * FROM \"user\" WHERE email = 'apicreated@example.com'");
        $this->assertNotFalse($row);
    }

    // Security (M3): the API create endpoint must enforce the password policy hard floor
    // (>=8), not the old inline "< 6" check. A 7-char password is rejected with 422 and no
    // user is created.
    public function testPostRejectsPasswordBelowPolicyFloor(): void
    {
        $this->apiRequest('POST', '/admin-api/users', [
            'email'    => 'apishortpw@example.com',
            'name'     => 'Short Pw',
            'password' => '1234567', // 7 chars — below the hard floor of 8
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('password', $data['errors']);

        $row = $this->conn->fetchAssociative("SELECT * FROM \"user\" WHERE email = 'apishortpw@example.com'");
        $this->assertFalse($row);
    }

    public function testGetUserDetailReturnsJson(): void
    {
        $user = $this->createUserFixture('apidetail@example.com', 'Detail User');
        $id   = $user->getId();

        $this->apiRequest('GET', '/admin-api/users/' . $id);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($id, $data['id']);
        $this->assertEquals('apidetail@example.com', $data['email']);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('roles', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function testPatchUpdatesUserFieldsAndReturns200(): void
    {
        $user = $this->createUserFixture('apipatch@example.com', 'Patch User');
        $id   = $user->getId();

        $this->apiRequest('PATCH', '/admin-api/users/' . $id, [
            'name'   => 'Updated Name',
            'status' => 'inactive',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Updated Name', $data['name']);
        $this->assertEquals('inactive', $data['status']);

        $row = $this->conn->fetchAssociative('SELECT * FROM "user" WHERE id = ?', [$id]);
        $this->assertEquals('Updated Name', $row['name']);
        $this->assertEquals('inactive', $row['status']);
    }

    // ADR-020 / FEATURE-110: DELETE is a soft delete — 204 still returned, but the row is retained
    // with status='inactive' rather than physically removed.
    public function testDeleteSoftDeletesUserAndReturns204(): void
    {
        $user = $this->createUserFixture('apidelete@example.com', 'Delete User');
        $id   = $user->getId();

        $this->apiRequest('DELETE', '/admin-api/users/' . $id);

        $this->assertResponseStatusCodeSame(204);

        $row = $this->conn->fetchAssociative('SELECT * FROM "user" WHERE id = ?', [$id]);
        $this->assertNotFalse($row, 'Soft delete must keep the users row');
        $this->assertSame('inactive', $row['status']);
    }

    public function testAllEndpointsReturn401WithoutValidToken(): void
    {
        $this->client->request('GET', '/admin-api/users');
        $this->assertResponseStatusCodeSame(401);
        $this->assertJson($this->client->getResponse()->getContent());

        $this->client->request('POST', '/admin-api/users', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/admin-api/users/1');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('PATCH', '/admin-api/users/1', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('DELETE', '/admin-api/users/1');
        $this->assertResponseStatusCodeSame(401);
    }
}
