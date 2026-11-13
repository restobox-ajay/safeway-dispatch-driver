<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-107 (review C14): the login-notification "known device" decision must come from the
 * dedicated login_notification_seen marker store, NOT from login_history — so it no longer depends
 * on the order the two LoginSuccessEvent listeners run in, and a sent alert also leaves a dedicated
 * audit-log row.
 */
final class LoginNotificationRecognitionTest extends WebTestCase
{
    private const EMAILS = [
        'lnrec1@example.com',
        'lnrec2@example.com',
        'lnrec3@example.com',
        'lnrec4@example.com',
        'lnrec5@example.com',
    ];

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
            foreach (self::EMAILS as $email) {
                $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
                if ($userId !== false) {
                    $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM login_notification_seen WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
                }
                $conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [$email]);
            }
            $conn->executeStatement("DELETE FROM config WHERE config_key IN ('login_notifications.enabled', 'login_notifications.recognition_mode')");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Recognition Test User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setLoginNotificationsEnabled(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email, string $userAgent): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'testpassword',
        ]);
    }

    private function resetMailer(): void
    {
        self::getContainer()->get('mailer.message_logger_listener')->reset();
    }

    private function countAuditRows(string $email): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ?',
            [$email, 'login_notification_sent'],
        );
    }

    // AC3 / AC7: two successive logins from the same device produce exactly one notification.
    public function testSecondLoginSameDeviceDoesNotReNotify(): void
    {
        $this->createUser('lnrec1@example.com');

        $this->loginAs('lnrec1@example.com', 'TripwireAgent/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/logout');
        $this->loginAs('lnrec1@example.com', 'TripwireAgent/1.0');
        $this->assertEmailCount(0);
    }

    // AC7: after a device is known, a login from a NEW device notifies again.
    public function testNewDeviceAfterKnownDeviceNotifiesAgain(): void
    {
        $this->createUser('lnrec2@example.com');

        $this->loginAs('lnrec2@example.com', 'KnownAgent/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/logout');
        // Different user-agent → different fingerprint → a new device under the default mode.
        $this->loginAs('lnrec2@example.com', 'BrandNewAgent/2.0');
        $this->assertEmailCount(1);
    }

    // AC2: recognition must NOT be inferred from login_history. Pre-seed login_history with the
    // CURRENT device's fingerprint (simulating LoginHistoryListener having already written the
    // current login), then log in: the notification must STILL fire because recognition reads the
    // (empty) marker store, not history. This fails on the pre-fix code, which would treat the
    // pre-seeded history row as proof the device is known and suppress the alert.
    public function testRecognitionIndependentOfLoginHistory(): void
    {
        $user = $this->createUser('lnrec3@example.com');
        $userAgent = 'HistoryFirstAgent/1.0';
        $fingerprint = hash('sha256', '127.0.0.1' . $userAgent);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'INSERT INTO login_history (user_id, ip, user_agent, fingerprint, created_at) VALUES (?, ?, ?, ?, ?)',
            [(int) $user->getId(), '127.0.0.1', $userAgent, $fingerprint, '2026-07-06 00:00:00'],
        );

        $this->loginAs('lnrec3@example.com', $userAgent);

        $this->assertEmailCount(1);
    }

    // AC5: a sent alert writes a dedicated 'login_notification_sent' audit row; a repeat login from
    // the same (now known) device writes no further row. Fails on the pre-fix code, which wrote no
    // audit row at all.
    public function testAuditRowWrittenWhenNotifiedAndNotOnRepeat(): void
    {
        $this->createUser('lnrec4@example.com');

        $this->loginAs('lnrec4@example.com', 'AuditAgent/1.0');
        $this->assertEmailCount(1);
        self::assertSame(1, $this->countAuditRows('lnrec4@example.com'));

        $this->client->request('GET', '/logout');
        $this->loginAs('lnrec4@example.com', 'AuditAgent/1.0');
        // No new email and no new audit row for the known device.
        self::assertSame(1, $this->countAuditRows('lnrec4@example.com'));
    }

    // AC4: recognition_mode=ip_only recognises by IP alone through the marker store.
    public function testIpOnlyModeHonoured(): void
    {
        self::getContainer()->get(ConfigService::class)->set('login_notifications.recognition_mode', 'ip_only');

        $this->createUser('lnrec5@example.com');

        $this->loginAs('lnrec5@example.com', 'IpModeAgent/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/logout');
        // Different user-agent but same IP (127.0.0.1) → ip_only treats it as the same device.
        $this->loginAs('lnrec5@example.com', 'IpModeAgent/DIFFERENT/2.0');
        $this->assertEmailCount(0);
    }
}
