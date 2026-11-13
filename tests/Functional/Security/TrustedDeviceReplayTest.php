<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FEATURE-128 (review C39): adversarial tests for the trusted-device 2FA-bypass cookie.
 *
 * The TRUSTED_DEVICE cookie is a bearer credential that skips the entire 2FA challenge, so it must
 * be un-replayable across users and un-forgeable. TrustedDeviceManager::isDeviceTrusted() binds the
 * cookie to the current user id AND to an HMAC over the user's binding token; these tests drive the
 * end-to-end behaviour (does the challenge fire?) rather than the manager in isolation, and each
 * fails if the id check or the HMAC check is removed/weakened.
 */
final class TrustedDeviceReplayTest extends WebTestCase
{
    use AuthenticationTestTrait;
    use \App\Tests\Support\TwoFactorTestTrait;

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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['replay_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['replay_%@example.com']);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function getTotp(): TotpService
    {
        return static::getContainer()->get(TotpService::class);
    }

    private function createUserWith2fa(string $email, string $secret): void
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Replay ' . $email);
        $user->setPassword(self::hashTestPassword('testpassword'));
        $this->em->persist($user);
        $this->em->flush();
        $this->enableTwoFactor($user, $secret);
        $this->em->clear();
    }

    /**
     * Logs in, completes the 2FA challenge with "trust this device" and leaves the resulting
     * TRUSTED_DEVICE cookie in the jar. Returns its raw value.
     */
    private function loginAndTrustDevice(string $email, string $secret): string
    {
        $this->loginUser($email);
        $this->client->followRedirect(); // /dashboard -> /2fa/challenge redirect
        $this->client->followRedirect(); // GET /2fa/challenge -> 200
        $this->assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $this->getTotp()->generateCode($secret);
        $form['_trust_device'] = '1';
        $this->client->submit($form);

        $cookie = $this->client->getCookieJar()->get('TRUSTED_DEVICE');
        $this->assertNotNull($cookie, 'Setup: expected a TRUSTED_DEVICE cookie after trusting the device');

        return (string) $cookie->getValue();
    }

    /** After a fresh login, returns the route the request settled on (dashboard = skipped, challenge = not). */
    private function routeAfterLogin(string $email): string
    {
        $this->loginUser($email);
        $this->client->followRedirect(); // POST /login -> GET /dashboard
        // If 2FA is NOT skipped, /dashboard bounces to /2fa/challenge; follow that too.
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }

        return (string) $this->client->getRequest()->attributes->get('_route');
    }

    // AC1: replaying ANOTHER user's trusted-device cookie must NOT skip the victim's 2FA challenge.
    public function testReplayingAnotherUsersTrustedCookieDoesNotSkip2fa(): void
    {
        $secretA = $this->getTotp()->generateSecret();
        $secretB = $this->getTotp()->generateSecret();
        $this->createUserWith2fa('replay_a@example.com', $secretA);
        $this->createUserWith2fa('replay_b@example.com', $secretB);

        // User A trusts this device; the TRUSTED_DEVICE cookie is bound to A's id.
        $this->loginAndTrustDevice('replay_a@example.com', $secretA);

        // Log A out (the TRUSTED_DEVICE cookie persists in the jar) and log in as B, who is now
        // presenting A's cookie. B must still be challenged for 2FA.
        $this->client->request('GET', '/logout');
        $this->assertSame(
            'app_2fa_challenge',
            $this->routeAfterLogin('replay_b@example.com'),
            "User B presenting user A's trusted-device cookie must NOT skip 2FA"
        );
    }

    // AC1: a tampered cookie (mutated HMAC) must NOT skip 2FA; the genuine cookie is the control.
    public function testTamperedTrustedCookieDoesNotSkip2fa(): void
    {
        $secret = $this->getTotp()->generateSecret();
        $this->createUserWith2fa('replay_c@example.com', $secret);

        $value = $this->loginAndTrustDevice('replay_c@example.com', $secret);

        // Control: the GENUINE cookie skips the challenge, proving the setup is valid.
        $this->client->request('GET', '/logout');
        $this->assertSame(
            'app_dashboard',
            $this->routeAfterLogin('replay_c@example.com'),
            'Control: the genuine trusted-device cookie should skip 2FA'
        );

        // Tamper: flip one character in the HMAC segment of userId:expires:hmac.
        $decoded = base64_decode($value, true);
        $this->assertIsString($decoded);
        [$uid, $exp, $hmac] = explode(':', $decoded, 3);
        $hmac[0] = $hmac[0] === '0' ? '1' : '0'; // stays valid hex, guaranteed different
        $tampered = base64_encode($uid . ':' . $exp . ':' . $hmac);
        $this->assertNotSame($value, $tampered);

        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->set(new BrowserKitCookie('TRUSTED_DEVICE', $tampered, null, '/', 'localhost'));

        $this->assertSame(
            'app_2fa_challenge',
            $this->routeAfterLogin('replay_c@example.com'),
            'A tampered trusted-device cookie must NOT skip 2FA'
        );
    }
}
