<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminRevokeUserTokensTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $admin = new Admin();
        $admin->setEmail('revoketokens-admin@example.com');
        $admin->setName('Revoke Tokens Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $admin->setRoles([]);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM personal_access_tokens WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE \'revoketokens-%\')');
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'revoketokens-%\'');
            $conn->executeStatement('DELETE FROM audit_log WHERE actor = \'revoketokens-admin@example.com\'');
            foreach (['revoketokens-admin@example.com'] as $email) {
                $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
                if ($admin) {
                    $this->em->remove($admin);
                    $this->em->flush();
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): User
    {
        return $this->createTestUser($email, 'Revoke Tokens User', 'userpass');
    }

    private function createActiveToken(int $userId, string $name = 'Test Token'): array
    {
        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);
        $token     = new PersonalAccessToken($userId, $name, $hash);
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return ['plaintext' => $plaintext, 'id' => $token->getId()];
    }

    // AC1: Admin user list shows active token count and 'Revoke All Tokens' form
    public function testActiveTokenCountAndRevokeButtonShownInList(): void
    {
        $user = $this->createUser('revoketokens-list@example.com');
        $id   = $user->getId();

        $this->createActiveToken($id, 'My API Token');

        $this->loginAsAdmin('revoketokens-admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.active-token-count');
        $this->assertSelectorExists('form[action="/admin/users/' . $id . '/revoke-tokens"]');
    }

    // AC2: Revoking marks all active tokens as revoked immediately
    public function testRevokingTokensMarksAllAsRevoked(): void
    {
        $user = $this->createUser('revoketokens-revoke@example.com');
        $id   = $user->getId();

        $this->createActiveToken($id, 'Token One');
        $this->createActiveToken($id, 'Token Two');

        $this->loginAsAdmin('revoketokens-admin@example.com');
        $crawler = $this->client->request('GET', '/admin/users');

        $form = $crawler->filter('form[action="/admin/users/' . $id . '/revoke-tokens"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/users');

        $conn  = $this->em->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens WHERE user_id = ? AND revoked_at IS NULL',
            [$id]
        );
        $this->assertSame(0, $count, 'All tokens should be revoked');

        $revokedCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens WHERE user_id = ? AND revoked_at IS NOT NULL',
            [$id]
        );
        $this->assertSame(2, $revokedCount, 'Both tokens should have revoked_at set');
    }

    // AC3: Subsequent API requests with revoked tokens return 401
    public function testSubsequentApiRequestWithRevokedTokenReturns401(): void
    {
        $user = $this->createUser('revoketokens-api@example.com');
        $id   = $user->getId();

        ['plaintext' => $plaintext] = $this->createActiveToken($id, 'API Token');

        $this->loginAsAdmin('revoketokens-admin@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $id . '/revoke-tokens"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/users');

        // Use Bearer token with same client (api firewall is stateless, ignores admin session)
        $this->client->request('GET', '/api/ping', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
        ]);

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }
}
