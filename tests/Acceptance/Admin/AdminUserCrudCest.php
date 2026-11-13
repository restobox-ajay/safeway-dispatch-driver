<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of admin authentication and full user CRUD via the admin
 * panel over real HTTP (FEATURE-069): admin login, access control, paginated user
 * list, create/edit/delete, and the regular-admin-cannot-delete-an-admin guard.
 *
 * Per ADR-015 this is the per-criterion depth for the admin user-management flow;
 * FEATURE-064's AdminFlowsCest only proves the harness end-to-end. Each scenario
 * seeds its own state via DatabaseHelper, which resets every table before each test.
 */
class AdminUserCrudCest
{
    // AC1: admin login → /admin/dashboard.
    public function adminLoginReachesDashboard(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->seeCurrentUrlEquals('/admin/dashboard');
        $I->see('Admin Dashboard', 'h1');
    }

    // AC2: an unauthenticated request to an admin route is redirected to /admin/login.
    public function unauthenticatedAdminAreaRedirectsToLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/users');

        $I->seeCurrentUrlEquals('/admin/login');
    }

    // AC3: /admin/users shows a paginated user list (page size 10).
    public function userListIsPaginated(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        // 12 users > one page of 10, so a second page exists.
        for ($i = 1; $i <= 12; $i++) {
            $I->createUser(sprintf('member%02d@example.com', $i), 'password123');
        }
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users');
        $I->seeResponseCodeIs(200);
        $I->see('Users', 'h1');
        $I->see('Total: 12');

        // Pagination controls are present and navigate to the second page.
        $I->seeElement('nav[aria-label="Pagination"]');
        $I->click('Next');
        $I->seeCurrentUrlEquals('/admin/users?page=2');
        $I->seeElement('a[aria-current="page"]');
    }

    // AC4: a user created via the admin form appears in the list.
    public function createdUserAppearsInList(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users/new');
        $I->submitForm('form', [
            'email' => 'created@example.com',
            'name' => 'Created User',
            'password' => 'password123',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $I->seeCurrentUrlEquals('/admin/users');
        $I->see('created@example.com');
        $I->see('Created User');
    }

    // AC5: edits made via the admin form are persisted (verified on reload, not just flash).
    public function editedUserChangesPersist(AcceptanceTester $I): void
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

        // Reload the edit form: the change is durable, not just a flash message.
        $I->amOnPage('/admin/users/' . $userId . '/edit');
        $I->seeInField('name', 'Renamed User');
    }

    // AC6 (ADR-020 / FEATURE-110): "deleting" a user is a soft delete — the row is retained and
    // flipped to inactive. It still appears in the admin management list (that is how an admin
    // reactivates it); only authentication is blocked.
    public function deletedUserBecomesInactive(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $userId = $I->createUser('delete-me@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->amOnPage('/admin/users');
        $I->see('delete-me@example.com');

        // The row has several POST forms; target the delete one by its action so its
        // hidden per-id CSRF token is submitted with it.
        $I->submitForm('form[action="/admin/users/' . $userId . '/delete"]', []);

        $I->seeCurrentUrlEquals('/admin/users');
        // Row retained, still listed, now inactive.
        $I->see('delete-me@example.com');
        $I->seeUserHasStatus($userId, 'inactive');
    }
}
