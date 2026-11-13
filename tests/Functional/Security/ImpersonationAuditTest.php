<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImpersonationAuditTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $this->createTestAdmin('impaudit_admin@example.com', 'Impersonation Audit Admin');
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
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'impaudit_%'");
            $conn->executeStatement("DELETE FROM admin WHERE email = 'impaudit_admin@example.com'");
            $conn->executeStatement("DELETE FROM audit_log WHERE actor = 'impaudit_admin@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): User
    {
        return $this->createTestUser($email, 'Impersonation Target', 'userpass');
    }

    private function doImpersonate(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $userId . '/impersonate-start"]')->form();
        $this->client->submit($form);
    }

    // AC1: Starting impersonation creates an audit log entry with actor (impersonator) and target (impersonated)
    public function testStartingImpersonationCreatesAuditLogEntryWithActorAndTarget(): void
    {
        $user = $this->createUser('impaudit_user1@example.com');

        $this->loginAsAdmin('impaudit_admin@example.com');

        $conn       = $this->em->getConnection();
        $countBefore = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.impersonate_start' AND actor = 'impaudit_admin@example.com'"
        );

        $this->doImpersonate((int) $user->getId());

        $countAfter = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.impersonate_start' AND actor = 'impaudit_admin@example.com'"
        );

        $this->assertSame($countBefore + 1, $countAfter, 'Audit log entry should be created for impersonation start');

        $row = $conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'admin.impersonate_start' AND actor = 'impaudit_admin@example.com' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotFalse($row);
        $this->assertSame('impaudit_admin@example.com', $row['actor']);
        $this->assertSame('admin', $row['actor_type']);
        $this->assertSame('impaudit_user1@example.com', $row['context']);
    }

    // AC2: Exiting impersonation creates an audit log entry
    public function testExitingImpersonationCreatesAuditLogEntry(): void
    {
        $user = $this->createUser('impaudit_user2@example.com');

        $this->loginAsAdmin('impaudit_admin@example.com');
        $this->doImpersonate((int) $user->getId());

        // Follow redirect chain to reach /dashboard as impersonated user
        $this->client->followRedirect(); // to /impersonate/start → authenticator runs → /dashboard
        $this->client->followRedirect(); // to /dashboard

        $conn        = $this->em->getConnection();
        $countBefore = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.impersonate_exit'"
        );

        // Submit the exit form from the impersonation banner
        $crawler  = $this->client->getCrawler();
        $exitForm = $crawler->filter('form[action="/impersonate/exit"]')->form();
        $this->client->submit($exitForm);

        $countAfter = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.impersonate_exit'"
        );

        $this->assertSame($countBefore + 1, $countAfter, 'Audit log entry should be created for impersonation exit');

        $row = $conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'admin.impersonate_exit' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotFalse($row);
        $this->assertSame('impaudit_admin@example.com', $row['actor']);
        $this->assertSame('admin', $row['actor_type']);
        $this->assertSame('impaudit_user2@example.com', $row['context']);
    }

    // AC3: Impersonation audit entries are visible in /admin/audit-log
    public function testImpersonationAuditEntriesAreVisibleInAdminAuditLog(): void
    {
        $user = $this->createUser('impaudit_user3@example.com');

        $this->loginAsAdmin('impaudit_admin@example.com');
        $this->doImpersonate((int) $user->getId());

        // Re-login as admin to access the audit log (impersonation switches to user firewall).
        // Log out the admin firewall first so GET /admin/login isn't bounced by the
        // already-authenticated redirect.
        $this->client->request('GET', '/admin/logout');
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'impaudit_admin@example.com',
            'password' => 'adminpass',
        ]);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/audit-log');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('admin.impersonate_start', $content);
    }
}
