<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-123 (review C31): admins get real session tracking parity with users — interactive admin logins
 * are recorded in the dedicated admin_sessions table, an admin can view their OWN active sessions, and
 * "logout everywhere" terminates them. Stateless admin-api (PAT) requests create NO admin_sessions row
 * (AC4). Mirrors the user side (SessionManagementTest) while staying realm-isolated (ADR-003).
 */
final class AdminSessionManagementTest extends WebTestCase
{
    private const EMAIL = 'adminsess1@example.com';

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
            $this->conn->executeStatement('DELETE FROM admin_sessions');
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'adminsess%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email = self::EMAIL): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Admin Session Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $adminId = (int) $admin->getId();
        $this->em->clear();

        return $adminId;
    }

    private function loginAs(string $email = self::EMAIL, string $userAgent = 'AdminSessAgent/1.0'): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'adminpassword',
        ]);
    }

    private function countSessions(int $adminId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ?', [$adminId]);
    }

    // AC1: an interactive admin login records exactly one admin_sessions row for that admin.
    public function testAdminLoginCreatesSessionRow(): void
    {
        $adminId = $this->createAdmin();

        $this->loginAs();

        self::assertSame(1, $this->countSessions($adminId));
    }

    // AC2: the admin can view their own active sessions (IP, user-agent, last active).
    public function testSessionsPageListsActiveSessions(): void
    {
        $this->createAdmin();

        $this->loginAs(self::EMAIL, 'AdminViewAgent/2.0');
        $this->client->request('GET', '/admin/sessions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.session-ip');
        $this->assertSelectorExists('.session-ua');
        $this->assertSelectorExists('.session-last-active');
        self::assertStringContainsString('AdminViewAgent/2.0', (string) $this->client->getResponse()->getContent());
    }

    // AC2: the admin can terminate a specific OTHER session.
    public function testAdminCanTerminateSpecificOtherSession(): void
    {
        $adminId = $this->createAdmin();
        $this->loginAs();

        // Seed a second admin_sessions row (another device).
        $this->conn->insert('admin_sessions', [
            'session_id'     => 'admin-other-device-999',
            'admin_id'       => $adminId,
            'ip'             => '10.0.0.9',
            'user_agent'     => 'AdminOtherDevice/1.0',
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_active_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $otherId = (int) $this->conn->fetchOne('SELECT id FROM admin_sessions WHERE session_id = ?', ['admin-other-device-999']);
        $this->assertGreaterThan(0, $otherId);

        $crawler = $this->client->request('GET', '/admin/sessions');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="' . $otherId . '/terminate"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/sessions');
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE session_id = ?', ['admin-other-device-999']));
    }

    // A plain logout removes this session's row. The listener must read the session id before Symfony's
    // SessionLogoutListener invalidates (and so re-ids) the session, or the row is left behind as a phantom.
    public function testLogoutRemovesTheSessionRow(): void
    {
        $adminId = $this->createAdmin();
        $this->loginAs();
        self::assertSame(1, $this->countSessions($adminId));

        $this->client->request('GET', '/admin/logout');

        self::assertSame(0, $this->countSessions($adminId));
    }

    // AC2/AC5: "logout everywhere" deletes all of the admin's session rows and redirects to admin login.
    public function testLogoutEverywhereTerminatesAllSessions(): void
    {
        $adminId = $this->createAdmin();
        $this->loginAs();

        $this->assertGreaterThan(0, $this->countSessions($adminId));

        $crawler = $this->client->request('GET', '/admin/sessions');
        $form = $crawler->selectButton('Logout Everywhere')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/login');
        self::assertSame(0, $this->countSessions($adminId));
    }

    // AC5: after "logout everywhere", the old session is unauthenticated on the next request.
    public function testAfterLogoutEverywhereOldSessionIsUnauthenticated(): void
    {
        $this->createAdmin();
        $this->loginAs();

        $crawler = $this->client->request('GET', '/admin/sessions');
        $form = $crawler->selectButton('Logout Everywhere')->form();
        $this->client->submit($form);
        $this->client->followRedirect();

        // The AdminSessionRequestListener sees no matching admin_sessions row and invalidates the session.
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // AC4: a stateless admin-api (PAT) request creates NO admin_sessions row.
    public function testPatRequestCreatesNoAdminSession(): void
    {
        $adminId = $this->createAdmin();

        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->client->request('GET', '/admin-api/users', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext]);

        $this->assertResponseIsSuccessful();
        self::assertSame(0, $this->countSessions($adminId), 'PAT auth must not create an admin session record');
    }

    // The sessions page is behind the admin firewall — anonymous access redirects to the admin login.
    public function testPageRequiresAdminAuth(): void
    {
        $this->client->request('GET', '/admin/sessions');

        $this->assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
