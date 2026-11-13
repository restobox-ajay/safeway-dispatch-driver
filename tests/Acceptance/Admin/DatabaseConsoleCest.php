<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Security\ConsoleCookie;
use App\Tests\Support\AcceptanceTester;

/**
 * ADR-053, adversarially, over real HTTP against the live server.
 *
 * The functional suite drives the gateway's checks one at a time with a hand-built database. This
 * comes at it the other way: a real login through the real firewall and the real 2FA gate, then the
 * credential is attacked — wrong token, forged token, revoked console — and finally the RIGHT token is
 * swapped back in to prove the denials were the gateway's doing and not the scenario being broken.
 *
 * It also pins the structural half of the fix, which no other test covers: phpliteadmin.php now lives
 * outside the docroot and must not be reachable by URL at all.
 */
class DatabaseConsoleCest
{
    private const TS_EMAIL = 'techsup-console@example.com';
    private const PASSWORD = 'password123';
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    /** Log in a tech-support admin and clear the mandatory 2FA gate, leaving the session verified. */
    private function loginAsTechSupport(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/login');
        $I->submitForm('form', ['email' => self::TS_EMAIL, 'password' => self::PASSWORD]);

        // Enrolled admin: the first admin-panel request bounces to the 2FA challenge.
        $I->amOnPage('/admin/dashboard');
        $I->submitForm('form', ['_code' => $I->generateTotpCode(self::SECRET)]);
    }

    /** Arm the console and open it, returning the raw token handed to the browser. */
    private function openConsole(AcceptanceTester $I): string
    {
        $I->amOnPage('/admin/db');
        $I->submitForm('form', []);              // "Arm for N minutes"
        $I->click('Open the database console');  // a CSRF-checked POST form (issue #41)

        $token = (string) $I->grabCookie(ConsoleCookie::COOKIE_NAME, ['path' => '/db-admin.php']);
        if ($token === '') {
            throw new \RuntimeException('opening the console did not hand the browser a token');
        }

        return $token;
    }

    // ---------------------------------------------------------------- the structural fix

    /**
     * phpliteadmin.php was moved to tools/. If it is ever reachable by URL again, its own password is
     * an empty string and the gateway is beside the point.
     */
    public function phpliteadminIsNotReachableByUrl(AcceptanceTester $I): void
    {
        foreach (['/phpliteadmin.php', '/tools/phpliteadmin.php'] as $path) {
            $I->amOnPage($path);
            // Assert the STATUS, not the body: a 404 page echoes the requested URL, so the word
            // "phpliteadmin" appears on it either way. What matters is that nothing served the file.
            $I->seeResponseCodeIs(404);
            $I->dontSee('CREATE TABLE');
        }
    }

    // ---------------------------------------------------------------- unauthenticated

    public function anonymousIsSentToAdminLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/db-admin.php');

        $I->seeCurrentUrlEquals('/admin/login');
        $I->dontSee('phpLiteAdmin');
    }

    public function anonymousCannotReachTheConsolePage(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/db');

        $I->seeCurrentUrlEquals('/admin/login');
    }

    /** Raw database access is a maintainer power; a plain admin must not have it. */
    public function plainAdminIsForbidden(AcceptanceTester $I): void
    {
        $I->createAdmin('plain-console@example.com', self::PASSWORD);
        $I->amOnPage('/admin/login');
        $I->submitForm('form', ['email' => 'plain-console@example.com', 'password' => self::PASSWORD]);

        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIs(403);
    }

    // ---------------------------------------------------------------- the token itself

    /**
     * The paired case. A wrong token is refused; swapping the RIGHT one back in grants access — so the
     * refusal above was the gateway deciding, not the scenario being broken.
     */
    public function wrongTokenIsRefusedAndTheRightTokenIsAdmitted(AcceptanceTester $I): void
    {
        $I->createTechSupportAdminWith2fa(self::TS_EMAIL, self::PASSWORD, self::SECRET);
        $this->loginAsTechSupport($I);
        $realToken = $this->openConsole($I);

        // --- RED: a wrong token of the right shape is refused.
        $I->setCookie(ConsoleCookie::COOKIE_NAME, str_repeat('a', 64), ['path' => '/db-admin.php']);
        $I->amOnPage('/db-admin.php');
        // A denial redirects to /admin/login, but this browser is still a signed-in admin, so the app
        // bounces onward to the dashboard. The claim worth asserting is therefore not the landing URL
        // but the security property: the console did not open.
        $I->dontSeeInCurrentUrl('/db-admin.php');
        $I->dontSee('phpLiteAdmin');

        // --- GREEN: the real token, same browser, same everything else.
        $I->setCookie(ConsoleCookie::COOKIE_NAME, $realToken, ['path' => '/db-admin.php']);
        $I->amOnPage('/db-admin.php');
        $I->dontSeeInCurrentUrl('/admin/login');
        $I->see('phpLiteAdmin');
    }

    /** The stored form is a hash, so replaying it must not work. */
    public function presentingTheStoredHashIsRefused(AcceptanceTester $I): void
    {
        $I->createTechSupportAdminWith2fa(self::TS_EMAIL, self::PASSWORD, self::SECRET);
        $this->loginAsTechSupport($I);
        $realToken = $this->openConsole($I);

        $I->setCookie(ConsoleCookie::COOKIE_NAME, ConsoleCookie::hashToken($realToken), ['path' => '/db-admin.php']);
        $I->amOnPage('/db-admin.php');

        $I->dontSeeInCurrentUrl('/db-admin.php');
        $I->dontSee('phpLiteAdmin');
    }

    // ---------------------------------------------------------------- the kill-switch

    /** Disarming must lock out a console that is already open, not merely stop new ones. */
    public function disarmingRevokesAnAlreadyOpenConsole(AcceptanceTester $I): void
    {
        $I->createTechSupportAdminWith2fa(self::TS_EMAIL, self::PASSWORD, self::SECRET);
        $this->loginAsTechSupport($I);
        $realToken = $this->openConsole($I);

        // It works right now.
        $I->setCookie(ConsoleCookie::COOKIE_NAME, $realToken, ['path' => '/db-admin.php']);
        $I->amOnPage('/db-admin.php');
        $I->see('phpLiteAdmin');

        // Switch it off, then present the very same token again.
        $I->seedConfig(ConsoleCookie::ENABLED_UNTIL_KEY, '0');
        $I->setCookie(ConsoleCookie::COOKIE_NAME, $realToken, ['path' => '/db-admin.php']);
        $I->amOnPage('/db-admin.php');

        $I->dontSeeInCurrentUrl('/db-admin.php');
        $I->dontSee('phpLiteAdmin');
    }
}
