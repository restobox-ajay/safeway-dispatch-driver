<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Entity\User;
use App\Security\SafeRedirect;
use App\Service\TotpService;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-131 (review C17): the post-2FA redirect must only ever go to a same-origin LOCAL
 * path. The challenge listener stores getRequestUri() (path, no host) and the controller
 * re-validates it through SafeRedirect, so a spoofed Host / crafted request URI cannot turn
 * the post-2FA redirect into an off-origin one.
 */
final class TwoFactorRedirectTargetTest extends WebTestCase
{
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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['2fa_redir_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM magic_link_tokens WHERE email LIKE ?', ['2fa_redir_%@example.com']);
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['2fa_redir_%@example.com']);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUserWith2fa(string $email, string $secret): void
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Redirect User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->enableTwoFactor($user, $secret);
        $this->em->clear();
    }

    private function loginAndReachChallenge(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => 'testpassword']);
        $this->client->followRedirect(); // /dashboard -> 302 /2fa/challenge (listener stores target)
        $this->client->followRedirect(); // GET /2fa/challenge -> 200
    }

    public function testStoredTargetIsALocalPathAndPostChallengeRedirectIsSameOrigin(): void
    {
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_redir_local@example.com', $secret);

        $this->loginAndReachChallenge('2fa_redir_local@example.com');

        // The listener must have stored a same-origin LOCAL path, never an absolute URL.
        $stored = $this->client->getRequest()->getSession()->get('_2fa_target_url');
        self::assertIsString($stored);
        self::assertTrue(
            SafeRedirect::isLocalPath($stored),
            sprintf('Stored 2FA target "%s" must be a same-origin local path.', $stored)
        );
        self::assertSame('/dashboard', $stored);

        $code = $totp->generateCode($secret);
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);

        // Redirect target is the local path, not an absolute/off-origin URL.
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame('/dashboard', $location);
    }

    public function testPoisonedTargetIsIgnoredAndFallsBackToDashboard(): void
    {
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_redir_poison@example.com', $secret);

        $this->loginAndReachChallenge('2fa_redir_poison@example.com');

        // Simulate a poisoned target reaching the session (e.g. via a crafted request URI):
        // the controller must NOT honour a protocol-relative, off-origin value.
        $session = $this->client->getRequest()->getSession();
        $session->set('_2fa_target_url', '//evil.example.com/pwn');
        $session->save();

        $code = $totp->generateCode($secret);
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('/dashboard', $location);
        self::assertStringNotContainsString('evil.example.com', $location);
    }

    // Issue #60: a magic-link sign-in that owes the 2FA challenge must not store the one-shot verify URL (with its
    // plaintext token) as the post-challenge target. Going back there re-ran the authenticator on a used token:
    // "This magic link has already been used." shown to a logged-in user, plus a spurious failed-login audit row.
    public function testMagicLinkSignInContinuesToTheDashboardAfterTheChallenge(): void
    {
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_redir_magic@example.com', $secret);

        $plaintext = bin2hex(random_bytes(16));
        $this->em->persist(new MagicLinkToken('2fa_redir_magic@example.com', hash('sha256', $plaintext), new \DateTimeImmutable('+15 minutes')));
        $this->em->flush();
        $this->em->clear();

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $failuresBefore = (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND outcome = 'failure'");

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);
        self::assertResponseRedirects('/2fa/challenge');
        $stored = $this->client->getRequest()->getSession()->get('_2fa_target_url');
        self::assertFalse(is_string($stored) && str_contains($stored, $plaintext), 'the plaintext token must not be parked in the session');

        $this->client->followRedirect();
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $totp->generateCode($secret);
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($failuresBefore, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND outcome = 'failure'"));
    }
}
