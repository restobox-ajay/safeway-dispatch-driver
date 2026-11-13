<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\ConfigService;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrustedIpTest extends WebTestCase
{
    use AuthenticationTestTrait;
    use TwoFactorTestTrait;

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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['trusted_ip_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['trusted_ip_%@example.com']);
            $conn->executeStatement('DELETE FROM config WHERE config_key = ?', ['2fa.trusted_ips']);
            $conn->executeStatement('DELETE FROM config WHERE config_key = ?', ['2fa.enforcement']);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUserWith2fa(string $email, string $name, string $secret): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(self::hashTestPassword('testpassword'));
        $this->em->persist($user);
        $this->em->flush();
        $this->enableTwoFactor($user, $secret);
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    // AC1: Admin config has a 2FA trusted IPs list
    public function testAdminConfigHas2faTrustedIpsList(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);

        // Default is empty string when no row exists
        $this->assertSame('', $configService->getString('2fa.trusted_ips', ''));

        // Storing a single IP persists correctly
        $configService->set('2fa.trusted_ips', '127.0.0.1');
        $this->assertSame('127.0.0.1', $configService->getString('2fa.trusted_ips', ''));

        // Storing a comma-separated list persists correctly
        $configService->set('2fa.trusted_ips', '10.0.0.1,192.168.1.0/24');
        $this->assertSame('10.0.0.1,192.168.1.0/24', $configService->getString('2fa.trusted_ips', ''));

        // Cross-check DB row
        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['2fa.trusted_ips']
        );
        $this->assertSame('10.0.0.1,192.168.1.0/24', $row);
    }

    // AC2: Login from a trusted IP skips the 2FA challenge
    public function testLoginFromTrustedIpSkipsChallenge(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        // Test client sends requests from 127.0.0.1
        $configService->set('2fa.trusted_ips', '127.0.0.1');

        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trusted_ip_hit@example.com', 'Trusted IP User', $secret);

        $this->loginUser('trusted_ip_hit@example.com');
        // POST /login → 302 to /dashboard

        $this->client->followRedirect();
        // GET /dashboard → IP is trusted → no challenge → 200

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }

    // AC3: Login from an IP not on the list requires the challenge as normal
    public function testLoginFromUntrustedIpRequiresChallenge(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        // Test client IP is 127.0.0.1 — 10.0.0.1 is not trusted for this client
        $configService->set('2fa.trusted_ips', '10.0.0.1');

        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trusted_ip_miss@example.com', 'Untrusted IP User', $secret);

        $this->loginUser('trusted_ip_miss@example.com');
        // POST /login → 302 to /dashboard

        $this->client->followRedirect();
        // GET /dashboard → IP not trusted → 302 to /2fa/challenge

        $this->assertResponseRedirects('/2fa/challenge');
    }
}
