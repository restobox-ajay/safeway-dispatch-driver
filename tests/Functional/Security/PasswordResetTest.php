<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\ConfigAwareRememberMeHandler;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

final class PasswordResetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $user = new User();
        $user->setEmail('resettest@example.com');
        $user->setName('Reset Test');
        $user->setPassword(password_hash('oldpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        try {
            $this->em->createQuery('DELETE FROM App\Entity\PasswordResetToken t WHERE t.email = :email')
                ->setParameter('email', 'resettest@example.com')
                ->execute();
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'resettest@example.com']);
            if ($user) {
                $conn = $this->em->getConnection();
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$user->getId()]);
                $this->em->remove($user);
                $this->em->flush();
            }
            // Forgot-password POSTs record a rate-limit attempt keyed on the test
            // client IP, and other suites accumulate login_attempts from the same
            // IP; clear both so this file's POSTs/logins stay self-contained.
            $this->em->getConnection()->executeStatement('DELETE FROM endpoint_rate_limits');
            $this->em->getConnection()->executeStatement('DELETE FROM login_attempts');
            $this->em->getConnection()->executeStatement(
                "DELETE FROM config WHERE config_key = 'remember_me.lifetime_days'"
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    /**
     * Fetch the live CSRF token rendered into the forgot-password form so a POST
     * passes validation (the same browser session is reused by the client).
     */
    private function forgotPasswordCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/forgot-password');

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    /** AC1: GET /forgot-password renders an email request form */
    public function testForgotPasswordFormRenders(): void
    {
        $this->client->request('GET', '/forgot-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    /** AC2: POST with registered email shows same confirmation; token saved to DB */
    public function testForgotPasswordWithRegisteredEmailShowsConfirmation(): void
    {
        $token = $this->forgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'email'  => 'resettest@example.com',
            '_token' => $token,
        ]);

        // Always redirects to the check page (no enumeration)
        $this->assertResponseRedirects('/forgot-password/check');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // A token was created in the DB for this email
        $token = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['email' => 'resettest@example.com']);
        $this->assertNotNull($token, 'A password reset token should have been created');
        $this->assertTrue($token->isValid(), 'The token should be valid (not expired, not used)');
    }

    /** AC2: POST with unregistered email shows the same confirmation (no enumeration) */
    public function testForgotPasswordWithUnregisteredEmailShowsSameConfirmation(): void
    {
        $token = $this->forgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'email'  => 'nobody@example.com',
            '_token' => $token,
        ]);

        // Same redirect — no indication that the email doesn't exist
        $this->assertResponseRedirects('/forgot-password/check');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // No token was created
        $token = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['email' => 'nobody@example.com']);
        $this->assertNull($token, 'No token should be created for an unregistered email');
    }

    /** AC3: Clicking the reset link shows a form to enter a new password */
    public function testResetPasswordFormRendersForValidToken(): void
    {
        $plaintextToken = $this->createValidToken('resettest@example.com');

        $this->client->request('GET', '/reset-password/' . $plaintextToken);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="password"]');
    }

    /** AC4: Submitting a valid new password updates the hashed password and invalidates the token */
    public function testResetPasswordUpdatesPasswordAndInvalidatesToken(): void
    {
        $plaintextToken = $this->createValidToken('resettest@example.com');
        $before = $this->auditRows('resettest@example.com', 'password_reset');

        $this->client->request('POST', '/reset-password/' . $plaintextToken, ['password' => 'newpassword123']);

        // Redirects to login after successful reset
        $this->assertResponseRedirects('/staff/login');
        $this->assertSame($before + 1, $this->auditRows('resettest@example.com', 'password_reset'), 'the completed reset is audited (issue #18)');

        // Password was actually changed
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'resettest@example.com']);
        $this->assertNotNull($user);
        $this->assertTrue(
            password_verify('newpassword123', $user->getPassword()),
            'Password should be updated to the new value'
        );

        // Token is now marked as used
        $tokenHash = hash('sha256', $plaintextToken);
        $resetToken = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $tokenHash]);
        $this->assertNotNull($resetToken->getUsedAt(), 'Token should be marked as used');
    }

    /** AC5: Expired token is rejected gracefully */
    public function testExpiredTokenIsRejectedGracefully(): void
    {
        $plaintextToken = $this->createExpiredToken('resettest@example.com');

        $this->client->request('GET', '/reset-password/' . $plaintextToken);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertSelectorNotExists('input[name="password"]');
    }

    /** AC5: Already-used token is rejected gracefully */
    public function testAlreadyUsedTokenIsRejectedGracefully(): void
    {
        $plaintextToken = $this->createUsedToken('resettest@example.com');

        $this->client->request('GET', '/reset-password/' . $plaintextToken);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertSelectorNotExists('input[name="password"]');
    }

    /** FEATURE-087 AC1: forgot-password POST without a valid CSRF token is rejected */
    public function testForgotPasswordRejectsMissingCsrfToken(): void
    {
        // No _token field — CSRF validation must fail.
        $this->client->request('POST', '/forgot-password', ['email' => 'resettest@example.com']);

        // Stays on the form with an error rather than redirecting to the check page.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // No reset token was created.
        $token = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['email' => 'resettest@example.com']);
        $this->assertNull($token, 'No reset token should be created when CSRF validation fails');
    }

    /** FEATURE-087 AC2: a successful reset invalidates other unused tokens for the email */
    public function testSuccessfulResetInvalidatesOtherUnusedTokens(): void
    {
        $usedToken = $this->createValidToken('resettest@example.com');
        $siblingPlaintext = $this->createValidToken('resettest@example.com');
        $siblingHash = hash('sha256', $siblingPlaintext);

        $this->client->request('POST', '/reset-password/' . $usedToken, ['password' => 'newpassword123']);
        $this->assertResponseRedirects('/staff/login');

        $this->em->clear();
        $sibling = $this->em->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $siblingHash]);
        $this->assertNotNull($sibling);
        $this->assertNotNull($sibling->getUsedAt(), 'Sibling token should be marked used after a reset');
        $this->assertFalse($sibling->isValid(), 'Sibling token should no longer be valid after a reset');
    }

    /** FEATURE-087 AC3: a successful reset terminates the user's active sessions */
    public function testSuccessfulResetInvalidatesActiveSessions(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'resettest@example.com']);
        $userId = $user->getId();

        // Seed an active session cross-reference row for this user.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO user_sessions (session_id, user_id, ip, user_agent, created_at, last_active_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            ['sess-' . $userId, $userId, '203.0.113.7', 'TestAgent', '2026-06-21 10:00:00', '2026-06-21 10:00:00']
        );

        $plaintextToken = $this->createValidToken('resettest@example.com');
        $this->client->request('POST', '/reset-password/' . $plaintextToken, ['password' => 'newpassword123']);
        $this->assertResponseRedirects('/staff/login');

        $remaining = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = ?',
            [$userId]
        );
        $this->assertSame(0, $remaining, "User's active sessions should be terminated after a password reset");
    }

    /**
     * FEATURE-087 AC3: a successful reset invalidates previously issued remember-me cookies.
     *
     * Drives the real ConfigAwareRememberMeHandler — whose HMAC is bound to the user's
     * password hash — rather than a full form login, so the assertion does not depend on
     * global login gates (2FA enforcement, password expiry) or shared rate-limit state.
     * disableReboot() keeps a single EntityManager so the controller's password change is
     * visible to the handler's subsequent verification.
     */
    public function testSuccessfulResetInvalidatesRememberMe(): void
    {
        $this->client->disableReboot();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        /** @var UserRepository $userRepository */
        $userRepository = self::getContainer()->get(UserRepository::class);

        // The handler is inlined into the firewall at compile time and not fetchable
        // from the container, so build the real class with its real collaborators.
        $handler = new ConfigAwareRememberMeHandler(
            $userRepository,
            $requestStack,
            self::getContainer()->get(ConfigService::class),
            (string) self::getContainer()->getParameter('kernel.secret'),
        );

        // A request must be on the stack for the handler to emit its cookie attribute.
        $requestStack->push(Request::create('/'));

        $user = $userRepository->findByEmail('resettest@example.com');

        // Issue a remember-me cookie bound to the current (old) password.
        $handler->createRememberMeCookie($user);
        $cookie = $requestStack->getMainRequest()->attributes->get(ResponseListener::COOKIE_ATTR_NAME);
        $this->assertNotNull($cookie, 'Handler should have emitted a REMEMBERME cookie');
        $details = RememberMeDetails::fromRawCookie($cookie->getValue());

        // Sanity: the cookie authenticates while the password is unchanged.
        $this->assertSame(
            'resettest@example.com',
            $handler->consumeRememberMeCookie($details)->getUserIdentifier(),
            'Cookie should authenticate before the password changes'
        );

        // Reset the password through the real flow (changes the hash the HMAC is bound to).
        $plaintextToken = $this->createValidToken('resettest@example.com');
        $this->client->request('POST', '/reset-password/' . $plaintextToken, ['password' => 'newpassword123']);
        $this->assertResponseRedirects('/staff/login');

        // The same cookie no longer authenticates: its HMAC was bound to the old password.
        $this->expectException(AuthenticationException::class);
        $handler->consumeRememberMeCookie($details);
    }

    private function createValidToken(string $email): string
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintextToken);
        $token = new PasswordResetToken($email, $tokenHash, new \DateTimeImmutable('+1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintextToken;
    }

    private function createExpiredToken(string $email): string
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintextToken);
        $token = new PasswordResetToken($email, $tokenHash, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintextToken;
    }

    private function createUsedToken(string $email): string
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintextToken);
        $token = new PasswordResetToken($email, $tokenHash, new \DateTimeImmutable('+1 hour'));
        $token->markUsed();
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintextToken;
    }

    /** Issue #18: audit rows written for one actor+action (the request-time count, so earlier rows can't fake a pass). */
    private function auditRows(string $actor, string $action, ?string $context = null): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) ($context === null
            ? $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success'", [$actor, $action])
            : $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success' AND context = ?", [$actor, $action, $context]));
    }
}
