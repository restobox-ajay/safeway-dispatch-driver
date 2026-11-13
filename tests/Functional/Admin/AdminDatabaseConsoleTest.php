<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\DbConsoleSession;
use App\Security\ConsoleCookie;
use App\Service\ConfigService;
use App\Service\TotpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-053: the mint side of the db console. The gateway is covered separately over real HTTP
 * (DbConsoleGatewayTest); this pins who may reach these routes and what arming/opening actually write.
 */
final class AdminDatabaseConsoleTest extends WebTestCase
{
    /** A fixed secret so the mandatory-2FA gate (ADR-050) can be cleared deterministically. */
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

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
            $this->conn->executeStatement('DELETE FROM db_console_session');
            $this->conn->executeStatement('DELETE FROM db_console_throttle');
            $this->conn->executeStatement('DELETE FROM admin_sessions');
            $this->conn->executeStatement("DELETE FROM admin_password_reset_tokens WHERE email LIKE 'dbc_%@example.com'");
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'dbc_%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            foreach ([ConsoleCookie::ENABLED_UNTIL_KEY, ConsoleCookie::WINDOW_MINUTES_KEY] as $k) {
                $this->conn->executeStatement('DELETE FROM config WHERE config_key = ?', [$k]);
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    /**
     * @param string[] $roles
     *
     * Admins are created already enrolled in TOTP: ROLE_TECH_SUPPORT carries a mandatory-2FA floor
     * (ADR-050 / FEATURE-149), so an unenrolled one is bounced to /admin/2fa/setup and never reaches
     * the console at all. That interaction is deliberate — the console is only reachable behind a
     * second factor — so the tests clear the gate rather than work around it.
     */
    private function createAdmin(string $email, array $roles): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('DB Console Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $admin->setTotpSecret(self::TOTP_SECRET);
        $admin->setIsTotpEnabled(true);
        $this->em->persist($admin);
        $this->em->flush();
        $id = (int) $admin->getId();
        $this->em->clear();

        return $id;
    }

