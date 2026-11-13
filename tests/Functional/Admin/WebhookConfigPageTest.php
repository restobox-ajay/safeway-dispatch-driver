<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WebhookConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('webhookconfig@example.com', 'Webhook Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        foreach (['webhook.global_url', 'webhook.login_url', 'webhook.registration_url', 'webhook.password_reset_url', 'webhook.lockout_url', 'webhook.max_retry_attempts'] as $key) {
            $this->cleanupConfigKey($key);
        }
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'webhookconfig@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
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

    // AC1: Webhook config sub-page appears in /admin/config when the bundle is installed
    public function testWebhookConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('webhookconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="webhook"]');
    }

    // AC2: Sub-page fields: global_url, per-event URLs, max_retry_attempts
    public function testWebhookConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('webhookconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.global_url]"]');
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.login_url]"]');
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.registration_url]"]');
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.password_reset_url]"]');
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.lockout_url]"]');
        $this->assertSelectorExists('[data-slug="webhook"] input[name="fields[webhook.max_retry_attempts]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingWebhookConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('webhookconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="webhook"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/webhook', [
            '_token' => $configToken,
            'fields' => [
                'webhook.global_url'         => 'https://example.com/webhook',
                'webhook.login_url'          => '',
                'webhook.registration_url'   => '',
                'webhook.password_reset_url' => '',
                'webhook.lockout_url'        => '',
                'webhook.max_retry_attempts' => '5',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();

        $globalUrl = $conn->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['webhook.global_url']
        );
        $this->assertSame('https://example.com/webhook', $globalUrl);

        $maxRetry = $conn->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['webhook.max_retry_attempts']
        );
        $this->assertSame('5', $maxRetry);
    }
}
