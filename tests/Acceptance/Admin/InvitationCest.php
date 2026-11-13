<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-071 — E2E Cest: Invitation Flow.
 *
 * Five phpBrowser scenarios over live HTTP, one per acceptance criterion: an admin
 * sending an invite, the invite link pre-filling the registration form, completing
 * registration through the invite, an expired invite being rejected with a clear
 * error, and an admin resending an invite (rotating the token, invalidating the old).
 *
 * The invitation subsystem already has green functional coverage (FEATURE-016); this
 * feature adds dedicated end-to-end depth. No production code changes — only read-only
 * DB assertion helpers on DatabaseHelper (the acceptance actor has no Asserts module).
 *
 * The test mailer DSN is null://null, so emails go nowhere over live HTTP; scenarios
 * assert flashes + DB state rather than email counts (email coverage lives in the
 * FEATURE-016 functional tests). Invite tokens are seeded through createInvitation so
 * the test holds the plaintext that goes in the /register?token=... link — the only
 * place the production flow ever exposes it is the emailed link.
 */
final class InvitationCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: admin sends an invite -> confirmation shown (and a real invitation row written).
    public function adminSendsInviteAndSeesConfirmation(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass');
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/users/invite');
        $I->submitForm('form', ['email' => 'invitee@example.com']);

        // Success redirects to the invitations list and flashes a confirmation.
        $I->seeCurrentUrlEquals('/admin/users/invitations');
        $I->see('Invitation sent to invitee@example.com.');
        $I->seeExactlyOneInvitationForEmail('invitee@example.com');
    }

    // AC2: a valid invite token -> /register with the invited email pre-filled.
    public function validInviteTokenShowsPrefilledRegisterForm(AcceptanceTester $I): void
    {
        $I->seedConfig('registration.mode', 'invitation-only');
        $token = $I->createInvitation('prefill@example.com');

        $I->amOnPage('/register?token=' . $token);

        $I->seeResponseCodeIs(200);
        $I->seeInField('email', 'prefill@example.com');
        // The token is carried forward on submit via a hidden field.
        $I->seeElement('input[name="_invite_token"]');
    }

    // AC3: completing the registration form via the invite -> account created and usable.
    public function completingRegistrationViaInviteCreatesAccount(AcceptanceTester $I): void
    {
        $I->seedConfig('registration.mode', 'invitation-only');
        $token = $I->createInvitation('joiner@example.com');

        $I->amOnPage('/register?token=' . $token);
        // The hidden _invite_token (and CSRF, if any) are carried from the rendered form.
        $I->submitForm('form', [
            'email'    => 'joiner@example.com',
            'name'     => 'New Joiner',
            'password' => 'password123',
        ]);

        // A successful invite registration redirects to the login page.
        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeExactlyOneUserWithEmail('joiner@example.com');
        // The invite is single-use: completing it marks the invitation consumed.
        $I->seeInvitationUsed('joiner@example.com');

        // The account works end-to-end.
        $I->loginAsUser('joiner@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC4: an expired invite token -> clear error, no registration form to submit through.
    public function expiredInviteTokenShowsClearError(AcceptanceTester $I): void
    {
        $I->seedConfig('registration.mode', 'invitation-only');
        // Negative expiry seeds an invitation whose expiresAt is already in the past.
        $token = $I->createInvitation('stale@example.com', -1);

        $I->amOnPage('/register?token=' . $token);

        // The controller re-renders the page (200) with an expiry error rather than 403.
        $I->seeResponseCodeIs(200);
        $I->see('expired', '.error');
    }

    // AC5: admin resends an invite -> a new token is generated and the old one is invalidated.
    public function adminResendsInviteRotatingToken(AcceptanceTester $I): void
    {
        $oldToken = $I->createInvitation('resend@example.com');

        $I->createAdmin('admin@example.com', 'adminpass');
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/users/invitations');
        $I->see('resend@example.com', '.invitation-email');
        $I->click('.resend-btn');

        $I->seeCurrentUrlEquals('/admin/users/invitations');
        $I->see('Invitation resent to resend@example.com.');
        // New token generated: the stored hash no longer matches the old plaintext token.
        $I->seeInvitationTokenRotated('resend@example.com', $oldToken);

        // Old token invalidated end-to-end: using it now is rejected (hash no longer found).
        $I->seedConfig('registration.mode', 'invitation-only');
        $I->amOnPage('/register?token=' . $oldToken);
        $I->seeResponseCodeIs(403);
    }
}
