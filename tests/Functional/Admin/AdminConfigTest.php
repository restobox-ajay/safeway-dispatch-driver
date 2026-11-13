<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Service\ConfigService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminConfigTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        // Settings are changed by a superadmin only (audit #2).
        $this->createTestAdmin('configtest@example.com', 'Config Test Admin', 'configpassword', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTestAdmin('configplain@example.com', 'Plain Admin', 'configpassword');
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('registration.mode');
        $this->cleanupConfigKey('test.ac1.key');
        $this->cleanupConfigKey('webhook.global_url');
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log WHERE action = 'admin.config_update'");
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            foreach (['configtest@example.com', 'configplain@example.com'] as $email) {
                $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
                if ($admin) {
                    $this->em->remove($admin);
                    $this->em->flush();
                }
            }
            $this->em->clear();
        } catch (\Throwable) {}
    }

    // Audit #2: settings govern security (the superadmins' own 2FA, IP lists, sessions): a plain admin sees
    // them read-only and cannot save them, even with a valid form token.
    public function testAPlainAdminSeesTheSettingsButCannotChangeThem(): void
    {
        $this->loginAsAdmin('configplain@example.com', 'configpassword');
        $crawler = $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-slug="general"] button[type="submit"]'));
        $this->assertGreaterThan(0, $crawler->filter('[data-slug="general"] input[disabled]')->count());

        $token = $crawler->filter('[data-slug="general"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/general', [
            '_token' => $token,
            'fields' => ['registration.mode' => 'invitation-only'],
        ]);
        $this->assertResponseStatusCodeSame(403);
        $this->assertNotSame('invitation-only', $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['registration.mode']
        ));
    }

    private function cleanupConfigKey(string $key): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM config WHERE config_key = ?',
                [$key]
            );
        } catch (\Throwable) {}
    }

    // AC1: Config values stored and retrieved from the 'config' database table
    public function testConfigValuesStoredAndRetrievedFromDatabase(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('test.ac1.key', 'test-value');

        $retrieved = $configService->getString('test.ac1.key');
        $this->assertSame('test-value', $retrieved);

        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['test.ac1.key']
        );
        $this->assertSame('test-value', $row);
    }

    // AC2: GET /admin/config renders all registered sub-pages (returns 200)
    public function testAdminConfigPageRendersSuccessfully(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
    }

    // AC3: A service implementing ConfigPageProviderInterface is auto-tagged and appears in the config UI
    public function testGeneralConfigPageAppearsInUI(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="general"]');
    }

    // AC4: Saving a config form persists the new values to the database
    public function testSavingConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="general"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/general', [
            '_token' => $configToken,
            'fields' => ['registration.mode' => 'invitation-only'],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['registration.mode']
        );
        $this->assertSame('invitation-only', $row);
    }

    // Security (H1): a config save POST without a valid CSRF token is rejected, and
    // writes nothing — so a cross-site POST can't silently rewrite security config.
    public function testConfigSaveWithoutCsrfTokenIsRejected(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->client->request('POST', '/admin/config/general', [
            'fields' => ['registration.mode' => 'invitation-only'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['registration.mode']
        );
        $this->assertNotSame('invitation-only', $row);
    }

    // AC5: Unauthenticated access to /admin/config redirects to /admin/login
    public function testUnauthenticatedAccessRedirectsToAdminLogin(): void
    {
        $this->client->request('GET', '/admin/config');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/admin/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    /** @param array<string,string> $fields */
    private function saveConfigPage(string $slug, array $fields): void
    {
        $token = $this->client->request('GET', '/admin/config')
            ->filter(sprintf('[data-slug="%s"] input[name="_token"]', $slug))->attr('value');
        $this->client->request('POST', '/admin/config/' . $slug, ['_token' => $token, 'fields' => $fields]);
        $this->assertResponseRedirects('/admin/config');
    }

    /** @return list<array{actor:string,context:?string}> */
    private function configAuditRows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative("SELECT actor, context FROM audit_log WHERE action = 'admin.config_update' ORDER BY id");
    }

    // Issue #12: config changes (2FA enforcement, IP whitelists, rate limits, the SSRF guard...) are audited.
    public function testAConfigChangeIsAuditedWithWhoWhatAndOldToNewValues(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log WHERE action = 'admin.config_update'");

        $this->saveConfigPage('general', ['registration.mode' => 'invitation-only']);

        $rows = $this->configAuditRows();
        $this->assertCount(1, $rows, 'one row per save');
        $this->assertSame('configtest@example.com', $rows[0]['actor']);
        $this->assertStringContainsString('page=general', (string) $rows[0]['context']);
        $this->assertStringContainsString('registration.mode', (string) $rows[0]['context']);
        $this->assertStringContainsString('invitation-only', (string) $rows[0]['context'], 'non-secret values are recorded');
    }

    public function testFreeTextValuesSuchAsWebhookUrlsAreNotWrittenToTheAuditLog(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log WHERE action = 'admin.config_update'");

        $this->saveConfigPage('webhook', ['webhook.global_url' => 'https://hooks.example.com/T0K3N-s3cr3t']);

        $rows = $this->configAuditRows();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('webhook.global_url', (string) $rows[0]['context'], 'the changed key is recorded');
        $this->assertStringNotContainsString('T0K3N', (string) $rows[0]['context'], 'a URL may embed a secret, so its value is not');
    }

    public function testASaveThatChangesNothingWritesNoAuditRow(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->saveConfigPage('general', ['registration.mode' => 'invitation-only']);
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log WHERE action = 'admin.config_update'");

        $this->saveConfigPage('general', ['registration.mode' => 'invitation-only']);

        $this->assertSame([], $this->configAuditRows());
    }

    public function testASaveThatFailsValidationWritesNoAuditRow(): void
    {
        $this->loginAsAdmin('configtest@example.com', 'configpassword');
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log WHERE action = 'admin.config_update'");

        $token = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="general"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/general', ['_token' => $token, 'fields' => ['registration.mode' => 'not-a-mode']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->configAuditRows());
    }
}

