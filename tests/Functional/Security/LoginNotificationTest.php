<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginNotificationTest extends WebTestCase
{
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
            foreach (['lnnotify1@example.com', 'lnnotify2@example.com', 'lnnotify3@example.com', 'lnnotify4@example.com', 'lnnotify5@example.com'] as $email) {
                $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
                if ($userId !== false) {
                    $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM login_notification_seen WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
                }
            }
            $conn->executeStatement("DELETE FROM config WHERE config_key IN ('login_notifications.enabled', 'login_notifications.recognition_mode')");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, bool $notificationsEnabled = true): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Notify Test User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setLoginNotificationsEnabled($notificationsEnabled);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email, string $userAgent = 'TestAgent/1.0'): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'testpassword',
        ]);
    }

    // AC1: Login from a previously unseen fingerprint triggers a notification email
    public function testNewFingerprintSendsNotificationEmail(): void
    {
        $this->createUser('lnnotify1@example.com');
        $this->loginAs('lnnotify1@example.com', 'UniqueAgent/NewDevice/1.0');

        $this->assertEmailCount(1);
        $messages = $this->getMailerMessages();
        $this->assertNotEmpty($messages);

        $email = $messages[0];
        $this->assertEmailAddressContains($email, 'To', 'lnnotify1@example.com');
    }

    // AC2: Login from a known fingerprint does not trigger a notification email
    public function testKnownFingerprintDoesNotSendEmail(): void
    {
        $this->createUser('lnnotify2@example.com');

        // First login — new fingerprint, sends email
        $this->loginAs('lnnotify2@example.com', 'KnownAgent/1.0');
        // Reset email log so we only count emails from the second login
        self::getContainer()->get('mailer.message_logger_listener')->reset();

        // Second login — same fingerprint, no email
        $this->client->request('GET', '/logout');
        $this->loginAs('lnnotify2@example.com', 'KnownAgent/1.0');

        $this->assertEmailCount(0);
    }

    // AC3: Notification email contains the login IP, user-agent, and timestamp
    public function testNotificationEmailContainsIpUserAgentAndTimestamp(): void
    {
        $this->createUser('lnnotify3@example.com');
        $userAgent = 'ContentCheckAgent/2.0';
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);

        $this->loginAs('lnnotify3@example.com', $userAgent);

        $this->assertEmailCount(1);
        $messages = $this->getMailerMessages();
        $email = $messages[0];

        $bodyText = $email->getHtmlBody() ?? $email->getTextBody() ?? '';
        $this->assertStringContainsString('127.0.0.1', $bodyText, 'Email body must contain IP address');
        $this->assertStringContainsString($userAgent, $bodyText, 'Email body must contain user-agent');

        // Timestamp: just check a year is present (current year)
        $this->assertStringContainsString((string) date('Y'), $bodyText, 'Email body must contain a timestamp');
    }

    // AC4: Notification is not sent when globally disabled in admin config
    public function testGloballyDisabledSkipsNotification(): void
    {
        // Disable global login notifications
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('login_notifications.enabled', '0');

        $this->createUser('lnnotify4@example.com');
        $this->loginAs('lnnotify4@example.com', 'DisabledNotifyAgent/1.0');

        $this->assertEmailCount(0);
    }

    // recognition_mode=ip_only: same IP with different user-agent is treated as known
    public function testIpOnlyModeRecognizesByIpAlone(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('login_notifications.recognition_mode', 'ip_only');

        $this->createUser('lnnotify5@example.com');

        // First login — establishes the IP in history
        $this->loginAs('lnnotify5@example.com', 'AgentAlpha/1.0');
        self::getContainer()->get('mailer.message_logger_listener')->reset();

        // Second login — different user-agent but same IP (127.0.0.1) → ip_only treats it as known
        $this->client->request('GET', '/logout');
        $this->loginAs('lnnotify5@example.com', 'AgentBeta/2.0');

        $this->assertEmailCount(0);
    }
}
