<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonalAccessTokenTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
            $conn = self::getContainer()->get('doctrine.dbal.default_connection');
            $conn->executeStatement('DELETE FROM personal_access_tokens');
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'pattest%\'');
            $conn->executeStatement('DELETE FROM config WHERE config_key LIKE \'pat.%\'');
            $conn->executeStatement('DELETE FROM audit_log');
            $conn->executeStatement('DELETE FROM login_history');
            $conn->executeStatement('DELETE FROM user_sessions');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    public function testTokenListRendersWithActiveTokens(): void
    {
        $user = $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        // Seed an active token directly
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'My API Token',
            'token_hash' => hash('sha256', 'sometoken123'),
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->client->request('GET', '/account/tokens');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
        $this->assertSelectorTextContains('.token-name', 'My API Token');
    }

    public function testCreateTokenShowsPlaintextOnce(): void
    {
        $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Create Token', [
            'name' => 'My New Token',
        ]);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        // Plaintext token should be on the page
        $this->assertStringContainsString('token-plaintext', $content);

        // Verify it was stored as hash in the DB
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative('SELECT * FROM personal_access_tokens WHERE name = ?', ['My New Token']);
        $this->assertNotFalse($row, 'Token row should exist in DB');
        $this->assertNotEmpty($row['token_hash']);
    }

    public function testPlaintextNotStored(): void
    {
        $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Create Token', [
            'name' => 'Hash Check Token',
        ]);

        $content = $this->client->getResponse()->getContent();

        // Extract the plaintext from the page
        preg_match('/<div class="token-plaintext">([^<]+)<\/div>/', $content, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'Plaintext should appear in response');
        $plaintext = trim($matches[1]);

        // Verify only the hash is in the DB
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative('SELECT * FROM personal_access_tokens WHERE name = ?', ['Hash Check Token']);
        $this->assertNotFalse($row);

        // DB must store the hash, not the plaintext
        $this->assertNotEquals($plaintext, $row['token_hash']);
        $this->assertEquals(hash('sha256', $plaintext), $row['token_hash']);
    }

    public function testUserCanRevokeToken(): void
    {
        $user = $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        // Create a token via DB
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'Token To Revoke',
            'token_hash' => hash('sha256', 'revoketoken456'),
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $tokenId = $conn->lastInsertId();

        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Revoke');

        $this->assertResponseRedirects('/account/tokens');

        // Verify revokedAt is set
        $row = $conn->fetchAssociative('SELECT revoked_at FROM personal_access_tokens WHERE id = ?', [(int) $tokenId]);
        $this->assertNotNull($row['revoked_at']);
    }

    public function testMaxTokensLimitBlocksCreation(): void
    {
        $user = $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        // Set max_tokens_per_user = 1
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('pat.max_tokens_per_user', '1')"
        );

        // Create first token
        $conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'First Token',
            'token_hash' => hash('sha256', 'firsttoken'),
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Attempt to create second token
        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Create Token', [
            'name' => 'Second Token',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // Confirm second token was NOT created
        $count = $conn->fetchOne('SELECT COUNT(*) FROM personal_access_tokens WHERE user_id = ?', [$user->getId()]);
        $this->assertEquals(1, (int) $count);
    }

    public function testExpiredTokensDoNotAppearActive(): void
    {
        $user = $this->createTestUser('pattest@example.com', 'PAT Test User');
        $this->loginUser('pattest@example.com', 'testpassword', true);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        // Insert an expired token
        $pastDate = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'Expired Token',
            'token_hash' => hash('sha256', 'expiredtoken'),
            'expires_at' => $pastDate,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        // Insert a valid token
        $conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'Active Token',
            'token_hash' => hash('sha256', 'activetoken'),
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->client->request('GET', '/account/tokens');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Expired Token', $content);
        $this->assertStringContainsString('Active Token', $content);
    }
}
