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

final class TwoFactorAuthTest extends WebTestCase
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
            $userIds = $conn->fetchFirstColumn('SELECT id FROM "user" WHERE email LIKE ?', ['2fa_%@example.com']);
            foreach ($userIds as $id) {
                $conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM two_factor_settings WHERE user_id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['2fa_%@example.com']);
            $conn->executeStatement('DELETE FROM admin WHERE email LIKE ?', ['2fa_%@example.com']);
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

    private function getTotp(): TotpService
    {
        return static::getContainer()->get(TotpService::class);
    }

    // AC1: GET /account/2fa/setup renders a QR code and the TOTP secret
    public function testSetupPageRendersQrCodeAndSecret(): void
    {
        $this->createUser('2fa_setup@example.com', 'Setup User');
        $this->loginUser('2fa_setup@example.com');

        $crawler = $this->client->request('GET', '/account/2fa/setup');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('img.qr-code');
        $this->assertSelectorExists('.totp-secret');

        // QR code must be a data URI (SVG image)
        $imgSrc = $crawler->filter('img.qr-code')->attr('src');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $imgSrc);

        // Secret must be non-empty base32 string
        $secret = $crawler->filter('.totp-secret')->text();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20,}$/', $secret);
    }

    // AC2 (valid): User must enter a valid TOTP code to confirm 2FA setup
    public function testUserMustEnterValidTotpCodeToConfirmSetup(): void
    {
        $this->createUser('2fa_confirm@example.com', 'Confirm User');
        $this->loginUser('2fa_confirm@example.com');

        // GET setup page — generates temp secret and stores in session
        $crawler = $this->client->request('GET', '/account/2fa/setup');
        $this->assertResponseIsSuccessful();

        // Extract the secret displayed on the page (same as session temp secret)
        $secret = trim($crawler->filter('.totp-secret')->text());

        // Generate a valid code for this secret
        $code = $this->getTotp()->generateCode($secret);

        // Submit the form with the valid code
        $form = $crawler->selectButton('Verify and Enable 2FA')->form();
        $form['_code'] = $code;
        $before = $this->auditRows('2fa_confirm@example.com', '2fa_enable', 'enrolled');
        $this->client->submit($form);

        $this->assertResponseRedirects('/account/settings');
        $this->assertSame($before + 1, $this->auditRows('2fa_confirm@example.com', '2fa_enable', 'enrolled'), 'enrolment is audited (issue #18)');

        // Verify 2FA is now enabled in the database
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative('SELECT is_totp_enabled, totp_secret FROM two_factor_settings WHERE user_id = (SELECT id FROM "user" WHERE email = ?)', ['2fa_confirm@example.com']);
        $this->assertSame('1', (string) $row['is_totp_enabled']);
        $this->assertSame($secret, $row['totp_secret']);
    }

    // AC2 (invalid): Invalid TOTP code shows an error and does not enable 2FA
    public function testConfirmSetupWithInvalidCodeShowsError(): void
    {
        $this->createUser('2fa_invalid@example.com', 'Invalid Code User');
        $this->loginUser('2fa_invalid@example.com');

        // GET setup page
        $crawler = $this->client->request('GET', '/account/2fa/setup');
        $this->assertResponseIsSuccessful();

        // Submit an obviously wrong code
        $form = $crawler->selectButton('Verify and Enable 2FA')->form();
        $form['_code'] = '000000';
        $this->client->submit($form);

        // Should stay on setup page with an error
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // 2FA should NOT be enabled
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        // An invalid setup code never creates a satellite row, so "no row" == disabled.
        $enabled = $conn->fetchOne('SELECT is_totp_enabled FROM two_factor_settings WHERE user_id = (SELECT id FROM "user" WHERE email = ?)', ['2fa_invalid@example.com']);
        $this->assertSame(0, (int) $enabled);
    }

    // AC3: After enabling 2FA, successful password login redirects to a TOTP challenge page
    public function testLoginWith2faEnabledRedirectsToChallengePage(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_redir@example.com', 'Redirect User', $secret);

        $this->loginUser('2fa_redir@example.com');
        // POST /login → 302 to /dashboard (Symfony's default_target_path)

        $this->client->followRedirect();
        // GET /dashboard → TwoFactorChallengeListener intercepts → 302 to /2fa/challenge

        $this->assertResponseRedirects('/2fa/challenge');
    }

    // AC4: A valid TOTP code on the challenge page grants access
    public function testValidTotpCodeGrantsAccess(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_access@example.com', 'Access User', $secret);

        $this->loginUser('2fa_access@example.com');
        $this->client->followRedirect(); // /dashboard → /2fa/challenge redirect

        // Now at /2fa/challenge response (302)
        $this->client->followRedirect(); // Follow to GET /2fa/challenge → 200

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_2fa_challenge');

        // Submit valid TOTP code
        $crawler = $this->client->getCrawler();
        $code = $totp->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);

        // Should redirect to /dashboard (the originally intended URL)
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }

    // Security (H3): a TOTP code that was already accepted cannot be replayed in a later
    // challenge, even within its ~90s validity window.
    public function testReplayedTotpCodeIsRejected(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_replay@example.com', 'Replay User', $secret);

        $code = $totp->generateCode($secret);

        // First login: the code is accepted and consumed.
        $this->loginUser('2fa_replay@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertRouteSame('app_dashboard');

        // Log out, then replay the SAME code (still within its window).
        $this->client->request('GET', '/logout');
        $this->loginUser('2fa_replay@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);

        // Rejected: stays on the challenge page with an error, not on the dashboard.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertRouteSame('app_2fa_challenge');
    }

    // Security: a passed challenge belongs to the account that passed it. Someone who signs in to their
    // own account, passes their own 2FA, then submits the login form with another user's password in the
    // same session (no logout) must still be challenged for the other user's code.
    public function testPassedChallengeDoesNotCarryOverToAnotherAccountInTheSameSession(): void
    {
        $totp = $this->getTotp();
        $attackerSecret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_attacker@example.com', 'Attacker', $attackerSecret);
        $this->createUserWith2fa('2fa_victim@example.com', 'Victim', $totp->generateSecret());

        $this->loginUser('2fa_attacker@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $totp->generateCode($attackerSecret);
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertRouteSame('app_dashboard');

        // Same session, no logout: the login form with the victim's password.
        $this->client->request('POST', '/staff/login', ['email' => '2fa_victim@example.com', 'password' => 'testpassword']);
        $this->assertResponseRedirects('/dashboard', null, 'the victim password is accepted');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/2fa/challenge');
    }

    // Security: the "impersonating" skip belongs to the impersonated user. An admin who impersonates user
    // B and then signs in as enrolled user C in the same session must be challenged for C's code.
    public function testImpersonationFlagDoesNotSkipTheChallengeForAnotherUser(): void
    {
        $totp = $this->getTotp();
        $this->createTestAdmin('2fa_impadmin@example.com');
        // The target is enrolled too, so this also proves impersonating it still skips ITS challenge.
        $target = $this->createUserWith2fa('2fa_imptarget@example.com', 'Impersonation Target', $totp->generateSecret());
        $this->createUserWith2fa('2fa_impvictim@example.com', 'Victim', $totp->generateSecret());

        $this->loginAsAdmin('2fa_impadmin@example.com');
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action="/admin/users/' . $target->getId() . '/impersonate-start"]')->form());
        $this->client->followRedirect(); // /impersonate/start -> /dashboard
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
        $this->assertSelectorExists('.impersonation-banner');

        // Same session, still impersonating: the login form with the victim's password.
        $this->client->request('POST', '/staff/login', ['email' => '2fa_impvictim@example.com', 'password' => 'testpassword']);
        $this->assertResponseRedirects('/dashboard', null, 'the victim password is accepted');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/2fa/challenge');
    }

    // Issue #16 (residue): a FAILED impersonation must leave no "impersonating" marker behind. The markers used to
    // be written before the target was checked, so impersonating a locked user left `_impersonating_as` = that
    // user in the session: no banner should appear, and once the lock lifts, that user signing in with their own
    // password in the same session must still be challenged for their 2FA code.
    public function testFailedImpersonationLeavesNoMarkerThatSkipsTheTargetsChallengeLater(): void
    {
        $totp = $this->getTotp();
        $this->createTestAdmin('2fa_impadmin@example.com');
        $target = $this->createUserWith2fa('2fa_imptarget@example.com', 'Locked Target', $totp->generateSecret());
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('INSERT INTO account_lockouts (user_id, locked_until) VALUES (?, ?)', [$target->getId(), '2099-01-01 00:00:00']);

        try {
            $this->loginAsAdmin('2fa_impadmin@example.com');
            $crawler = $this->client->request('GET', '/admin/users');
            $this->client->submit($crawler->filter('form[action="/admin/users/' . $target->getId() . '/impersonate-start"]')->form());
            $this->client->followRedirect(); // /impersonate/start: the user checker refuses the locked target
            $this->assertResponseRedirects('/admin/login', null, 'impersonating a locked account fails');

            $this->client->request('GET', '/admin/users');
            $this->assertSelectorNotExists('.impersonation-banner', 'a failed impersonation must not claim to be active');

            // The lock lifts; the target signs in with their own password in this same session.
            $conn->executeStatement('DELETE FROM account_lockouts WHERE user_id = ?', [$target->getId()]);
            $this->client->request('POST', '/staff/login', ['email' => '2fa_imptarget@example.com', 'password' => 'testpassword']);
            $this->assertResponseRedirects('/dashboard', null, 'the target password is accepted');

            $this->client->request('GET', '/dashboard');
            $this->assertResponseRedirects('/2fa/challenge', null, 'their own second factor must still be asked for');
        } finally {
            $conn->executeStatement('DELETE FROM account_lockouts WHERE user_id = ?', [$target->getId()]);
        }
    }

    // AC5: An invalid TOTP code shows an error and does not authenticate
    public function testInvalidTotpCodeShowsError(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_wrong@example.com', 'Wrong Code User', $secret);

        $this->loginUser('2fa_wrong@example.com');
        $this->client->followRedirect(); // /dashboard → redirect to /2fa/challenge

        $this->client->followRedirect(); // GET /2fa/challenge → 200

        $this->assertResponseIsSuccessful();

        // Submit invalid code
        $crawler = $this->client->getCrawler();
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = '000000';
        $this->client->submit($form);

        // Should stay on challenge page with error
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // Confirm user cannot reach dashboard — trying /dashboard redirects back to /2fa/challenge
        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/2fa/challenge');
    }

    // AC6: User can disable 2FA from account settings
    public function testUserCanDisable2fa(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_disable@example.com', 'Disable User', $secret);

        // Login and pass 2FA challenge
        $this->loginUser('2fa_disable@example.com');
        $this->client->followRedirect(); // → redirect to /2fa/challenge
        $this->client->followRedirect(); // → GET /2fa/challenge 200

        $crawler = $this->client->getCrawler();
        $code = $totp->generateCode($secret);
        $form = $crawler->selectButton('Verify')->form();
        $form['_code'] = $code;
        $this->client->submit($form);
        $this->client->followRedirect(); // → /dashboard 200

        // Now disable 2FA via settings page
        $crawler = $this->client->request('GET', '/account/settings');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.2fa-disable-btn');

        $before = $this->auditRows('2fa_disable@example.com', '2fa_disable');
        $form = $crawler->selectButton('Disable 2FA')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects('/account/settings');
        $this->assertSame($before + 1, $this->auditRows('2fa_disable@example.com', '2fa_disable'), 'disabling is audited (issue #18)');

        // Verify DB state
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative(
            'SELECT is_totp_enabled, totp_secret FROM two_factor_settings WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            ['2fa_disable@example.com']
        );
        $this->assertSame('0', (string) $row['is_totp_enabled']);
        $this->assertNull($row['totp_secret']);

        // Log out (disabling 2FA left the session live), then log in again — no challenge.
        $this->client->request('GET', '/logout');
        $this->loginUser('2fa_disable@example.com');
        $this->client->followRedirect(); // → /dashboard 200 (no 2FA intercept)
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_dashboard');
    }

    // Guard: an already-enrolled user visiting the setup page must NOT silently be shown a
    // fresh secret/QR. They get the "already enabled" status panel; a fresh QR only appears
    // when they explicitly reconfigure (?reconfigure=1).
    public function testSetupGuardsAgainstReEnrollmentWhenAlreadyEnabled(): void
    {
        $totp = $this->getTotp();
        $secret = $totp->generateSecret();
        $this->createUserWith2fa('2fa_guard@example.com', 'Guard User', $secret);

        // Log in and clear the 2FA challenge so /account/2fa/setup is reachable.
        $this->loginUser('2fa_guard@example.com');
        $this->client->followRedirect(); // → /2fa/challenge (302)
        $this->client->followRedirect(); // GET /2fa/challenge (200)
        $form = $this->client->getCrawler()->selectButton('Verify')->form();
        $form['_code'] = $totp->generateCode($secret);
        $this->client->submit($form);
        $this->client->followRedirect(); // → /dashboard

        // Plain visit while enrolled → status panel, no fresh QR.
        $this->client->request('GET', '/account/2fa/setup');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('img.qr-code', 'Enrolled users must not be shown a new QR by default');
        $this->assertSelectorExists('.2fa-disable-btn');

        // Explicit reconfigure → a fresh QR is shown.
        $this->client->request('GET', '/account/2fa/setup?reconfigure=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('img.qr-code');
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
