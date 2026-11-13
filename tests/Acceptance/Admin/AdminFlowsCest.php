<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the core admin workflows over real HTTP (FEATURE-064 AC5):
 * admin login, user list/create/edit/delete, config UI, audit log view, and the
 * invitation flow. Each scenario relies on DatabaseHelper resetting the DB before it.
 */
class AdminFlowsCest
{
    public function adminLoginReachesDashboard(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->seeCurrentUrlEquals('/admin/dashboard');
        $I->see('Admin Dashboard', 'h1');
    }

    public function unauthenticatedAdminAreaRedirectsToLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/users');

        $I->seeCurrentUrlEquals('/admin/login');
    }

    public function adminCanCreateUserAndItAppearsInList(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users/new');
        $I->submitForm('form', [
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'password' => 'password123',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        // Redirects back to the user list, where the new account is shown.
        $I->seeCurrentUrlEquals('/admin/users');
        $I->see('newuser@example.com');
        $I->see('New User');
    }

    public function adminCanEditUser(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $userId = $I->createUser('edit-me@example.com', 'password123', ['name' => 'Original Name']);
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users/' . $userId . '/edit');
        $I->seeInField('name', 'Original Name');

        $I->submitForm('form', [
            'email' => 'edit-me@example.com',
            'name' => 'Renamed User',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $I->seeCurrentUrlEquals('/admin/users');
        $I->see('Renamed User');
        $I->dontSee('Original Name');
    }

    public function adminCanDeleteUser(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $userId = $I->createUser('delete-me@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users');
        $I->see('delete-me@example.com');

        // The row has several POST forms; target the delete one by its action so the
        // hidden per-id CSRF token is submitted with it.
        $I->submitForm('form[action="/admin/users/' . $userId . '/delete"]', []);

        $I->seeCurrentUrlEquals('/admin/users');
        // Soft delete (ADR-020 / FEATURE-110): the account is disabled, not removed.
        $I->seeUserHasStatus($userId, 'inactive');
    }

    public function adminCanSaveConfig(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123', ['roles' => ['ROLE_SUPER_ADMIN']]); // settings: superadmin only (audit #2)
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/config');
        $I->seeElement('section.config-page[data-slug="general"]');

        // The page renders one form per config provider; target the general one.
        $I->submitForm('form[action="/admin/config/general"]', [
            'fields' => ['registration.mode' => 'invitation-only'],
        ]);

        $I->seeCurrentUrlEquals('/admin/config');
        $I->see('Configuration saved.', '.flash-success');
        // The saved value is reflected back on reload.
        $I->seeInField('fields[registration.mode]', 'invitation-only');
    }

    public function adminCanViewAuditLog(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/audit-log');

        $I->seeResponseCodeIs(200);
        $I->see('Audit Log', 'h1');
    }

    public function adminCanSendInvitation(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users/invite');
        $I->submitForm('form', [
            'email' => 'invitee@example.com',
        ]);

        // Invitation creation redirects to the invitations list, where it is shown.
        $I->amOnPage('/admin/users/invitations');
        $I->see('invitee@example.com', '.invitation-email');
    }
}
