<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-128 (review C39): adversarial test for CIDR entries in the login IP whitelist.
 *
 * The existing IpWhitelistTest only uses single-IP entries. IpWhitelistListener matches with
 * IpUtils::checkIp, which is CIDR-aware, so a deployment can whitelist a whole range. These tests
 * configure a /24 CIDR and drive the login with a spoofed client IP (REMOTE_ADDR) inside and
 * outside the range, asserting the range boundary is enforced. They fail if the CIDR check is
 * removed or the client IP is no longer evaluated.
 */
final class IpWhitelistCidrTest extends WebTestCase
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
        $this->removeUser();

        $user = new User();
        $user->setEmail('cidruser@example.com');
        $user->setName('CIDR Whitelist User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeUser();
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('ip_whitelist.user_ips', 'ip_whitelist.admin_ips')"
        );
        parent::tearDown();
    }

    private function removeUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'cidruser@example.com']);
            if ($user) {
                $this->em->remove($user);
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

    private function attemptLoginFrom(string $ip): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'cidruser@example.com',
            'password' => 'testpassword',
        ], 'POST', ['REMOTE_ADDR' => $ip]);
    }

    // AC4: a client IP OUTSIDE the whitelisted CIDR is blocked with the clear error.
    public function testCidrWhitelistBlocksSpoofedIpOutsideRange(): void
    {
        $this->setConfig('ip_whitelist.user_ips', '203.0.113.0/24');

        // 198.51.100.7 is outside 203.0.113.0/24.
        $this->attemptLoginFrom('198.51.100.7');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('not allowed', (string) $this->client->getResponse()->getContent());
    }

    // AC4: a client IP INSIDE the whitelisted CIDR is allowed (proves the block is range-based, not blanket).
    public function testCidrWhitelistAllowsIpInsideRange(): void
    {
        $this->setConfig('ip_whitelist.user_ips', '203.0.113.0/24');

        // 203.0.113.9 is inside 203.0.113.0/24.
        $this->attemptLoginFrom('203.0.113.9');

        $this->assertResponseRedirects('/dashboard');
    }
}
