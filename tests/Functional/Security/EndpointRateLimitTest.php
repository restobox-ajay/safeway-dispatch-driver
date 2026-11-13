<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\TotpService;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-086 (H4) AC2/AC4: the non-login auth endpoints — forgot-password,
 * magic-link, resend-verification and the 2FA challenge — are rate limited via
 * App\Bundle\AuthSecurity\Security\EndpointRateLimiter using the shared rate_limit.* config.
 */
final class EndpointRateLimitTest extends WebTestCase
{
    use TwoFactorTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->resetState();

        $this->removeUser();
        $user = new User();
        $user->setEmail('eprate@example.com');
        $user->setName('Endpoint Rate Test');
        $user->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeUser();
        $this->resetState();
        parent::tearDown();
    }

    private function resetState(): void
    {
        $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $this->conn->executeStatement(
            "DELETE FROM config WHERE config_key IN ('rate_limit.max_attempts', 'rate_limit.window_seconds', 'email_verification.mode')"
        );
    }

    private function removeUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'eprate@example.com']);
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

    public function testForgotPasswordIsThrottled(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        // 3 allowed requests (each redirects to the neutral check page).
        for ($i = 0; $i < 3; $i++) {
            $this->client->request('GET', '/forgot-password');
            $this->client->submitForm('Send Reset Link', ['email' => 'eprate@example.com']);
            $this->assertResponseRedirects('/forgot-password/check');
        }

        // 4th request — count 3 >= 3, blocked with an inline error (no redirect).
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send Reset Link', ['email' => 'eprate@example.com']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('Too many requests', $this->client->getResponse()->getContent());
    }

    public function testMagicLinkIsThrottled(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        for ($i = 0; $i < 3; $i++) {
            $this->client->request('GET', '/magic-link');
            $this->client->submitForm('Send Magic Link', ['email' => 'eprate@example.com']);
            $this->assertResponseRedirects('/magic-link/check');
        }

        $this->client->request('GET', '/magic-link');
        $this->client->submitForm('Send Magic Link', ['email' => 'eprate@example.com']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('Too many requests', $this->client->getResponse()->getContent());
    }

    public function testResendVerificationIsThrottled(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        for ($i = 0; $i < 3; $i++) {
            $this->client->request('GET', '/resend-verification');
            $this->client->submitForm('Resend verification email', ['email' => 'eprate@example.com']);
            // Under the limit the request is processed and redirects to /login.
            $this->assertResponseRedirects('/staff/login');
        }

        // 4th request — blocked before CSRF; flashes an error and redirects to the form.
        $this->client->request('GET', '/resend-verification');
        $this->client->submitForm('Resend verification email', ['email' => 'eprate@example.com']);
        $this->assertResponseRedirects('/resend-verification');
        $this->client->followRedirect();

        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('Too many requests', $this->client->getResponse()->getContent());
    }

    public function testTwoFactorChallengeIsThrottled(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        // Seed a 2FA-enabled user and authenticate to the pending-challenge state.
        $secret = self::getContainer()->get(TotpService::class)->generateSecret();
        $this->removeUser();
        $user = new User();
        $user->setEmail('eprate@example.com');
        $user->setName('Endpoint Rate Test');
        $user->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->enableTwoFactor($user, $secret);
        $this->em->clear();

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => 'eprate@example.com', 'password' => 'correctpassword']);
        $this->client->followRedirect(); // /dashboard → redirect to /2fa/challenge
        $this->client->followRedirect(); // GET /2fa/challenge → 200
        $this->assertResponseIsSuccessful();

        // 3 wrong-code submissions are allowed (each shows an invalid-code error).
        for ($i = 0; $i < 3; $i++) {
            $form = $this->client->getCrawler()->selectButton('Verify')->form();
            $form['_code'] = '000000';
            $this->client->submit($form);
            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString('Too many attempts', $this->client->getResponse()->getContent());
        }

        // 4th submission — count 3 >= 3, blocked.
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = '000000';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('Too many attempts', $this->client->getResponse()->getContent());
    }
}
