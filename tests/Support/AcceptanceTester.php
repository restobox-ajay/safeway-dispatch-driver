<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Inherited Methods
 * @method void wantTo($text)
 * @method void wantToTest($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
 */
class AcceptanceTester extends \Codeception\Actor
{
    use _generated\AcceptanceTesterActions;

    // Enables $I->haveFriend(...) for multi-session scenarios (a second, independently
    // cookie-jarred browser). The base \Codeception\Actor does not include this trait;
    // PhpBrowser implements MultiSession, so friends get their own session.
    use \Codeception\Lib\Actor\Shared\Friend;

    /**
     * Submit the user login form with the given credentials.
     */
    public function loginAsUser(string $email, string $password = 'password123'): void
    {
        $this->amOnPage('/staff/login');
        $this->submitForm('form', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Simulate "coming back later": drop the session cookie, keeping any long-lived cookies (notably
     * the opt-in ADMIN_LAST_EMAIL prefill cookie from ADR-051). The session cookie is PHPSESSID here
     * because the acceptance stack runs the real native session storage, not the mock file storage
     * the functional suite uses.
     */
    public function resetCookieJarKeepingRememberedEmail(): void
    {
        $this->resetCookie('PHPSESSID');
    }

    /**
     * Submit the admin login form with the given credentials.
     */
    public function loginAsAdmin(string $email, string $password = 'password123'): void
    {
        $this->amOnPage('/admin/login');
        $this->submitForm('form', [
            'email' => $email,
            'password' => $password,
        ]);
    }
}
