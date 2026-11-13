<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-113 (review C20): the IP whitelist must apply to magic-link login,
 * not only to form login. Before the fix IpWhitelistListener early-returned
 * unless the authenticator was FormLoginAuthenticator, so a magic link
 * bypassed both the global ip_whitelist.user_ips policy and a per-user
 * allowedIps restriction.
 *
 * The WebTestCase client's IP is 127.0.0.1; allow/deny is controlled by the
 * whitelist value (a whitelist that excludes 127.0.0.1 blocks the login).
 */
final class MagicLinkIpWhitelistTest extends WebTestCase
{
    private const EMAIL = 'mlipw@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();

        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('Magic Link IP Whitelist User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
            $this->conn->executeStatement('DELETE FROM magic_link_tokens WHERE email = ?', [self::EMAIL]);
            $this->conn->executeStatement(
                "DELETE FROM config WHERE config_key IN ('ip_whitelist.user_ips', 'ip_whitelist.admin_ips')"
            );
            $this->em->clear();
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

    private function setAllowedIps(string $value): void
    {
        // The per-user override lives in the auth-ip-whitelist-bundle satellite `user_ip_whitelist`
        // (FEATURE-146), not on `user`. Upsert a row keyed by the user id.
        $userId = (int) $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->conn->executeStatement('DELETE FROM user_ip_whitelist WHERE user_id = ?', [$userId]);
        if (trim($value) !== '') {
            $this->conn->executeStatement(
                'INSERT INTO user_ip_whitelist (user_id, allowed_ips) VALUES (?, ?)',
                [$userId, $value]
            );
        }
    }

    /** Seed a fresh, valid magic-link token and return its plaintext. */
    private function seedValidToken(): string
    {
        $plaintext = bin2hex(random_bytes(16));
        $token = new MagicLinkToken(
            self::EMAIL,
            hash('sha256', $plaintext),
            new \DateTimeImmutable('+15 minutes')
        );
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintext;
    }

    private function assertNotAuthenticated(): void
    {
        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/staff/login');
    }

    // AC5 (allowed) + AC2: magic-link login from a whitelisted IP succeeds.
    public function testMagicLinkFromWhitelistedGlobalIpSucceeds(): void
    {
        $this->setConfig('ip_whitelist.user_ips', '127.0.0.1');
        $plaintext = $this->seedValidToken();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    // AC1 + AC2 + AC5 (blocked): magic-link login from a non-whitelisted IP is
    // rejected under the global user policy and leaves the user unauthenticated.
    public function testMagicLinkFromNonWhitelistedGlobalIpIsBlocked(): void
    {
        $this->setConfig('ip_whitelist.user_ips', '192.168.1.100');
        $plaintext = $this->seedValidToken();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/magic-link');
        $this->client->followRedirect();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'not allowed',
            $this->client->getResponse()->getContent()
        );

        $this->assertNotAuthenticated();
    }

    // AC3 (per-user restriction enforced): with no global policy, a per-user
    // allowedIps that excludes the client IP blocks the magic-link login.
    public function testMagicLinkEnforcesPerUserAllowedIps(): void
    {
        $this->setAllowedIps('192.168.1.100');
        $plaintext = $this->seedValidToken();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/magic-link');
        $this->client->followRedirect();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'not allowed',
            $this->client->getResponse()->getContent()
        );

        $this->assertNotAuthenticated();
    }

    // AC3 (per-user override allows): a per-user allowedIps that includes the
    // client IP lets the magic-link login through even when the global policy
    // would exclude it.
    public function testMagicLinkPerUserAllowedIpsOverridesGlobal(): void
    {
        $this->setConfig('ip_whitelist.user_ips', '192.168.1.100');
        $this->setAllowedIps('127.0.0.1');
        $plaintext = $this->seedValidToken();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }
}