    /** Log in and complete the mandatory TOTP challenge, leaving the session verified. */
    private function loginAs(string $email): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => 'adminpassword']);

        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect(); // the 2FA challenge form
        $code = self::getContainer()->get(TotpService::class)->generateCode(self::TOTP_SECRET);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    /** POST the "open" action with a valid CSRF token (issue #41: it is a POST form, no longer a GET link). */
    private function openConsole(string $token = 'planted-open-token'): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/db_console_open', 'planted-open-token');
        $session->save();
        $this->client->request('POST', '/admin/db/open', ['_token' => $token]);
    }

    private function arm(int $until): void
    {
        self::getContainer()->get(ConfigService::class)->set(ConsoleCookie::ENABLED_UNTIL_KEY, (string) $until);
    }

    private function sessionCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM db_console_session');
    }

    // ---------------------------------------------------------------- authorisation

    public function testConsoleIsClosedToAnonymous(): void
    {
        $this->client->request('GET', '/admin/db');

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** Raw database access is a maintainer power: ROLE_SUPER_ADMIN is deliberately not enough. */
    public function testSuperAdminWithoutTechSupportIsForbidden(): void
    {
        $this->createAdmin('dbc_super@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('dbc_super@example.com');

        $this->client->request('GET', '/admin/db');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTechSupportCanReachTheConsolePage(): void
    {
        $this->createAdmin('dbc_ts@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_ts@example.com');

        $this->client->request('GET', '/admin/db');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Database Console');
    }

    public function testOpenIsForbiddenForSuperAdmin(): void
    {
        $this->createAdmin('dbc_super2@example.com', ['ROLE_SUPER_ADMIN']);
        $this->loginAs('dbc_super2@example.com');
        $this->arm(time() + 600);

        $this->openConsole();

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->sessionCount(), 'a forbidden request must never mint a token');
    }

    // ---------------------------------------------------------------- arming

    /**
     * The button says 30 minutes, so assert 30 minutes — "some time in the future" would pass just as
     * happily for a one-second window or a hundred-year one.
     */
    public function testArmingWritesADeadlineOfExactlyTheDefaultWindow(): void
    {
        $this->createAdmin('dbc_arm@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_arm@example.com');

        $this->client->request('GET', '/admin/db');
        $before = time();
        $this->client->submitForm('Arm for 30 minutes');

        $until = self::getContainer()->get(ConfigService::class)->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0);
        $expected = ConsoleCookie::DEFAULT_WINDOW_MINUTES * 60;

        self::assertGreaterThanOrEqual($before + $expected, $until);
        self::assertLessThanOrEqual(time() + $expected + 5, $until, 'the window must be 30 minutes, not merely positive');
    }

    /** The label is rendered from config, so a changed window must change what the button offers. */
    public function testTheButtonAndTheDeadlineBothFollowTheConfiguredWindow(): void
    {
        self::getContainer()->get(ConfigService::class)->set(ConsoleCookie::WINDOW_MINUTES_KEY, '5');

        $this->createAdmin('dbc_window@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_window@example.com');

        $crawler = $this->client->request('GET', '/admin/db');
        self::assertStringContainsString(
            'Arm for 5 minutes',
            $crawler->filter('button')->text(),
            'the button must offer the configured window, not a hardcoded 30',
        );

        $before = time();
        $this->client->submitForm('Arm for 5 minutes');

        $until = self::getContainer()->get(ConfigService::class)->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0);
        self::assertGreaterThanOrEqual($before + 300, $until);
        self::assertLessThanOrEqual(time() + 305, $until, 'a configured 5-minute window must arm for 5 minutes');
    }

    /** The minted session must expire on the same window the console was armed for. */
    public function testTheMintedSessionExpiresOnTheConfiguredWindow(): void
    {
        self::getContainer()->get(ConfigService::class)->set(ConsoleCookie::WINDOW_MINUTES_KEY, '5');

        $this->createAdmin('dbc_exp@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_exp@example.com');
        $this->arm(time() + 600);

        $before = time();
        $this->openConsole();

        $expiresAt = strtotime((string) $this->conn->fetchOne('SELECT expires_at FROM db_console_session'));
        self::assertGreaterThanOrEqual($before + 300, $expiresAt);
        self::assertLessThanOrEqual(time() + 305, $expiresAt, 'the token must expire on the configured window');
    }

    /**
     * Issue #40: the gateway (public/db-admin.php) reads and rewrites expires_at in PHP's local timezone. The mint
     * used to store it as UTC wall-clock time, so east of UTC every fresh console read as already expired (and
     * west of UTC lived hours too long). The minted deadline must mean the same instant when read the gateway's way.
     *
     * @return iterable<string,array{string}>
     */
    public static function nonUtcTimezones(): iterable
    {
        yield 'east of UTC' => ['Europe/Berlin'];
        yield 'west of UTC' => ['America/New_York'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonUtcTimezones')]
    public function testTheMintedDeadlineMeansTheSameInstantToTheGatewayInAnyTimezone(string $timezone): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            self::getContainer()->get(ConfigService::class)->set(ConsoleCookie::WINDOW_MINUTES_KEY, '30');
            $this->createAdmin('dbc_tz@example.com', ['ROLE_TECH_SUPPORT']);
            $this->loginAs('dbc_tz@example.com');
            $this->arm(time() + 600);

            $before = time();
            $this->openConsole();

            // Exactly how the gateway reads it: strtotime() in the default timezone.
            $expiresAt = strtotime((string) $this->conn->fetchOne('SELECT expires_at FROM db_console_session'));
            self::assertGreaterThanOrEqual($before + 1800, $expiresAt, "in $timezone the fresh console must not read as expired");
            self::assertLessThanOrEqual(time() + 1805, $expiresAt, "in $timezone the console must not outlive its window");
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testArmingRejectsABadCsrfToken(): void
    {
        $this->createAdmin('dbc_csrf@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_csrf@example.com');

        $this->client->request('POST', '/admin/db/enable', ['_token' => 'wrong']);

        self::assertSame(
            0,
            self::getContainer()->get(ConfigService::class)->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0),
            'a bad CSRF token must not arm the console',
        );
    }

    // ---------------------------------------------------------------- opening

    // Issue #41: opening revokes the admin's other console, mints a token and writes an audit row, so it must not
    // be reachable by a cross-site top-level navigation (the panel cookie is SameSite=Lax): no GET, and a CSRF token.
    public function testOpeningByGetIsRefusedAndMintsNothing(): void
    {
        $adminId = $this->createAdmin('dbc_getopen@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_getopen@example.com');
        $this->arm(time() + 600);
        $this->seedConsole($adminId);
        $auditBefore = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.db_console_open'");

        $this->client->request('GET', '/admin/db/open');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->consolesOf($adminId), 'the existing console must not be revoked');
        self::assertSame($auditBefore, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.db_console_open'"));
    }

    public function testOpeningWithABadCsrfTokenMintsNothing(): void
    {
        $adminId = $this->createAdmin('dbc_csrfopen@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_csrfopen@example.com');
        $this->arm(time() + 600);
        $this->seedConsole($adminId);

        $this->openConsole('forged');

        self::assertResponseRedirects('/admin/db');
        self::assertSame(1, $this->consolesOf($adminId), 'the existing console must not be revoked');
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.db_console_open'"));
    }

    public function testTheOpenButtonOnTheConsolePageWorks(): void
    {
        $this->createAdmin('dbc_button@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_button@example.com');
        $this->arm(time() + 600);

        $this->client->request('GET', '/admin/db');
        $this->client->submitForm('Open the database console');

        self::assertResponseRedirects('/db-admin.php');
        self::assertSame(1, $this->sessionCount());
    }

    public function testOpeningWhileDisarmedMintsNothing(): void
    {
        $this->createAdmin('dbc_closed@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_closed@example.com');

        $this->openConsole();

        self::assertResponseRedirects('/admin/db');
        self::assertSame(0, $this->sessionCount(), 'the console must be armed before a token can be minted');
    }

    public function testOpeningMintsASessionAndSetsAScopedCookie(): void
    {
        $adminId = $this->createAdmin('dbc_open@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_open@example.com');
        $this->arm(time() + 600);

        $this->openConsole();

        self::assertResponseRedirects('/db-admin.php');
        self::assertSame(1, $this->sessionCount());

        $row = $this->conn->fetchAssociative('SELECT * FROM db_console_session');
        self::assertIsArray($row);
        self::assertSame($adminId, (int) $row['admin_id']);

        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame(ConsoleCookie::COOKIE_NAME, $cookie->getName());
        self::assertSame('/db-admin.php', $cookie->getPath(), 'the cookie must be sent to the gateway only');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());

        // The raw token goes to the client; only its hash is stored.
        self::assertSame(
            ConsoleCookie::hashToken((string) $cookie->getValue()),
            (string) $row['token_hash'],
            'the stored value must be the hash of the cookie token, never the token itself',
        );
    }

    public function testOpeningTwiceRetiresTheEarlierToken(): void
    {
        $this->createAdmin('dbc_twice@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_twice@example.com');
        $this->arm(time() + 600);

        $this->openConsole();
        $first = (string) $this->client->getResponse()->headers->getCookies()[0]->getValue();

        $this->openConsole();
        $second = (string) $this->client->getResponse()->headers->getCookies()[0]->getValue();

        self::assertNotSame($first, $second);
        self::assertSame(1, $this->sessionCount(), 'only one console may be open per admin');

        $hash = (string) $this->conn->fetchOne('SELECT token_hash FROM db_console_session');
        self::assertSame(ConsoleCookie::hashToken($second), $hash, 'the surviving row must be the newest token');
    }

    // ---------------------------------------------------------------- the kill-switch

    public function testDisarmingRevokesOpenSessions(): void
    {
        $this->createAdmin('dbc_kill@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_kill@example.com');
        $this->arm(time() + 600);

        $this->openConsole();
        self::assertSame(1, $this->sessionCount());

        $this->client->request('GET', '/admin/db');
        $this->client->submitForm('Disable now (revokes open sessions)');

        self::assertSame(
            0,
            self::getContainer()->get(ConfigService::class)->getInt(ConsoleCookie::ENABLED_UNTIL_KEY, 0),
            'disarming must zero the deadline',
        );
        self::assertSame(0, $this->sessionCount(), 'disarming must drop open sessions, not just block new ones');
    }

    // ---------------------------------------------------------------- audit trail

    public function testConsoleActionsAreAudited(): void
    {
        $this->createAdmin('dbc_audit@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_audit@example.com');
        $this->arm(time() + 600);

        $this->openConsole();

        $actions = $this->conn->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE action LIKE 'admin.db_console%'"
        );
        self::assertContains('admin.db_console_open', $actions, 'opening the console must leave an audit trail');
    }

    // ---------------------------------------------------------------- issue #48: a console dies with its admin's access

    /** A live console row for an admin, exactly as open() would leave it. */
    private function seedConsole(int $adminId): void
    {
        $this->conn->insert('db_console_session', [
            'token_hash' => ConsoleCookie::hashToken(ConsoleCookie::generateToken()),
            'admin_id' => $adminId,
            'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'),
            'ip_address' => '127.0.0.1',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function consolesOf(int $adminId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM db_console_session WHERE admin_id = ?', [$adminId]);
    }

    public function testLoggingOutOfThePanelRevokesYourConsole(): void
    {
        $id = $this->createAdmin('dbc_logout@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_logout@example.com');
        $this->arm(time() + 600);
        $this->openConsole();
        self::assertSame(1, $this->consolesOf($id));

        $this->client->request('GET', '/admin/logout');

        self::assertSame(0, $this->consolesOf($id), 'the next person at that browser must not inherit the console');
    }

    // While impersonating, the token holds the TARGET admin; the person at the browser is the impersonator,
    // whose console cookie is the one on this browser. Logging out must revoke theirs too.
    public function testLoggingOutWhileImpersonatingRevokesTheImpersonatorsConsole(): void
    {
        $impersonator = $this->createAdmin('dbc_imp_by@example.com', ['ROLE_TECH_SUPPORT']);
        $target = $this->createAdmin('dbc_imp_as@example.com', []);
        $this->loginAs('dbc_imp_by@example.com');
        $this->arm(time() + 600);
        $this->openConsole();
        self::assertSame(1, $this->consolesOf($impersonator));

        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/admin_impersonate_admin_' . $target, 'planted-valid-token');
        $session->save();
        $this->client->request('POST', sprintf('/admin/superadmin/admins/%d/impersonate', $target), ['_token' => 'planted-valid-token']);
        self::assertResponseRedirects();

        $this->client->request('GET', '/admin/logout');

        self::assertSame(0, $this->consolesOf($impersonator), 'the impersonator\'s console cookie is on this browser');
    }

    public function testLogoutEverywhereRevokesYourConsole(): void
    {
        $id = $this->createAdmin('dbc_everywhere@example.com', ['ROLE_TECH_SUPPORT']);
        $this->loginAs('dbc_everywhere@example.com');
        $this->seedConsole($id);

        $this->client->request('GET', '/admin/sessions');
        $this->client->submitForm('Logout Everywhere');

        self::assertSame(0, $this->consolesOf($id));
    }

    public function testCompletingAPasswordResetRevokesTheAdminsConsoles(): void
    {
        $id = $this->createAdmin('dbc_reset@example.com', ['ROLE_TECH_SUPPORT']);
        $this->seedConsole($id);
        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new \App\Entity\AdminPasswordResetToken('dbc_reset@example.com', hash('sha256', $plaintext), new \DateTimeImmutable('+1 hour')));
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/admin/reset-password/' . $plaintext);
        $this->client->submitForm('Reset Password', ['password' => 'a-brand-new-password-123']);
        self::assertResponseRedirects('/admin/login');

        self::assertSame(0, $this->consolesOf($id), 'a reset after a compromise must also close the console');
    }

    public function testDeactivatingOrDeletingAnAdminRevokesItsConsoles(): void
    {
        $deactivated = $this->createAdmin('dbc_victim1@example.com', ['ROLE_TECH_SUPPORT']);
        $deleted = $this->createAdmin('dbc_victim2@example.com', ['ROLE_TECH_SUPPORT']);
        $this->createAdmin('dbc_boss@example.com', ['ROLE_TECH_SUPPORT']);
        $this->seedConsole($deactivated);
        $this->seedConsole($deleted);
        $this->loginAs('dbc_boss@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $deactivated . '/edit');
        $this->client->submitForm('Save', ['email' => 'dbc_victim1@example.com', 'name' => 'DB Console Test', 'role' => 'ROLE_TECH_SUPPORT', 'status' => 'inactive']);
        self::assertSame(0, $this->consolesOf($deactivated));

        $this->client->request('GET', '/admin/superadmin/admins');
        $this->client->submit($this->client->getCrawler()->filter(sprintf('form[action="/admin/superadmin/admins/%d/delete"]', $deleted))->form());
        self::assertSame(0, $this->consolesOf($deleted));
    }

    public function testDisarmingRevokesEveryAdminsConsoleNotJustYours(): void
    {
        $other = $this->createAdmin('dbc_other@example.com', ['ROLE_TECH_SUPPORT']);
        $this->createAdmin('dbc_me@example.com', ['ROLE_TECH_SUPPORT']);
        $this->seedConsole($other);
        $this->loginAs('dbc_me@example.com');
        $this->arm(time() + 600);

        $this->client->request('GET', '/admin/db');
        $this->client->submitForm('Disable now (revokes open sessions)');

        self::assertSame(0, $this->sessionCount(), "a suspected-stolen console of another admin must not come back on re-arm");
    }
}

