<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TwoFactorConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('2faconfig@example.com', '2FA Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        foreach (['2fa.enforcement', 'trusted_device.lifetime_days', '2fa.trusted_ips'] as $key) {
            $this->cleanupConfigKey($key);
        }
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => '2faconfig@example.com']);
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

    // AC1: 2FA config sub-page appears in /admin/config when the bundle is installed
    public function testTwoFactorConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('2faconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="2fa"]');
    }

    // AC2: Sub-page fields: enforcement level, trusted device duration, trusted IP list
    public function testTwoFactorConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('2faconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="2fa"] input[name="fields[2fa.enforcement]"]');
        $this->assertSelectorExists('[data-slug="2fa"] input[name="fields[trusted_device.lifetime_days]"]');
        $this->assertSelectorExists('[data-slug="2fa"] input[name="fields[2fa.trusted_ips]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingTwoFactorConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('2faconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="2fa"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/2fa', [
            '_token' => $configToken,
            'fields' => [
                '2fa.enforcement'              => 'required',
                'trusted_device.lifetime_days' => '14',
                '2fa.trusted_ips'              => '192.168.1.1',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();

        $enforcement = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['2fa.enforcement']);
        $this->assertSame('required', $enforcement);

        $lifetime = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['trusted_device.lifetime_days']);
        $this->assertSame('14', $lifetime);

        $ips = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['2fa.trusted_ips']);
        $this->assertSame('192.168.1.1', $ips);
    }
}
