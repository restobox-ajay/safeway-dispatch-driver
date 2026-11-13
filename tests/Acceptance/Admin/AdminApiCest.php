<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the JSON Admin API over real HTTP (FEATURE-079): Bearer
 * (personal access token) authentication, the 401 responses for a missing or invalid
 * token, user CRUD, the user action endpoints (activate/deactivate/force-logout/
 * password-reset), invitation creation, and the audit-log endpoint.
 *
 * Per ADR-015 this is the per-criterion depth for the Admin API; FEATURE-064 only proves
 * the harness end-to-end. Requests are driven through ApiHelper (which delegates to the
 * shared PhpBrowser client's low-level _request, since the suite has no REST module), so
 * arbitrary verbs, the Authorization header, and raw JSON bodies travel over the live
 * server. DatabaseHelper resets every table before each scenario.
 *
 * The token owner is an Admin entity: the admin_api firewall's AdminTokenAuthenticator
 * loads the token's owner from the admin provider (admin table), so the admin API is
 * reachable only by a real Admin — never a User, regardless of any role the user carries.
 */
class AdminApiCest
{
    /**
     * Seed an Admin and an active admin access token for it; return the plaintext Bearer
     * credential. Fresh per scenario (the DB is reset in _before).
     */
    private function adminBearerToken(AcceptanceTester $I): string
    {
        $adminId = $I->createAdmin('apiadmin@example.com', 'password123');

        return $I->createAdminAccessToken($adminId);
    }

    // AC1: a request with no token → 401 JSON.
    public function missingTokenReturns401Json(AcceptanceTester $I): void
    {
        $I->sendApiRequest('GET', '/admin-api/users');

        $I->seeApiResponseCodeIs(401);
        $I->seeApiResponseContains('"error"');
    }

    // AC2: a request with an invalid token → 401 JSON.
    public function invalidTokenReturns401Json(AcceptanceTester $I): void
    {
        $I->sendApiRequest('GET', '/admin-api/users', null, 'not-a-real-token');

        $I->seeApiResponseCodeIs(401);
        $I->seeApiResponseContains('"error"');
    }

    // AC3: GET /admin-api/users → paginated JSON list.
    public function listUsersReturnsPaginatedJson(AcceptanceTester $I): void
    {
        $token = $this->adminBearerToken($I);
        $I->createUser('member@example.com', 'password123', ['name' => 'List Member']);

        $I->sendApiRequest('GET', '/admin-api/users', null, $token);

        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"meta"');
        $I->seeApiResponseContains('"total_pages"');
        $I->seeApiResponseContains('member@example.com');
    }

    // AC4: POST /admin-api/users → 201 with the new user.
    public function createUserReturns201(AcceptanceTester $I): void
    {
        $token = $this->adminBearerToken($I);

        $I->sendApiRequest('POST', '/admin-api/users', [
            'email'    => 'created@example.com',
            'name'     => 'Created User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ], $token);

        $I->seeApiResponseCodeIs(201);
        $I->seeApiResponseContains('created@example.com');
        // The 201 reflects a real row, not just an echoed payload.
        $I->seeExactlyOneUserWithEmail('created@example.com');
    }

    // AC5: PATCH /admin-api/users/{id} → 200 updated (and the change is durable).
    public function patchUserReturns200AndPersists(AcceptanceTester $I): void
    {
        $token  = $this->adminBearerToken($I);
        $userId = $I->createUser('patch-me@example.com', 'password123', ['name' => 'Original Name']);

        $I->sendApiRequest('PATCH', '/admin-api/users/' . $userId, [
            'name'   => 'Updated Name',
            'status' => 'inactive',
        ], $token);

        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('Updated Name');
        $I->seeApiResponseContains('inactive');

        // Re-fetch over HTTP: the update was persisted, not merely echoed back.
        $I->sendApiRequest('GET', '/admin-api/users/' . $userId, null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('Updated Name');
    }

    // AC6: DELETE /admin-api/users/{id} → 204.
    public function deleteUserReturns204(AcceptanceTester $I): void
    {
        $token  = $this->adminBearerToken($I);
        $userId = $I->createUser('delete-me@example.com', 'password123');

        $I->sendApiRequest('DELETE', '/admin-api/users/' . $userId, null, $token);

        $I->seeApiResponseCodeIs(204);
        // Soft delete (ADR-020 / FEATURE-110): the row is retained but flipped to inactive,
        // proving 204 was not a no-op.
        $I->seeUserHasStatus($userId, 'inactive');
    }

    // AC7: POST activate / deactivate / force-logout / password-reset → 200.
    public function userActionEndpointsReturn200(AcceptanceTester $I): void
    {
        $token  = $this->adminBearerToken($I);
        $userId = $I->createUser('actions@example.com', 'password123', ['status' => 'inactive']);

        $I->sendApiRequest('POST', '/admin-api/users/' . $userId . '/activate', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"status":"ok"');

        $I->sendApiRequest('POST', '/admin-api/users/' . $userId . '/deactivate', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"status":"ok"');

        $I->sendApiRequest('POST', '/admin-api/users/' . $userId . '/force-logout', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"status":"ok"');

        $I->sendApiRequest('POST', '/admin-api/users/' . $userId . '/password-reset', null, $token);
        $I->seeApiResponseCodeIs(200);
        // The password-reset action has a real side effect: a single-use reset token.
        $I->seeExactlyOnePasswordResetToken('actions@example.com');
    }

    // AC8: POST /admin-api/invitations → 201.
    public function createInvitationReturns201(AcceptanceTester $I): void
    {
        $token = $this->adminBearerToken($I);

        $I->sendApiRequest('POST', '/admin-api/invitations', [
            'email' => 'invitee@example.com',
        ], $token);

        $I->seeApiResponseCodeIs(201);
        $I->seeApiResponseContains('invitee@example.com');
        // A real invitation row was written.
        $I->seeExactlyOneInvitationForEmail('invitee@example.com');
    }

    // AC9: GET /admin-api/audit-log → paginated JSON.
    public function auditLogReturnsPaginatedJson(AcceptanceTester $I): void
    {
        $token = $this->adminBearerToken($I);
        $I->seedAuditLog('auditor@example.com', 'admin', 'admin.user_create', 'success');

        $I->sendApiRequest('GET', '/admin-api/audit-log', null, $token);

        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"meta"');
        $I->seeApiResponseContains('"total_pages"');
        $I->seeApiResponseContains('auditor@example.com');
    }
}
