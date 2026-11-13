<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginHistoryTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTestUser();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $conn = self::getContainer()->get('doctrine.dbal.default_connection');
            $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', ['loginhistory@example.com']);
            if ($userId !== false) {
                $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
            }
            $this->em->clear();
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }

    public function testLoginHistoryTableHasRequiredColumns(): void
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $columns = $conn->executeQuery('PRAGMA table_info(login_history)')->fetchAllAssociative();
        $names = array_column($columns, 'name');

        $this->assertContains('id', $names, 'login_history must have id column');
        $this->assertContains('user_id', $names, 'login_history must have user_id column');
        $this->assertContains('ip', $names, 'login_history must have ip column');
        $this->assertContains('user_agent', $names, 'login_history must have user_agent column');
        $this->assertContains('fingerprint', $names, 'login_history must have fingerprint column');
        $this->assertContains('created_at', $names, 'login_history must have created_at column');
    }

    public function testSuccessfulLoginCreatesLoginHistoryRow(): void
    {
        $userId = (int) $this->createTestUser('loginhistory@example.com', 'Login History Test')->getId();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'loginhistory@example.com',
            'password' => 'testpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM login_history WHERE user_id = ?',
            [$userId]
        );
        $this->assertGreaterThan(0, $count, 'A login history row must be created on successful login');
    }

    public function testFingerprintIsSha256OfIpAndUserAgent(): void
    {
        $userAgent = 'TestBrowser/FingerprintTest/1.0';
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);

        $userId = (int) $this->createTestUser('loginhistory@example.com', 'Login History Test')->getId();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'loginhistory@example.com',
            'password' => 'testpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative(
            'SELECT ip, fingerprint FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );

        $this->assertNotFalse($row, 'A login history row must exist after login');
        $expectedFingerprint = hash('sha256', $row['ip'] . $userAgent);
        $this->assertSame($expectedFingerprint, $row['fingerprint'], 'Fingerprint must be SHA-256(ip + user_agent)');
    }

    public function testFailedLoginDoesNotCreateHistoryRow(): void
    {
        $userId = (int) $this->createTestUser('loginhistory@example.com', 'Login History Test')->getId();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'loginhistory@example.com',
            'password' => 'wrongpassword',
        ]);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM login_history WHERE user_id = ?',
            [$userId]
        );
        $this->assertSame(0, $count, 'Failed login must not create a login history row');
    }
}
