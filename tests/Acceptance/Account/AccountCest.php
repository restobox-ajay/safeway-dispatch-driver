<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Account;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-072 — E2E Cest: Account self-service (login history, PAT, notification prefs).
 *
 * Five phpBrowser scenarios over live HTTP, one per acceptance criterion: the login
 * history page showing only the current user's logins, creating a personal access token
 * (plaintext shown exactly once), revoking a token, the per-user token limit producing a
 * validation error, and toggling the login-notification preference (persisted to the DB).
 *
 * These subsystems already have green functional coverage (login history FEATURE-025/026,
 * PAT bundle, notification prefs FEATURE-027); this feature adds dedicated end-to-end
 * depth. No production code changes — only read-only seed/assertion helpers on
 * DatabaseHelper (the acceptance actor has no Asserts module). Tokens are created through
 * the real UI so the plaintext-once guarantee is exercised exactly as a user would hit it.
 */
final class AccountCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: /account/login-history shows the current user's logins and not another user's.
    public function loginHistoryShowsOwnLoginsOnly(AcceptanceTester $I): void
    {
        $ownerId = $I->createUser('owner@example.com', 'password123');
        $otherId = $I->createUser('other@example.com', 'password123');

        $I->seedLoginHistory($ownerId, '10.0.0.1', 'OwnerBrowser');
        $I->seedLoginHistory($otherId, '10.9.9.9', 'OtherBrowser');

        $I->loginAsUser('owner@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        $I->amOnPage('/account/login-history');
        $I->seeResponseCodeIs(200);

        // The owner's seeded login is visible.
        $I->see('10.0.0.1', '.entry-ip');
        // The other user's login must never leak into this list.
        $I->dontSee('10.9.9.9');
    }

    // AC2: creating a PAT shows the plaintext exactly once (never again on the list).
    public function createTokenShowsPlaintextOnce(AcceptanceTester $I): void
    {
        $userId = $I->createUser('pat@example.com', 'password123');
        $I->loginAsUser('pat@example.com', 'password123');

        $I->amOnPage('/account/tokens');
        $I->submitForm('form', ['name' => 'CI Token']);

        // The token-created page reveals the plaintext value once.
        $I->seeResponseCodeIs(200);
        $I->seeElement('.token-plaintext');
        $I->see('will not be shown again');
        $plaintext = $I->grabTextFrom('.token-plaintext');

        // A real active row was written.
        $I->seeActiveTokenCountForUser($userId, 1);

        // Returning to the list never re-renders the plaintext — shown exactly once.
        $I->amOnPage('/account/tokens');
        $I->see('CI Token', '.token-name');
        $I->dontSee($plaintext);
    }

    // AC3: revoking a PAT removes it from the active list and clears it in the database.
    public function revokeTokenMakesItInactive(AcceptanceTester $I): void
    {
        $userId = $I->createUser('revoke@example.com', 'password123');
        $I->loginAsUser('revoke@example.com', 'password123');

        $I->amOnPage('/account/tokens');
        $I->submitForm('form', ['name' => 'Revoke Me']);
        $I->seeActiveTokenCountForUser($userId, 1);

        $I->amOnPage('/account/tokens');
        $I->see('Revoke Me', '.token-name');
        $I->click('.token-revoke-btn');

        $I->seeCurrentUrlEquals('/account/tokens');
        $I->see('Token revoked.');
        // Gone from the active list, and no active tokens remain in the database.
        $I->dontSee('Revoke Me', '.token-name');
        $I->see('No active tokens.');
        $I->seeActiveTokenCountForUser($userId, 0);
    }

    // AC4: creating beyond the configured per-user limit is rejected with a clear error.
    public function exceedingMaxTokensShowsValidationError(AcceptanceTester $I): void
    {
        $I->seedConfig('pat.max_tokens_per_user', '1');

        $userId = $I->createUser('limit@example.com', 'password123');
        $I->loginAsUser('limit@example.com', 'password123');

        // First token uses up the single allowed slot.
        $I->amOnPage('/account/tokens');
        $I->submitForm('form', ['name' => 'First']);
        $I->seeActiveTokenCountForUser($userId, 1);

        // The second is refused with a validation error, not persisted.
        $I->amOnPage('/account/tokens');
        $I->submitForm('form', ['name' => 'Second']);
        $I->seeResponseCodeIs(200);
        $I->see('You have reached the maximum of 1 active token(s).', '.error');
        $I->seeActiveTokenCountForUser($userId, 1);
    }

    // AC5: toggling the login-notification preference is persisted to the database.
    public function togglingLoginNotificationPreferencePersists(AcceptanceTester $I): void
    {
        // New users default to login notifications enabled.
        $userId = $I->createUser('prefs@example.com', 'password123');
        $I->loginAsUser('prefs@example.com', 'password123');

        $I->amOnPage('/account/settings');
        $I->seeCheckboxIsChecked('login_notifications_enabled');

        // Unchecking and saving turns the preference off.
        $I->uncheckOption('login_notifications_enabled');
        $I->click('Save Settings');
        $I->seeCurrentUrlEquals('/account/settings');
        $I->see('Settings saved.');

        // Reflected in the reloaded form and persisted to the database.
        $I->dontSeeCheckboxIsChecked('login_notifications_enabled');
        $I->seeLoginNotificationsEnabledFlag($userId, false);
    }
}
