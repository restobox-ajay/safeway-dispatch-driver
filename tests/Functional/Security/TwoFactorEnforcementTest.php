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

final class TwoFactorEnforcementTest extends WebTestCase
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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['enforce_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['enforce_%@example.com']);
            $conn->executeStatement("DELETE FROM config WHERE config_key = ?", ['2fa.enforcement']);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $name): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(self::hashTestPassword('testpassword'));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
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

    // AC1: Admin config has a 2FA enforcement setting with values: off, optional, required
    public function testAdminConfigHas2faEnforcementSetting(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);

        // Default is 'optional' when no row exists
        $this->assertSame('optional', $configService->getString('2fa.enforcement', 'optional'));

        // Storing 'off' persists correctly
        $configService->set('2fa.enforcement', 'off');
        $this->assertSame('off', $configService->getString('2fa.enforcement', 'optional'));

        // Storing 'required' persists correctly
        $configService->set('2fa.enforcement', 'required');
        $this->assertSame('required', $configService->getString('2fa.enforcement', 'optional'));

        // Cross-check DB row
        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['2fa.enforcement']
        );
        $this->assertSame('required', $row);
    }

    // AC2: When required, a user without 2FA enabled is redirected to /account/2fa/setup after login
    public function testRequiredEnforcementRedirectsUserWithout2faToSetup(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('2fa.enforcement', 'required');

        $this->createUser('enforce_required@example.com', 'Required User');
        $this->loginUser('enforce_required@example.com');
        // POST /login → 302 to /dashboard (default_target_path)

        $this->client->followRedirect();
        // GET /dashboard → enforcement=required, no 2FA → 302 to /account/2fa/setup

        $this->assertResponseRedirects('/account/2fa/setup');
    }

    // AC3: When optional, 2FA setup is not forced
    public function testOptionalEnforcementDoesNotForce2faSetup(): void
    {
        // No config row stored; listener uses default 'optional'
        $this->createUser('enforce_optional@example.com', 'Optional User');
        $this->loginUser('enforce_optional@example.com');
        // POST /login → 302 to /dashboard

        $this->client->followRedirect();
        // GET /dashboard → enforcement=optional, no 2FA → no redirect

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }

    // AC4: When off, the 2FA challenge step is skipped even for users who have 2FA enabled
    public function testOffEnforcementSkipsChallengeEvenWhenEnabled(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('2fa.enforcement', 'off');

        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('enforce_off@example.com', 'Off 2FA User', $secret);

        $this->loginUser('enforce_off@example.com');
        // POST /login → 302 to /dashboard

        $this->client->followRedirect();
        // GET /dashboard → enforcement=off → 2FA challenge bypassed → 200

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }
}
