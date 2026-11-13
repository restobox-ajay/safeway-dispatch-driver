<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\ConfigService;
use App\Service\InMemoryWebhookDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-097 (review C2): the stateless `api` firewall authenticates via bearer PAT
 * and resolves a real `User`, so before the fix every API call was treated as an
 * interactive login — writing audit/login_history/user_sessions rows, sending
 * "new device" emails, and firing webhooks. These tests pin that a PAT-authenticated
 * (or failed-bearer) /api request produces none of those interactive-login side-effects.
 */
final class ApiLoginSideEffectsTest extends WebTestCase
{
    private const EMAIL = 'api-sideeffects@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private ConfigService $configService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->configService = self::getContainer()->get(ConfigService::class);
        InMemoryWebhookDispatcher::reset();
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
            $userId = $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);
            if ($userId !== false) {
                $this->conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                $this->conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [(int) $userId]);
                $this->conn->executeStatement('DELETE FROM personal_access_tokens WHERE user_id = ?', [(int) $userId]);
            }
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email = ?", [self::EMAIL]);
            $this->conn->executeStatement("DELETE FROM audit_log WHERE actor = ?", [self::EMAIL]);
            $this->conn->executeStatement(
                "DELETE FROM config WHERE config_key IN ('webhook.global_url', 'webhook.login_url')"
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(): int
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('API Side Effects User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $id = (int) $user->getId();
        $this->em->clear();

        return $id;
    }

    private function seedToken(int $userId): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'      => $userId,
            'name'         => 'Side Effects Token',
            'token_hash'   => hash('sha256', $plaintext),
            'expires_at'   => null,
            'last_used_at' => null,
            'revoked_at'   => null,
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function countLoginAuditRows(): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login'"
        );
    }

    private function countHistory(int $userId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM login_history WHERE user_id = ?', [$userId]);
    }

    private function countSessions(int $userId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);
    }

    // AC2 + AC5: a successful PAT auth produces no audit login row, no login_history row,
    // no user_sessions row, and sends no login-notification email.
    public function testSuccessfulApiAuthProducesNoInteractiveSideEffects(): void
    {
        $userId = $this->createUser();
        $plaintext = $this->seedToken($userId);

        $auditBefore = $this->countLoginAuditRows();

        $this->client->request('GET', '/api/ping', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertSame($auditBefore, $this->countLoginAuditRows(),
            'A PAT-authenticated /api request must not write an audit login row');
        $this->assertSame(0, $this->countHistory($userId),
            'A PAT-authenticated /api request must not write a login_history row');
        $this->assertSame(0, $this->countSessions($userId),
            'A PAT-authenticated /api request must not write a user_sessions row');
        $this->assertEmailCount(0);
    }

    // AC3: a failed bearer-token auth writes no login.failure audit row and fires no webhook.
    public function testFailedApiAuthProducesNoFailureAuditRowAndNoWebhook(): void
    {
        // A webhook target is configured so that, were the firewall filter absent,
        // a login.failure webhook WOULD fire — proving the guard, not merely an unset URL.
        $this->configService->set('webhook.global_url', 'https://hooks.example.com/auth');

        $failureBefore = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND outcome = 'failure'"
        );

        $this->client->request('GET', '/api/ping', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer totally-invalid-token',
        ]);
        $this->assertResponseStatusCodeSame(401);

        $failureAfter = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND outcome = 'failure'"
        );
        $this->assertSame($failureBefore, $failureAfter,
            'A failed bearer-token /api auth must not write a login.failure audit row');
        $this->assertCount(0, InMemoryWebhookDispatcher::getDispatched(),
            'A failed bearer-token /api auth must not fire a login.failure webhook');
    }
}
