<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IpWhitelistTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('ip_whitelist.user_ips', 'ip_whitelist.admin_ips')"
        );

        $this->removeTestUser();
        $this->removeTestAdmin();

        $user = new User();
        $user->setEmail('ipwhitelistuser@example.com');
        $user->setName('IP Whitelist User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);

        $admin = new Admin();
        $admin->setEmail('ipwhitelistadmin@example.com');
        $admin->setName('IP Whitelist Admin');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        $this->removeTestAdmin();
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('ip_whitelist.user_ips', 'ip_whitelist.admin_ips')"
        );
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'ipwhitelistuser@example.com']);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'ipwhitelistadmin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [$key, $value]
        );
    }

    public function testNonWhitelistedIpIsRejectedWithClearError(): void
    {
        // WebTestCase requests come from 127.0.0.1; set whitelist to a different IP
        $this->setConfig('ip_whitelist.user_ips', '192.168.1.100');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'ipwhitelistuser@example.com',
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'not allowed',
            $this->client->getResponse()->getContent()
        );
    }

    public function testWhitelistedIpProceedsNormally(): void
    {
        // Allow 127.0.0.1 — the WebTestCase client's IP
        $this->setConfig('ip_whitelist.user_ips', '127.0.0.1');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'ipwhitelistuser@example.com',
            'password' => 'testpassword',
        ]);

        $this->assertResponseRedirects('/dashboard');
    }

    public function testPerUserIpWhitelistOverridesGlobal(): void
    {
        // Global whitelist excludes 127.0.0.1 (the test client IP)
        $this->setConfig('ip_whitelist.user_ips', '192.168.1.100');

        // Give the specific user a per-user whitelist that includes 127.0.0.1. The override lives in the
        // auth-ip-whitelist-bundle satellite `user_ip_whitelist` (FEATURE-146), not on `user`.
        $userId = (int) $this->conn->fetchOne(
            'SELECT id FROM "user" WHERE email = ?',
            ['ipwhitelistuser@example.com']
        );
        $this->conn->executeStatement('DELETE FROM user_ip_whitelist WHERE user_id = ?', [$userId]);
        $this->conn->executeStatement(
            'INSERT INTO user_ip_whitelist (user_id, allowed_ips) VALUES (?, ?)',
            [$userId, '127.0.0.1']
        );

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'ipwhitelistuser@example.com',
            'password' => 'testpassword',
        ]);

        // Per-user override allows 127.0.0.1 even though global excludes it
        $this->assertResponseRedirects('/dashboard');
    }

    public function testAdminAndUserWhitelistsAreConfiguredIndependently(): void
    {
        // User whitelist excludes 127.0.0.1; admin whitelist allows it
        $this->setConfig('ip_whitelist.user_ips', '192.168.1.100');
        $this->setConfig('ip_whitelist.admin_ips', '127.0.0.1');

        // User login from 127.0.0.1 → blocked by user whitelist
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'ipwhitelistuser@example.com',
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect();

        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'not allowed',
            $this->client->getResponse()->getContent()
        );

        // Admin login from 127.0.0.1 → allowed by admin whitelist
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'ipwhitelistadmin@example.com',
            'password' => 'adminpassword',
        ]);

        $this->assertResponseRedirects('/admin/dashboard');
    }
}
