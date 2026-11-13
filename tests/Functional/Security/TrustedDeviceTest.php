<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TrustedDeviceTest extends WebTestCase
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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['trust_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['trust_%@example.com']);
            $conn->executeStatement("DELETE FROM config WHERE config_key = 'trusted_device.lifetime_days'");
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

    /**
     * Drives the full login -> 2FA challenge -> trust-device flow over the given scheme and
     * returns the TRUSTED_DEVICE cookie from the final response's Set-Cookie headers.
     *
     * The response headers (not the BrowserKit cookie jar) are inspected because the jar
     * silently discards a Secure cookie received over a non-secure (http) request, which
     * would make an over-http assertion meaningless.
     */
    private function trustDeviceOverScheme(string $email, string $secret, string $scheme): ?\Symfony\Component\HttpFoundation\Cookie
    {
        $base = $scheme . '://localhost';

        $this->client->request('GET', $base . '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect(); // /dashboard -> /2fa/challenge redirect
        $this->client->followRedirect(); // GET /2fa/challenge -> 200
        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $code = $this->getTotp()->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $form['_trust_device'] = '1';
        $this->client->submit($form);
        $this->assertResponseRedirects();

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'TRUSTED_DEVICE') {
                return $cookie;
            }
        }

        return null;
    }

    private function getTotp(): TotpService
    {
        return static::getContainer()->get(TotpService::class);
    }

    // AC1: 2FA challenge page has a 'trust this device' checkbox
    public function testChallengePageHasTrustCheckbox(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_ac1@example.com', 'Trust AC1 User', $secret);

        $this->loginUser('trust_ac1@example.com');
        $this->client->followRedirect(); // /dashboard → /2fa/challenge redirect

        $this->client->followRedirect(); // GET /2fa/challenge → 200

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_trust_device"]');
    }

    // AC2: Checking trust stores a signed trusted-device cookie
    public function testCheckingTrustStoresCookie(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_ac2@example.com', 'Trust AC2 User', $secret);

        $this->loginUser('trust_ac2@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $code = $totp->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $form['_trust_device'] = '1';
        $this->client->submit($form);

        // Should redirect to /dashboard
        $this->assertResponseRedirects();

        // TRUSTED_DEVICE cookie must be set
        $cookie = $this->client->getCookieJar()->get('TRUSTED_DEVICE');
        $this->assertNotNull($cookie, 'Expected TRUSTED_DEVICE cookie to be set after trusting device');

        // Cookie must have a future expiry
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
    }

    // AC3: Subsequent logins from trusted device skip the 2FA challenge
    public function testTrustedDeviceCookieSkipsChallenge(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_ac3@example.com', 'Trust AC3 User', $secret);

        // First login: pass challenge with trust=1
        $this->loginUser('trust_ac3@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $code = $totp->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $form['_trust_device'] = '1';
        $this->client->submit($form);

        // TRUSTED_DEVICE cookie is now in the jar
        $this->assertNotNull($this->client->getCookieJar()->get('TRUSTED_DEVICE'));

        // Logout
        $this->client->request('GET', '/logout');

        // Second login: same client still has TRUSTED_DEVICE cookie
        $this->loginUser('trust_ac3@example.com');
        // POST /login → 302 to /dashboard
        $this->client->followRedirect(); // GET /dashboard → listener sees trusted cookie → no redirect to /2fa/challenge
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }

    // AC4: Trusted device cookies expire after the configured duration
    public function testTrustedDeviceCookieExpiresAfterConfiguredDuration(): void
    {
        // Set lifetime to 2 days
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)",
            ['trusted_device.lifetime_days', '2']
        );

        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_ac4@example.com', 'Trust AC4 User', $secret);

        $this->loginUser('trust_ac4@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $code = $totp->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $form['_trust_device'] = '1';
        $this->client->submit($form);

        $cookie = $this->client->getCookieJar()->get('TRUSTED_DEVICE');
        $this->assertNotNull($cookie);

        $expectedExpiry = time() + 2 * 86400;
        $this->assertEqualsWithDelta($expectedExpiry, $cookie->getExpiresTime(), 120);
    }

    // FEATURE-132 (review C16): resetting/re-enrolling a user's 2FA (the totpSecret rotates)
    // invalidates a previously issued trusted-device cookie, so the next login must challenge again.
    public function testResetting2faInvalidatesTrustedDeviceCookie(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $user = $this->createUserWith2fa('trust_reset@example.com', 'Trust Reset User', $secret);

        // First login: pass the challenge and trust this device.
        $this->loginUser('trust_reset@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $totp->generateCode($secret);
        $form['_trust_device'] = '1';
        $this->client->submit($form);

        $this->assertNotNull(
            $this->client->getCookieJar()->get('TRUSTED_DEVICE'),
            'Expected TRUSTED_DEVICE cookie after trusting the device'
        );

        // Sanity: with the cookie present and the secret unchanged, a fresh login skips 2FA.
        $this->client->request('GET', '/logout');
        $this->loginUser('trust_reset@example.com');
        $this->client->followRedirect(); // /dashboard, no challenge
        $this->assertRouteSame('app_dashboard');

        // Admin resets the user's 2FA and the user re-enrols with a NEW secret. Simulated by
        // rotating the stored totpSecret (reset clears it; re-enroll mints a fresh one).
        $newSecret = $totp->generateSecret();
        $this->assertNotSame($secret, $newSecret);
        $reloaded = $this->em->getRepository(User::class)->findOneBy(['email' => 'trust_reset@example.com']);
        $this->enableTwoFactor($reloaded, $newSecret);
        $this->em->clear();

        // The old TRUSTED_DEVICE cookie is still in the jar but is now bound to the old secret.
        $this->client->request('GET', '/logout');
        $this->loginUser('trust_reset@example.com');
        $this->client->followRedirect(); // POST /login -> GET /dashboard
        $this->client->followRedirect(); // listener sees stale cookie -> GET /2fa/challenge
        $this->assertRouteSame('app_2fa_challenge');
    }

    // FEATURE-112 (review C15): over HTTPS the trusted-device cookie carries the Secure attribute
    public function testTrustedDeviceCookieIsSecureOverHttps(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_https@example.com', 'Trust HTTPS User', $secret);

        $cookie = $this->trustDeviceOverScheme('trust_https@example.com', $secret, 'https');

        $this->assertNotNull($cookie, 'Expected a TRUSTED_DEVICE Set-Cookie on the trust response');
        $this->assertTrue($cookie->isSecure(), 'TRUSTED_DEVICE cookie must be Secure over HTTPS');
        $this->assertTrue($cookie->isHttpOnly(), 'TRUSTED_DEVICE cookie must be HttpOnly');
        $this->assertSame('strict', strtolower((string) $cookie->getSameSite()));
    }

    // FEATURE-112 (review C15): over plain http the Secure attribute is NOT set (local testing unaffected)
    public function testTrustedDeviceCookieIsNotSecureOverHttp(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('trust_http@example.com', 'Trust HTTP User', $secret);

        $cookie = $this->trustDeviceOverScheme('trust_http@example.com', $secret, 'http');

        $this->assertNotNull($cookie, 'Expected a TRUSTED_DEVICE Set-Cookie on the trust response');
        $this->assertFalse($cookie->isSecure(), 'TRUSTED_DEVICE cookie must NOT be Secure over http');
        $this->assertTrue($cookie->isHttpOnly(), 'TRUSTED_DEVICE cookie must be HttpOnly');
    }
}
