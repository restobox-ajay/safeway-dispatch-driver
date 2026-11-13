<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * The API docs, over real HTTP against the live acceptance server (ADR-060). The headline check is the
 * one the docs cannot fake: the number of calls the docs list must equal the number of API endpoints the
 * app actually has. (OpenApiSpecTest / OpenApiContractTest in PHPUnit go deeper — exact route sets and
 * every response's shape — but this is the end-to-end tripwire on what the server really serves.)
 *
 * Not covered here on purpose: fetching the vendored Swagger UI files. The acceptance server runs with
 * public/index.php as its router script, which treats any existing static file as the front controller and
 * fatals (real web servers serve public/ files natively). Their presence, size, licence and boot script are
 * pinned by ApiDocsPageTest instead.
 */
class ApiDocsCest
{
    private const TS_EMAIL = 'techsup-docs@example.com';
    private const TS_SECRET = 'JBSWY3DPEHPK3PXP';

    /** Tech support gets the complete docs (incl. the Htaccess Lock API); mandatory 2FA is cleared like a real login. */
    private function fetchServedSpecAsTechSupport(AcceptanceTester $I): string
    {
        $I->createTechSupportAdminWith2fa(self::TS_EMAIL, 'password123', self::TS_SECRET);
        $I->amOnPage('/admin/login');
        $I->submitForm('form', ['email' => self::TS_EMAIL, 'password' => 'password123']);
        $I->amOnPage('/admin/dashboard');
        $I->submitForm('form', ['_code' => $I->generateTotpCode(self::TS_SECRET)]);

        $I->amOnPage('/admin/api-docs/openapi.json');
        $I->seeResponseCodeIs(200);

        return $I->grabPageSource();
    }

    /** Every other admin class gets the docs minus what it cannot call. */
    private function fetchServedSpecAsPlainAdmin(AcceptanceTester $I, string $role = 'ROLE_ADMIN'): string
    {
        $I->createAdmin('admin@example.com', 'password123', ['roles' => [$role]]);
        $I->loginAsAdmin('admin@example.com', 'password123');
        $I->amOnPage('/admin/api-docs/openapi.json');
        $I->seeResponseCodeIs(200);

        return $I->grabPageSource();
    }

    public function docsListExactlyAsManyCallsAsTheAppHasApiEndpoints(AcceptanceTester $I): void
    {
        $served = $this->fetchServedSpecAsTechSupport($I);

        $I->seeDocumentedCallCountEqualsRealEndpointCount($served);
    }

    public function everyRealApiEndpointIsDocumentedAndNothingDocumentedIsPhantom(AcceptanceTester $I): void
    {
        $served = $this->fetchServedSpecAsTechSupport($I);

        $I->seeDocumentedCallsAreExactlyTheRealEndpoints($served);
    }

    public function aPlainAdminIsShownExactlyTheCallsTheyCanMakeAndNoHintOfTheRest(AcceptanceTester $I): void
    {
        $served = $this->fetchServedSpecAsPlainAdmin($I);

        $I->seeDocumentedCallCountEqualsRealEndpointCount($served, false);
        $I->seeDocumentedCallsAreExactlyTheRealEndpoints($served, false);
        $I->seeServedSpecDoesNotMention($served, 'htaccess');
    }

    public function aSuperAdminIsNotShownTheTechSupportOnlyCallsEither(AcceptanceTester $I): void
    {
        $served = $this->fetchServedSpecAsPlainAdmin($I, 'ROLE_SUPER_ADMIN');

        $I->seeDocumentedCallsAreExactlyTheRealEndpoints($served, false);
        $I->seeServedSpecDoesNotMention($served, 'htaccess');
    }

    public function techSupportSeesTheHtaccessLockApiInTheDocs(AcceptanceTester $I): void
    {
        $served = $this->fetchServedSpecAsTechSupport($I);

        $I->seeServedSpecMentions($served, '/admin-api/htaccess-lock/ips');
        $I->seeServedSpecMentions($served, 'addWhitelistedIp');
    }

    public function adminsSeeTheDocsPageWithTheSwaggerUiAndASidebarLink(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'password123');
        $I->loginAsAdmin('admin@example.com', 'password123');

        $I->click('API Docs', 'aside.sidebar');

        $I->seeCurrentUrlEquals('/admin/api-docs');
        $I->see('API Docs', 'h1');
        $I->seeElement('#swagger-ui[data-spec-url="/admin/api-docs/openapi.json"]');
        $I->seeElement('script[src="/vendor/swagger-ui/swagger-ui-bundle.js"]');
    }

    public function anonymousVisitorsCannotReadTheApiDescription(AcceptanceTester $I): void
    {
        $I->amOnPage('/admin/api-docs/openapi.json');

        $I->seeCurrentUrlEquals('/admin/login');
        $I->dontSee('"openapi"');
    }
}
