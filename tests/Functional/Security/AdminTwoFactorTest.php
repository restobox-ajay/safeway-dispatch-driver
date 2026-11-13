<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Service\ConfigService;
use App\Service\TotpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-126 (review C37): the Admin realm gets TOTP 2FA (enrol + login challenge) and 2FA
 * enforcement becomes per-role (off/optional/required). Admin-side mirror of the user 2FA flow,
 * realm-isolated (ADR-003) — admin TOTP state lives on the Admin entity, its own routes and
 * session keys. Superadmins can reset any admin's 2FA.
 */
final class AdminTwoFactorTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement('DELETE FROM admin_sessions');
            $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'admin2fa%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            foreach ([
                '2fa.enforcement',
                '2fa.enforcement.role.ROLE_USER',
                '2fa.enforcement.role.ROLE_ADMIN',
                '2fa.enforcement.role.ROLE_SUPER_ADMIN',
                '2fa.enforcement.role.ROLE_TECH_SUPPORT',
            ] as $key) {
                $this->conn->executeStatement('DELETE FROM config WHERE config_key = ?', [$key]);
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    /** @param string[] $roles */
    private function createAdmin(string $email, array $roles = [], ?string $totpSecret = null): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Admin 2FA Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        if ($totpSecret !== null) {
            $admin->setTotpSecret($totpSecret);
            $admin->setIsTotpEnabled(true);
        }
        $this->em->persist($admin);
        $this->em->flush();
        $id = (int) $admin->getId();
        $this->em->clear();

        return $id;
    }

    private function loginAs(string $email): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'adminpassword',
        ]);
    }

    private function isTotpEnabled(int $adminId): bool
    {
        return (bool) $this->conn->fetchOne('SELECT is_totp_enabled FROM admin WHERE id = ?', [$adminId]);
    }

    private function setConfig(string $key, string $value): void
    {
        self::getContainer()->get(ConfigService::class)->set($key, $value);
    }

    // AC2 / AC4: an admin can enrol in TOTP 2FA; the secret is stored on the Admin realm.
    public function testAdminCanEnrolInTwoFactor(): void
    {
        $adminId = $this->createAdmin('admin2fa_enrol@example.com');
        $this->loginAs('admin2fa_enrol@example.com');

        // Optional enforcement (default) does not force enrolment, so the setup page is reachable.
        $crawler = $this->client->request('GET', '/admin/2fa/setup');
        self::assertResponseIsSuccessful();

        $secret = trim($crawler->filter('.totp-secret')->text());
        self::assertNotSame('', $secret);

        $totp = self::getContainer()->get(TotpService::class);
        $code = $totp->generateCode($secret);

        $this->client->submitForm('Verify and Enable 2FA', ['_code' => $code]);

        self::assertResponseRedirects('/admin/dashboard');
        self::assertTrue($this->isTotpEnabled($adminId), 'Admin TOTP should be enabled after enrolment.');
        self::assertSame(1, $this->auditRows('admin2fa_enrol@example.com', 'admin.2fa_enable', 'enrolled'), 'enrolment is audited (issue #18)');

        // Enrolling proves possession of the code, so this session counts as having passed the challenge.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_admin_dashboard');
    }

    // Issue #18: removing an admin's second factor is audited (a hijacked verified session could otherwise do it silently).
    public function testAdminDisablingTwoFactorIsAudited(): void
    {
        $adminId = $this->createAdmin('admin2fa_disable@example.com');
        $this->loginAs('admin2fa_disable@example.com');
        $crawler = $this->client->request('GET', '/admin/2fa/setup');
        $secret = trim($crawler->filter('.totp-secret')->text());
        $this->client->submitForm('Verify and Enable 2FA', ['_code' => self::getContainer()->get(TotpService::class)->generateCode($secret)]);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/2fa/setup');
        $this->client->submitForm('Disable 2FA');

        self::assertResponseRedirects('/admin/2fa/setup');
        self::assertFalse($this->isTotpEnabled($adminId));
        self::assertSame(1, $this->auditRows('admin2fa_disable@example.com', 'admin.2fa_disable'));
    }

    // AC2: an enrolled admin is challenged for a TOTP code before reaching admin pages.
    public function testEnrolledAdminIsChallengedOnLogin(): void
    {
        // Enrol directly in the DB, then log in fresh (no _admin_2fa_verified marker yet).
        $this->createAdmin('admin2fa_challenge@example.com', [], 'JBSWY3DPEHPK3PXP');
        $this->loginAs('admin2fa_challenge@example.com');

        // login POST -> 302 /admin/dashboard; GET /admin/dashboard -> 302 /admin/2fa/challenge.
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/2fa/challenge');
    }

    // Issue #63: the public-route exemption matched '/admin/login' by PREFIX, so /admin/login-history (the
    // admin's own login IPs and user agents) was served to a password-only session that still owed the challenge.
    public function testLoginHistoryIsBehindThePendingChallenge(): void
    {
        $this->createAdmin('admin2fa_history@example.com', [], 'JBSWY3DPEHPK3PXP');
        $this->loginAs('admin2fa_history@example.com');

        $this->client->request('GET', '/admin/login-history');

        self::assertResponseRedirects('/admin/2fa/challenge');
    }

    // The public admin auth routes stay reachable while the challenge is pending.
    public function testPublicAuthRoutesStayReachableDuringPendingChallenge(): void
    {
        $this->createAdmin('admin2fa_public@example.com', [], 'JBSWY3DPEHPK3PXP');
        $this->loginAs('admin2fa_public@example.com');

        foreach (['/admin/forgot-password', '/admin/forgot-password/check', '/admin/reset-password/not-a-real-token'] as $path) {
            $this->client->request('GET', $path);
            self::assertNotSame(
                '/admin/2fa/challenge',
                $this->client->getResponse()->headers->get('Location'),
                $path . ' must not be gated by the challenge'
            );
        }

        $this->client->request('GET', '/admin/logout');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/admin/2fa/challenge', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // Security: a passed challenge belongs to the admin who passed it. An admin who passes their own
    // challenge and then signs in as another admin in the same session (no logout) must be challenged
    // for the other admin's code.
    public function testPassedChallengeDoesNotCarryOverToAnotherAdminInTheSameSession(): void
    {
        $totp = self::getContainer()->get(TotpService::class);
        $this->createAdmin('admin2fa_attacker@example.com', [], 'JBSWY3DPEHPK3PXP');
        $this->createAdmin('admin2fa_victim@example.com', ['ROLE_SUPER_ADMIN'], 'KRSXG5DSNFXGOIDB');

        $this->loginAs('admin2fa_attacker@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $this->client->submitForm('Verify', ['_code' => $totp->generateCode('JBSWY3DPEHPK3PXP')]);
        self::assertResponseRedirects('/admin/dashboard');

        // Same session, no logout: the admin login form with the victim's password.
        $this->client->request('POST', '/admin/login', ['email' => 'admin2fa_victim@example.com', 'password' => 'adminpassword']);
        self::assertResponseRedirects('/admin/dashboard', null, 'the victim password is accepted');

        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/2fa/challenge');
    }

    // Security: the "impersonating" skip belongs to the impersonated admin. A superadmin who starts
    // impersonating admin A and then signs in as enrolled admin B in the same session must be challenged
    // for B's code, not waved through because an impersonation flag is still in the session.
    public function testImpersonationFlagDoesNotSkipTheChallengeForAnotherAdmin(): void
    {
        $totp = self::getContainer()->get(TotpService::class);
        $this->createAdmin('admin2fa_impsuper@example.com', ['ROLE_SUPER_ADMIN'], 'JBSWY3DPEHPK3PXP');
        // The target is enrolled too, so this also proves impersonating it still skips ITS challenge.
        $targetId = $this->createAdmin('admin2fa_imptarget@example.com', [], 'GEZDGNBVGY3TQOJQ');
        $this->createAdmin('admin2fa_impvictim@example.com', ['ROLE_TECH_SUPPORT'], 'KRSXG5DSNFXGOIDB');

        $this->loginAs('admin2fa_impsuper@example.com');
        $this->client->followRedirect();
        $this->client->followRedirect();
        $this->client->submitForm('Verify', ['_code' => $totp->generateCode('JBSWY3DPEHPK3PXP')]);
        self::assertResponseRedirects('/admin/dashboard');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $this->client->submit($crawler->filter('form[action="/admin/superadmin/admins/' . $targetId . '/impersonate"]')->form());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_admin_dashboard');
        self::assertSelectorExists('.admin-impersonation-banner');

        // Same session, still impersonating: the admin login form with the victim's password.
        $this->client->request('POST', '/admin/login', ['email' => 'admin2fa_impvictim@example.com', 'password' => 'adminpassword']);
        self::assertResponseRedirects('/admin/dashboard', null, 'the victim password is accepted');

        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/2fa/challenge');
    }

    // AC2 (review C37 BLOCKER): an enrolled admin who logged in but has NOT completed the TOTP
    // challenge must not be able to disable 2FA. The disable route is no longer skipped by the
    // challenge listener, so the POST is bounced to the challenge before the controller runs and
    // the TOTP stays enabled — a stolen-password attacker cannot self-disable the second factor.
    public function testEnrolledAdminCannotDisableTwoFactorDuringPendingChallenge(): void
    {
        $adminId = $this->createAdmin('admin2fa_bypass@example.com', [], 'JBSWY3DPEHPK3PXP');
        $this->loginAs('admin2fa_bypass@example.com');

        // Fresh login: session holds ROLE_ADMIN but no _admin_2fa_verified marker yet.
        $this->client->request('POST', '/admin/2fa/disable', ['_token' => 'irrelevant']);

        // Redirect target proves the listener intercepted: a CSRF failure would redirect to
        // /admin/2fa/setup, whereas the pending-challenge listener redirects to the challenge.
        self::assertResponseRedirects('/admin/2fa/challenge');
        self::assertTrue(
            $this->isTotpEnabled($adminId),
            'TOTP must remain enabled — disabling during a pending challenge must be blocked.'
        );
    }

    // AC1 / AC5: per-role enforcement — a required role is forced, an optional/off role is not.
    public function testPerRoleEnforcementForcesSuperadminButNotPlainAdmin(): void
    {
        $this->setConfig('2fa.enforcement.role.ROLE_SUPER_ADMIN', 'required');
        $this->setConfig('2fa.enforcement.role.ROLE_ADMIN', 'off');

        // Superadmin (holds ROLE_SUPER_ADMIN) with no 2FA -> forced to setup.
        $this->createAdmin('admin2fa_super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admin2fa_super@example.com');
        $this->client->followRedirect(); // GET /admin/dashboard
        self::assertResponseRedirects('/admin/2fa/setup');

        // Fresh client so the second login has its own session.
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        // Plain admin (ROLE_ADMIN, level off) with no 2FA -> NOT forced.
        $this->createAdmin('admin2fa_plain@example.com', []);
        $this->loginAs('admin2fa_plain@example.com');
        $this->client->followRedirect(); // GET /admin/dashboard
        self::assertResponseIsSuccessful();
    }

    // Audit #2: "off" means "don't force anyone to enrol", never "skip the code of an admin who enrolled".
    // Otherwise one admin who can change the setting turns every superadmin's 2FA off.
    public function testAnEnrolledAdminIsChallengedEvenWithEnforcementOff(): void
    {
        $this->setConfig('2fa.enforcement.role.ROLE_SUPER_ADMIN', 'off');
        $this->setConfig('2fa.enforcement.role.ROLE_ADMIN', 'off');
        $this->setConfig('2fa.enforcement', 'off');
        $this->createAdmin('admin2fa_super@example.com', ['ROLE_SUPER_ADMIN'], 'JBSWY3DPEHPK3PXP');

        $this->loginAs('admin2fa_super@example.com');
        $this->client->followRedirect(); // GET /admin/dashboard

        self::assertResponseRedirects('/admin/2fa/challenge');
    }

    // ADR-050 / FEATURE-149: ROLE_TECH_SUPPORT ALWAYS requires 2FA, even with every enforcement
    // key explicitly set to 'off' — the floor is enforced in code, not config.
    public function testTechSupportIsForcedIntoTwoFactorEvenWithEnforcementOff(): void
    {
        $this->setConfig('2fa.enforcement.role.ROLE_TECH_SUPPORT', 'off');
        $this->setConfig('2fa.enforcement.role.ROLE_SUPER_ADMIN', 'off');
        $this->setConfig('2fa.enforcement.role.ROLE_ADMIN', 'off');
        $this->setConfig('2fa.enforcement', 'off');

        $this->createAdmin('admin2fa_techsup@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('admin2fa_techsup@example.com');
        $this->client->followRedirect(); // GET /admin/dashboard
        self::assertResponseRedirects('/admin/2fa/setup');
    }

    // AC3: a superadmin can reset another admin's 2FA.
    public function testSuperadminCanResetAdminTwoFactor(): void
    {
        $targetId = $this->createAdmin('admin2fa_target@example.com', [], 'JBSWY3DPEHPK3PXP');
        self::assertTrue($this->isTotpEnabled($targetId));

        $this->createAdmin('admin2fa_super2@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('admin2fa_super2@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="/admin/superadmin/admins/' . $targetId . '/reset-2fa"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/superadmin/admins');
        self::assertFalse($this->isTotpEnabled($targetId), 'Target admin TOTP should be reset.');
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
