<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuditLogTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = $this->em->getConnection();

        $this->cleanup();

        // User for login/logout/password-reset tests
        $this->createTestUser('auditlog-user@example.com', 'Audit User', 'userpass');

        // Admin for CRUD tests
        $this->createTestAdmin('auditlog-admin@example.com', 'Audit Admin');

        // Reset audit_log for a clean baseline in each test
        $this->conn->executeStatement('DELETE FROM audit_log');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement(
                "DELETE FROM \"user\" WHERE email LIKE 'auditlog-%@example.com'"
            );
            $this->conn->executeStatement(
                "DELETE FROM admin WHERE email LIKE 'auditlog-%@example.com'"
            );
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function countAuditEntries(?string $action = null, ?string $outcome = null): int
    {
        $sql = 'SELECT COUNT(*) FROM audit_log WHERE 1=1';
        $params = [];
        if ($action !== null) {
            $sql .= ' AND action = :action';
            $params['action'] = $action;
        }
        if ($outcome !== null) {
            $sql .= ' AND outcome = :outcome';
            $params['outcome'] = $outcome;
        }
        return (int) $this->conn->fetchOne($sql, $params);
    }

    private function loginAsUser(): void
    {
        $this->loginUser('auditlog-user@example.com', 'userpass');
    }

    // AC1: audit_log table exists with required columns
    public function testAuditLogTableHasRequiredColumns(): void
    {
        $columns = $this->conn->fetchAllAssociative('PRAGMA table_info(audit_log)');
        $names   = array_column($columns, 'name');

        $this->assertContains('actor', $names, 'audit_log must have actor column');
        $this->assertContains('actor_type', $names, 'audit_log must have actor_type column');
        $this->assertContains('ip', $names, 'audit_log must have ip column');
        $this->assertContains('action', $names, 'audit_log must have action column');
        $this->assertContains('outcome', $names, 'audit_log must have outcome column');
        $this->assertContains('created_at', $names, 'audit_log must have created_at column');
    }

    // AC2: Successful login creates an audit log entry
    public function testSuccessfulLoginCreatesAuditLogEntry(): void
    {
        $this->loginAsUser();

        $this->assertSame(1, $this->countAuditEntries('login', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'login' AND outcome = 'success'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-user@example.com', $row['actor']);
        $this->assertSame('user', $row['actor_type']);
    }

    // AC2: Failed login creates an audit log entry
    public function testFailedLoginCreatesAuditLogEntry(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'auditlog-user@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertSame(1, $this->countAuditEntries('login', 'failure'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'login' AND outcome = 'failure'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-user@example.com', $row['actor']);
    }

    // AC3: Logout creates an audit log entry
    public function testLogoutCreatesAuditLogEntry(): void
    {
        $this->loginAsUser();
        $this->conn->executeStatement('DELETE FROM audit_log'); // reset after login

        $this->client->request('GET', '/logout');

        $this->assertSame(1, $this->countAuditEntries('logout', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'logout'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-user@example.com', $row['actor']);
        $this->assertSame('user', $row['actor_type']);
        $this->assertSame('success', $row['outcome']);
    }

    // AC4: Registration creates an audit log entry
    public function testRegistrationCreatesAuditLogEntry(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'auditlog-newreg@example.com',
            'name'     => 'New Reg User',
            'password' => 'password123',
        ]);

        $this->assertSame(1, $this->countAuditEntries('register', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'register'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-newreg@example.com', $row['actor']);
        $this->assertSame('user', $row['actor_type']);
        $this->assertSame('success', $row['outcome']);
    }

    // AC5: Password reset request creates an audit log entry
    public function testPasswordResetRequestCreatesAuditLogEntry(): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send Reset Link', [
            'email' => 'auditlog-user@example.com',
        ]);

        $this->assertSame(1, $this->countAuditEntries('password_reset_request', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'password_reset_request'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-user@example.com', $row['actor']);
        $this->assertSame('user', $row['actor_type']);
    }

    // AC6: Admin user create creates an audit log entry
    public function testAdminUserCreateCreatesAuditLogEntry(): void
    {
        $this->loginAsAdmin('auditlog-admin@example.com', followRedirect: false);
        $this->conn->executeStatement('DELETE FROM audit_log'); // reset after admin login

        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create User', [
            'email'    => 'auditlog-created@example.com',
            'name'     => 'Created User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertSame(1, $this->countAuditEntries('admin.user_create', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'admin.user_create'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-admin@example.com', $row['actor']);
        $this->assertSame('admin', $row['actor_type']);
    }

    // AC6: Admin user edit creates an audit log entry
    public function testAdminUserEditCreatesAuditLogEntry(): void
    {
        // Create target user
        $userId = $this->createTestUser('auditlog-edittarget@example.com', 'Edit Target', 'pass')->getId();

        $this->loginAsAdmin('auditlog-admin@example.com', followRedirect: false);
        $this->conn->executeStatement('DELETE FROM audit_log'); // reset after admin login

        $this->client->request('GET', '/admin/users/' . $userId . '/edit');
        $this->client->submitForm('Save Changes', [
            'email'  => 'auditlog-edittarget@example.com',
            'name'   => 'Edited Name',
            'role'   => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->assertSame(1, $this->countAuditEntries('admin.user_edit', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'admin.user_edit'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-admin@example.com', $row['actor']);
        $this->assertSame('admin', $row['actor_type']);
    }

    // AC6: Admin user delete creates an audit log entry
    public function testAdminUserDeleteCreatesAuditLogEntry(): void
    {
        // Create target user
        $userId = $this->createTestUser('auditlog-deletetarget@example.com', 'Delete Target', 'pass')->getId();

        $this->loginAsAdmin('auditlog-admin@example.com', followRedirect: false);
        $this->conn->executeStatement('DELETE FROM audit_log'); // reset after admin login

        // Submit the delete form via the list page
        $crawler = $this->client->request('GET', '/admin/users');
        $form    = $crawler->filter('form[action="/admin/users/' . $userId . '/delete"]')->form();
        $this->client->submit($form);

        $this->assertSame(1, $this->countAuditEntries('admin.user_delete', 'success'));

        $row = $this->conn->fetchAssociative(
            "SELECT * FROM audit_log WHERE action = 'admin.user_delete'"
        );
        $this->assertNotFalse($row);
        $this->assertSame('auditlog-admin@example.com', $row['actor']);
        $this->assertSame('admin', $row['actor_type']);
    }
}
