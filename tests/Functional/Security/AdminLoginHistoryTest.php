<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-109: interactive admin logins are recorded in the dedicated admin_login_history table
 * (keyed on admin_id), and an admin can view their OWN recent logins via /admin/login-history —
 * mirroring the user side (FEATURE-107 view) while staying realm-isolated (ADR-003). An admin can
 * never see another admin's history.
 */
final class AdminLoginHistoryTest extends WebTestCase
{
    private const EMAILS = [
        'adminlh1@example.com',
        'adminlh2@example.com',
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
                    $conn->executeStatement('DELETE FROM admin_login_history WHERE admin_id = ?', [(int) $adminId]);
                    $conn->executeStatement('DELETE FROM admin WHERE id = ?', [(int) $adminId]);
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Admin History Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $adminId = (int) $admin->getId();
        $this->em->clear();

        return $adminId;
    }

    private function insertHistoryRow(int $adminId, string $ip, string $ua): void
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'INSERT INTO admin_login_history (admin_id, ip, user_agent, fingerprint, created_at) VALUES (?, ?, ?, ?, ?)',
            [$adminId, $ip, $ua, hash('sha256', $ip . $ua), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
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

    private function countHistoryRows(int $adminId): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) $conn->fetchOne('SELECT COUNT(*) FROM admin_login_history WHERE admin_id = ?', [$adminId]);
    }

    // AC1: an interactive admin login records exactly one admin_login_history row for that admin.
    public function testAdminLoginCreatesHistoryRow(): void
    {
        $adminId = $this->createAdmin('adminlh1@example.com');

        $this->loginAs('adminlh1@example.com', 'AdminHistoryAgent/1.0');

        self::assertSame(1, $this->countHistoryRows($adminId));
    }

    // AC5/AC7: the recent-logins page shows the logged-in admin's own entries (ip, ua, timestamp).
    public function testRecentLoginsPageShowsOwnEntries(): void
    {
        $adminId = $this->createAdmin('adminlh1@example.com');
        $this->insertHistoryRow($adminId, '203.0.113.9', 'AdminSeededAgent/ViewTest/2.0');

        $this->loginAs('adminlh1@example.com', 'AdminLoginAgent/1.0');
        $this->client->request('GET', '/admin/login-history');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('203.0.113.9', $content);
        $this->assertStringContainsString('AdminSeededAgent/ViewTest/2.0', $content);
        $this->assertSelectorExists('.entry-time');
    }

    // AC5: an admin cannot see another admin's history.
    public function testAdminCannotSeeAnotherAdminsHistory(): void
    {
        $adminId1 = $this->createAdmin('adminlh1@example.com');
        $adminId2 = $this->createAdmin('adminlh2@example.com');

        $this->insertHistoryRow($adminId1, '198.51.100.1', 'AgentForAdmin1/1.0');
        $this->insertHistoryRow($adminId2, '198.51.100.2', 'AgentForAdmin2/1.0');

        $this->loginAs('adminlh1@example.com', 'AdminLoginAgent/1.0');
        $this->client->request('GET', '/admin/login-history');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('AgentForAdmin1/1.0', $content);
        $this->assertStringNotContainsString('AgentForAdmin2/1.0', $content);
    }

    // The page is behind the admin firewall — anonymous access redirects to the admin login.
    public function testPageRequiresAdminAuth(): void
    {
        $this->client->request('GET', '/admin/login-history');

        $this->assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
