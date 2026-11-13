<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-070 — E2E Cest: Admin Config UI & Audit Log.
 *
 * Five phpBrowser scenarios over live HTTP, one per acceptance criterion.
 * Harness (live server, DB reset/seed, login helpers) comes from FEATURE-064.
 */
final class AdminConfigAuditCest
{
    /** Every config sub-page registered via ConfigPageProviderInterface (matches each provider's getSlug()). */
    private const CONFIG_SLUGS = [
        'general',
        '2fa',
        'security',
        'magic-link',
        'password-policy',
        'ip-whitelist',
        'pat',
        'impersonate',
        'webhook',
    ];

    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    public function configPageRendersAllSubPages(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass', ['roles' => ['ROLE_SUPER_ADMIN']]); // settings: superadmin only (audit #2)
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/config');
        $I->see('Configuration');
        // Each provider renders its own <section class="config-page" data-slug="...">.
        foreach (self::CONFIG_SLUGS as $slug) {
            $I->seeElement('section.config-page[data-slug="' . $slug . '"]');
        }
        $I->see('General');
        $I->see('Security');
    }

    public function savingConfigPersistsAndReloads(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass', ['roles' => ['ROLE_SUPER_ADMIN']]);
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/config');
        // The page renders one form per provider; target the general one by its action.
        $I->submitForm('form[action="/admin/config/general"]', [
            'fields' => ['registration.mode' => 'invitation-only'],
        ]);

        $I->seeCurrentUrlEquals('/admin/config');
        $I->see('Configuration saved.');

        // Reload and confirm the value was durably persisted (reloaded from DB, not flash).
        $I->amOnPage('/admin/config');
        $I->seeInField('fields[registration.mode]', 'invitation-only');
        $I->seeConfigValue('registration.mode', 'invitation-only');
    }

    public function auditLogShowsPaginatedEntries(AcceptanceTester $I): void
    {
        // PAGE_SIZE is 20; 25 rows yields two pages.
        for ($i = 1; $i <= 25; $i++) {
            $I->seedAuditLog(sprintf('actor%02d@example.com', $i), 'user', 'login');
        }
        $I->createAdmin('admin@example.com', 'adminpass', ['roles' => ['ROLE_SUPER_ADMIN']]);
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/audit-log');
        $I->see('Audit Log');
        $I->seeElement('.entry-action');
        $I->seeElement('nav[aria-label="Pagination"]');
        $I->click('Next');
        $I->seeCurrentUrlEquals('/admin/audit-log?page=2');
        $I->seeElement('a[aria-current="page"]');
    }

    public function filterByActorNarrowsResults(AcceptanceTester $I): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $I->seedAuditLog('filler@example.com', 'user', 'login');
        }
        $I->seedAuditLog('needle@example.com', 'user', 'login');
        $I->createAdmin('admin@example.com', 'adminpass', ['roles' => ['ROLE_SUPER_ADMIN']]);
        $I->loginAsAdmin('admin@example.com', 'adminpass');

        $I->amOnPage('/admin/audit-log');
        // The only audit-log form is the GET filter form (targeted by its action).
        $I->submitForm('form[action="/admin/audit-log"]', ['actor' => 'needle']);

        $I->seeInCurrentUrl('actor=needle');
        $I->seeInField('actor', 'needle');
        $I->see('needle@example.com', '.entry-actor');
        $I->dontSee('filler@example.com');
    }

    public function nonAdminAccessToAuditLogRedirects(AcceptanceTester $I): void
    {
        $I->createUser('user@example.com', 'password');
        $I->loginAsUser('user@example.com', 'password');

        $I->amOnPage('/admin/audit-log');
        $I->seeCurrentUrlEquals('/admin/login');
    }
}
