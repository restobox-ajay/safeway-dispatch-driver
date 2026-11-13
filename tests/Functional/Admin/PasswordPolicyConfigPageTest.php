<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordPolicyConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('pwpolicyconfig@example.com', 'Password Policy Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        foreach ([
            'password_policy.min_length',
            'password_policy.require_uppercase',
            'password_policy.require_number',
            'password_policy.require_symbol',
            'password_policy.expiry_days',
            'password_policy.reuse_count',
        ] as $key) {
            $this->cleanupConfigKey($key);
        }
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'pwpolicyconfig@example.com']);
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

    // AC1: Password policy config sub-page appears in /admin/config when the bundle is installed
    public function testPasswordPolicyConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('pwpolicyconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="password-policy"]');
    }

    // AC2: Sub-page fields: min_length, require_uppercase, require_number, require_symbol, expiry_days, reuse_count
    public function testPasswordPolicyConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('pwpolicyconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.min_length]"]');
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.require_uppercase]"]');
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.require_number]"]');
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.require_symbol]"]');
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.expiry_days]"]');
        $this->assertSelectorExists('[data-slug="password-policy"] input[name="fields[password_policy.reuse_count]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingPasswordPolicyConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('pwpolicyconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="password-policy"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/password-policy', [
            '_token' => $configToken,
            'fields' => [
                'password_policy.min_length'        => '10',
                'password_policy.require_uppercase'  => '1',
                'password_policy.require_number'     => '1',
                'password_policy.expiry_days'        => '60',
                'password_policy.reuse_count'        => '5',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();

        $minLength = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.min_length']);
        $this->assertSame('10', $minLength);

        $requireUppercase = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.require_uppercase']);
        $this->assertSame('1', $requireUppercase);

        $requireNumber = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.require_number']);
        $this->assertSame('1', $requireNumber);

        $requireSymbol = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.require_symbol']);
        $this->assertSame('0', $requireSymbol);

        $expiryDays = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.expiry_days']);
        $this->assertSame('60', $expiryDays);

        $reuseCount = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['password_policy.reuse_count']);
        $this->assertSame('5', $reuseCount);
    }
}
