<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Service\ConfigService;
use App\Service\InMemoryWebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WebhookTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ConfigService $configService;

    private static array $testEmails = [
        'webhook-login1@example.com',
        'webhook-login2@example.com',
        'webhook-login3@example.com',
        'webhook-reg4@example.com',
        'webhook-reset5@example.com',
        'webhook-lockout6@example.com',
        'webhook-lockout7@example.com',
        'webhook-nobody8@example.com',
    ];

    protected function setUp(): void
    {
        $this->client        = static::createClient();
        $this->em            = self::getContainer()->get(EntityManagerInterface::class);
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
            $conn = $this->em->getConnection();
            foreach (self::$testEmails as $email) {
                $conn->executeStatement('DELETE FROM password_reset_tokens WHERE email = ?', [$email]);
                $conn->executeStatement('DELETE FROM login_attempts WHERE email = ?', [$email]);
                $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
                if ($userId !== false) {
                    $conn->executeStatement('DELETE FROM account_lockouts WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM password_history WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
                }
            }
            $conn->executeStatement(
                "DELETE FROM config WHERE config_key IN (
                    'webhook.global_url',
                    'webhook.login_url',
                    'webhook.registration_url',
                    'webhook.password_reset_url',
                    'webhook.lockout_url',
                    'lockout.max_attempts',
                    'lockout.duration_minutes'
                )"
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Webhook Test User');
        $user->setPassword(password_hash('webhookpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'webhookpass',
        ]);
    }

    private function loginWithWrongPassword(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'wrong-password',
        ]);
    }

    private function createValidResetToken(string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new PasswordResetToken($email, hash('sha256', $plaintext), new \DateTimeImmutable('+1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintext;
    }

    // A completed password reset fires a webhook to the per-event reset URL (FEATURE: wire the
    // previously-unwired webhook.password_reset_url).
    public function testPasswordResetFiresWebhook(): void
    {
        $this->configService->set('webhook.password_reset_url', 'https://reset-hook.example.com');
        $this->createUser('webhook-reset5@example.com');
        $plaintext = $this->createValidResetToken('webhook-reset5@example.com');

        $this->client->request('POST', '/reset-password/' . $plaintext, ['password' => 'newpassword123']);
        $this->assertResponseRedirects('/staff/login');

        $dispatched = InMemoryWebhookDispatcher::getDispatched();
        $this->assertCount(1, $dispatched, 'A completed reset must fire exactly one webhook');
        $this->assertSame('https://reset-hook.example.com', $dispatched[0]['url']);
        $this->assertSame('password_reset', $dispatched[0]['payload']['event_type']);
        $this->assertSame('webhook-reset5@example.com', $dispatched[0]['payload']['actor']);
    }

    // An account lockout fires a webhook to the per-event lockout URL (wire the previously-
    // unwired webhook.lockout_url). Lockout triggers on the Nth failed login.
    public function testAccountLockoutFiresWebhook(): void
    {
        $this->configService->set('webhook.lockout_url', 'https://lockout-hook.example.com');
        $this->configService->set('lockout.max_attempts', '2');
        $this->createUser('webhook-lockout6@example.com');

        // Two failed logins: the second crosses the threshold and locks the account.
        $this->loginWithWrongPassword('webhook-lockout6@example.com');
        $this->loginWithWrongPassword('webhook-lockout6@example.com');

        $lockoutHooks = array_values(array_filter(
            InMemoryWebhookDispatcher::getDispatched(),
            static fn (array $d): bool => ($d['payload']['event_type'] ?? '') === 'lockout'
        ));
        $this->assertCount(1, $lockoutHooks, 'Crossing the lockout threshold must fire one lockout webhook');
        $this->assertSame('https://lockout-hook.example.com', $lockoutHooks[0]['url']);
        $this->assertSame('webhook-lockout6@example.com', $lockoutHooks[0]['payload']['actor']);
        $this->assertArrayHasKey('locked_until', $lockoutHooks[0]['payload']);
    }

    // AC1: Auth events dispatch a JSON webhook payload to the configured URL
    /** @return list<array{url: string, payload: array<string, mixed>}> */
    private function lockoutHooks(): array
    {
        return array_values(array_filter(
            InMemoryWebhookDispatcher::getDispatched(),
            static fn (array $d): bool => ($d['payload']['event_type'] ?? '') === 'lockout'
        ));
    }

    // Issue #15: the lockout INSERT ... SELECT writes nothing for an email no account has, so no account is locked
    // and no 'lockout' event may be sent (it was: a false security alert naming any address an attacker typed).
    public function testFailuresForAnUnknownEmailFireNoLockoutWebhook(): void
    {
        $this->configService->set('webhook.lockout_url', 'https://lockout-hook.example.com');
        $this->configService->set('lockout.max_attempts', '2');

        $this->loginWithWrongPassword('webhook-nobody8@example.com');
        $this->loginWithWrongPassword('webhook-nobody8@example.com');
        $this->loginWithWrongPassword('webhook-nobody8@example.com');

        $this->assertSame([], $this->lockoutHooks());
    }

    // Issue #15: failures against an account that is ALREADY locked refresh the lock but must not re-announce it.
    public function testFurtherFailuresWhileLockedDoNotRefireTheLockoutWebhook(): void
    {
        $this->configService->set('webhook.lockout_url', 'https://lockout-hook.example.com');
        $this->configService->set('lockout.max_attempts', '2');
        $this->createUser('webhook-lockout7@example.com');

        $this->loginWithWrongPassword('webhook-lockout7@example.com');
        $this->loginWithWrongPassword('webhook-lockout7@example.com'); // threshold: locked
        $this->loginWithWrongPassword('webhook-lockout7@example.com');
        $this->loginWithWrongPassword('webhook-lockout7@example.com');

        $this->assertCount(1, $this->lockoutHooks(), 'one lockout, one event');
    }

    public function testLoginEventFiresWebhookToConfiguredUrl(): void
    {
        $this->configService->set('webhook.global_url', 'https://hooks.example.com/auth');
        $this->createUser('webhook-login1@example.com');

        $this->loginAs('webhook-login1@example.com');

        $dispatched = InMemoryWebhookDispatcher::getDispatched();
        $this->assertCount(1, $dispatched, 'Exactly one webhook must be dispatched on login');
        $this->assertSame('https://hooks.example.com/auth', $dispatched[0]['url']);
    }

    // AC2: Webhook URL is configurable per event type and globally via admin config
    public function testWebhookUrlIsConfigurablePerEventType(): void
    {
        $this->configService->set('webhook.login_url', 'https://login-hook.example.com');
        $this->createUser('webhook-login2@example.com');

        $this->loginAs('webhook-login2@example.com');

        $dispatched = InMemoryWebhookDispatcher::getDispatched();
        $this->assertCount(1, $dispatched, 'One webhook must fire using the per-event login URL');
        $this->assertSame('https://login-hook.example.com', $dispatched[0]['url'],
            'Per-event URL must be used when set');
    }

    // AC3: Payload contains event_type, actor, timestamp, and relevant metadata
    public function testWebhookPayloadContainsRequiredFields(): void
    {
        $this->configService->set('webhook.global_url', 'https://hooks.example.com/auth');
        $this->createUser('webhook-login3@example.com');

        $this->loginAs('webhook-login3@example.com');

        $dispatched = InMemoryWebhookDispatcher::getDispatched();
        $this->assertNotEmpty($dispatched);

        $payload = $dispatched[0]['payload'];
        $this->assertArrayHasKey('event_type', $payload, 'Payload must contain event_type');
        $this->assertArrayHasKey('actor', $payload, 'Payload must contain actor');
        $this->assertArrayHasKey('timestamp', $payload, 'Payload must contain timestamp');
        $this->assertArrayHasKey('ip', $payload, 'Payload must contain ip metadata');

        $this->assertSame('login.success', $payload['event_type']);
        $this->assertSame('webhook-login3@example.com', $payload['actor']);

        // Timestamp must be a valid ISO-8601 date string
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['timestamp']);
    }

    // AC4: Events not subscribed to do not trigger any webhook
    public function testEventNotSubscribedToDoesNotFireWebhook(): void
    {
        // Only login_url is configured — registration_url and global_url are both empty
        $this->configService->set('webhook.login_url', 'https://login-hook.example.com');

        // Register a new user via the form (CSRF token fetched automatically by submitForm)
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'webhook-reg4@example.com',
            'name'     => 'Webhook Reg User',
            'password' => 'WebhookPass1!',
        ]);

        $dispatched = InMemoryWebhookDispatcher::getDispatched();
        $this->assertCount(0, $dispatched,
            'Registration must not fire a webhook when only login_url is configured');
    }

    // Issue #22: the login.failure payload carries the posted email, which is attacker-controlled and unbounded.
    public function testLoginFailureWebhookCapsTheActor(): void
    {
        $this->configService->set('webhook.login_url', 'https://hooks.example.com/login');
        $this->client->request('POST', '/staff/login', ['email' => str_repeat('a', 6000) . '@example.com', 'password' => 'x']);

        $failures = array_values(array_filter(InMemoryWebhookDispatcher::getDispatched(), static fn (array $d): bool => ($d['payload']['event_type'] ?? null) === 'login.failure'));
        $this->assertCount(1, $failures);
        $this->assertLessThanOrEqual(255, mb_strlen($failures[0]['payload']['actor']));
    }
}

