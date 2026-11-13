<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TokenAuthenticatorTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email = 'apitest@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->conn->executeStatement('DELETE FROM login_history');
            $this->conn->executeStatement('DELETE FROM user_sessions');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function seedToken(int $userId, array $overrides = []): array
    {
        $plaintext = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plaintext);

        $this->conn->insert('personal_access_tokens', array_merge([
            'user_id'     => $userId,
            'name'        => 'Test Token',
            'token_hash'  => $hash,
            'expires_at'  => null,
            'last_used_at' => null,
            'revoked_at'  => null,
            'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return ['plaintext' => $plaintext, 'hash' => $hash, 'id' => (int) $this->conn->lastInsertId()];
    }

    public function testMissingAuthorizationHeaderReturns401Json(): void
    {
        $this->client->request('GET', '/api/ping');

        $this->assertResponseStatusCodeSame(401);
        $body = $this->client->getResponse()->getContent();
        $this->assertJson($body);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testInvalidOrRevokedTokenReturns401Json(): void
    {
        $user = $this->createTestUser('apitest@example.com', 'API Test User');

        // Invalid (non-existent) token
        $this->client->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer totally-invalid-token']);
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // Revoked token
        $token = $this->seedToken($user->getId(), ['revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $this->client->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['plaintext']]);
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testValidTokenAuthenticatesRequest(): void
    {
        $user = $this->createTestUser('apitest@example.com', 'API Test User');
        $token = $this->seedToken($user->getId());

        $this->client->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['plaintext']]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $data['status']);
    }

    public function testExpiredTokenReturns401Json(): void
    {
        $user = $this->createTestUser('apitest@example.com', 'API Test User');
        $pastDate = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $token = $this->seedToken($user->getId(), ['expires_at' => $pastDate]);

        $this->client->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['plaintext']]);
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testLastUsedAtIsUpdatedOnAuthenticatedRequest(): void
    {
        $user = $this->createTestUser('apitest@example.com', 'API Test User');
        $token = $this->seedToken($user->getId());

        // Confirm last_used_at starts null
        $row = $this->conn->fetchAssociative(
            'SELECT last_used_at FROM personal_access_tokens WHERE token_hash = ?',
            [$token['hash']]
        );
        $this->assertNull($row['last_used_at']);

        // Authenticated request
        $this->client->request('GET', '/api/ping', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['plaintext']]);
        $this->assertResponseIsSuccessful();

        // last_used_at must now be set
        $row = $this->conn->fetchAssociative(
            'SELECT last_used_at FROM personal_access_tokens WHERE token_hash = ?',
            [$token['hash']]
        );
        $this->assertNotNull($row['last_used_at']);
    }
}
