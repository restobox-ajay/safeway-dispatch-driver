<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('securityconfig@example.com', 'Security Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        foreach (['rate_limit.max_attempts', 'rate_limit.window_seconds', 'lockout.max_attempts', 'lockout.duration_minutes'] as $key) {
            $this->cleanupConfigKey($key);
        }
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'securityconfig@example.com']);
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

    // AC1: Security config sub-page appears in /admin/config when the bundle is installed
    public function testSecurityConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('securityconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="security"]');
    }

    // AC2: Sub-page fields: max_attempts, lockout_duration_minutes, rate_limit_window_seconds
    public function testSecurityConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('securityconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="security"] input[name="fields[rate_limit.max_attempts]"]');
        $this->assertSelectorExists('[data-slug="security"] input[name="fields[rate_limit.window_seconds]"]');
        $this->assertSelectorExists('[data-slug="security"] input[name="fields[lockout.max_attempts]"]');
        $this->assertSelectorExists('[data-slug="security"] input[name="fields[lockout.duration_minutes]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingSecurityConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('securityconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="security"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/security', [
            '_token' => $configToken,
            'fields' => [
                'rate_limit.max_attempts'  => '5',
                'rate_limit.window_seconds' => '600',
                'lockout.max_attempts'     => '10',
                'lockout.duration_minutes' => '30',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();

        $maxAttempts = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['rate_limit.max_attempts']);
        $this->assertSame('5', $maxAttempts);

        $windowSeconds = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['rate_limit.window_seconds']);
        $this->assertSame('600', $windowSeconds);

        $lockoutAttempts = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['lockout.max_attempts']);
        $this->assertSame('10', $lockoutAttempts);

        $lockoutDuration = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['lockout.duration_minutes']);
        $this->assertSame('30', $lockoutDuration);
    }
}
