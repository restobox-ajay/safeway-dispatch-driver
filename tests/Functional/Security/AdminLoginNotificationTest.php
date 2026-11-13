<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-108: admins receive a "new login from an unrecognized device" email, mirroring the user
 * side (FEATURE-107). Recognition is driven by the dedicated admin_login_notification_seen table
 * (keyed on admin_id), honours the admin-specific recognition mode, and leaves a
 * `login_notification_sent` audit row (actorType 'admin') — none of which reads login_history/audit.
 */
final class AdminLoginNotificationTest extends WebTestCase
{
    private const EMAILS = [
        'adminln1@example.com',
        'adminln2@example.com',
        'adminln3@example.com',
        'adminln4@example.com',
        'adminln5@example.com',
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
                $adminId = $conn->fetchOne('SELECT id FROM admin WHERE email = ?', [$email]);
                if ($adminId !== false) {
                    $conn->executeStatement('DELETE FROM admin_login_notification_seen WHERE admin_id = ?', [(int) $adminId]);
                    $conn->executeStatement('DELETE FROM admin WHERE id = ?', [(int) $adminId]);
                }
                $conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [$email]);
            }
            $conn->executeStatement("DELETE FROM config WHERE config_key IN ('login_notifications.admin_enabled', 'login_notifications.admin_recognition_mode')");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Admin Notification Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email, string $userAgent): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'adminpassword',
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
            "SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = 'login_notification_sent' AND actor_type = 'admin'",
            [$email],
        );
    }

    private function countSeenRows(int $adminId): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) $conn->fetchOne('SELECT COUNT(*) FROM admin_login_notification_seen WHERE admin_id = ?', [$adminId]);
    }

    // AC1: an admin login from an unrecognized device sends the "new login" email.
    public function testAdminUnrecognizedDeviceSendsEmail(): void
    {
        $this->createAdmin('adminln1@example.com');

        $this->loginAs('adminln1@example.com', 'AdminAgent/1.0');

        $this->assertEmailCount(1);
        self::assertSame(1, $this->countAuditRows('adminln1@example.com'));
    }

    // AC7: two successive logins from the same admin device produce exactly one notification.
    public function testSecondLoginSameDeviceDoesNotReNotify(): void
    {
        $this->createAdmin('adminln2@example.com');

        $this->loginAs('adminln2@example.com', 'AdminTripwire/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/admin/logout');
        $this->loginAs('adminln2@example.com', 'AdminTripwire/1.0');
        $this->assertEmailCount(0);
    }

    // AC7: after a device is known, a login from a NEW device notifies again.
    public function testNewDeviceAfterKnownDeviceNotifiesAgain(): void
    {
        $this->createAdmin('adminln3@example.com');

        $this->loginAs('adminln3@example.com', 'AdminKnown/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/admin/logout');
        // Different user-agent → different fingerprint → a new device under the default mode.
        $this->loginAs('adminln3@example.com', 'AdminBrandNew/2.0');
        $this->assertEmailCount(1);
    }

    // AC6: admin_recognition_mode=ip_only recognises by IP alone through the admin marker store.
    public function testIpOnlyModeHonoured(): void
    {
        self::getContainer()->get(ConfigService::class)->set('login_notifications.admin_recognition_mode', 'ip_only');

        $this->createAdmin('adminln4@example.com');

        $this->loginAs('adminln4@example.com', 'AdminIpMode/1.0');
        $this->assertEmailCount(1);
        $this->resetMailer();

        $this->client->request('GET', '/admin/logout');
        // Different user-agent but same IP (127.0.0.1) → ip_only treats it as the same device.
        $this->loginAs('adminln4@example.com', 'AdminIpMode/DIFFERENT/2.0');
        $this->assertEmailCount(0);
    }

    // AC10: the admin-specific enable switch turns the whole flow off — no email, no audit, no marker.
    public function testAdminNotificationsDisabledSendsNothing(): void
    {
        self::getContainer()->get(ConfigService::class)->set('login_notifications.admin_enabled', '0');

        $admin = $this->createAdmin('adminln5@example.com');

        $this->loginAs('adminln5@example.com', 'AdminDisabled/1.0');

        $this->assertEmailCount(0);
        self::assertSame(0, $this->countAuditRows('adminln5@example.com'));
        self::assertSame(0, $this->countSeenRows((int) $admin->getId()));
    }
}
