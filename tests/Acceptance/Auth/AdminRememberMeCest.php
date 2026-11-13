<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * ADR-051 over real HTTP. Unlike the functional suite (which runs on mock session storage), the
 * acceptance stack uses the REAL ConfigAwarePdoSessionHandler against SQLite, so this exercises the
 * actual `sessions` write path that carries the extended TTL.
 *
 * "Remember me" here extends the SESSION rather than issuing a bearer cookie (ADR-049 / review C3),
 * so what is observable end-to-end is: the box exists, ticking it prefills the email on a later
 * visit, and not ticking it leaves nothing behind.
 */
class AdminRememberMeCest
{
    public function loginFormOffersRememberMe(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/login');

        $I->seeElement('input[name="_remember_me"]');
    }

    public function rememberedAdminGetsEmailPrefilledOnReturn(AcceptanceTester $I): void
    {
        $I->createAdmin('rmadmin@example.com', 'password123');

        $I->amOnPage('/admin/login');
        $I->submitForm('form', [
            'email' => 'rmadmin@example.com',
            'password' => 'password123',
            '_remember_me' => 'on',
        ]);
        $I->seeCurrentUrlEquals('/admin/dashboard');

        // Return later: the session is gone but the opt-in prefill cookie remains.
        $I->resetCookieJarKeepingRememberedEmail();
        $I->amOnPage('/admin/login');

        $I->seeInField('email', 'rmadmin@example.com');
        $I->seeCheckboxIsChecked('input[name="_remember_me"]');
    }

    public function withoutRememberMeNothingIsPrefilled(AcceptanceTester $I): void
    {
        $I->createAdmin('nrmadmin@example.com', 'password123');

        $I->loginAsAdmin('nrmadmin@example.com', 'password123');
        $I->seeCurrentUrlEquals('/admin/dashboard');

        $I->resetCookieJarKeepingRememberedEmail();
        $I->amOnPage('/admin/login');

        $I->seeInField('email', '');
        $I->dontSeeCheckboxIsChecked('input[name="_remember_me"]');
    }
}
