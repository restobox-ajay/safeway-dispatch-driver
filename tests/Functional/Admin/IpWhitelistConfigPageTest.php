<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IpWhitelistConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('ipwhitelistconfig@example.com', 'IP Whitelist Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('ip_whitelist.user_ips');
        $this->cleanupConfigKey('ip_whitelist.admin_ips');
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'ipwhitelistconfig@example.com']);
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

    // AC1: IP whitelist config sub-page appears in /admin/config when the bundle is installed
    public function testIpWhitelistConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('ipwhitelistconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="ip-whitelist"]');
    }

    // AC2: Sub-page allows adding/removing global IPs and role-specific IPs (user_ips and admin_ips fields)
    public function testIpWhitelistConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('ipwhitelistconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="ip-whitelist"] input[name="fields[ip_whitelist.user_ips]"]');
        $this->assertSelectorExists('[data-slug="ip-whitelist"] input[name="fields[ip_whitelist.admin_ips]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingIpWhitelistConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('ipwhitelistconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="ip-whitelist"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/ip-whitelist', [
            '_token' => $configToken,
            'fields' => [
                'ip_whitelist.user_ips'  => '192.168.1.0/24,10.0.0.1',
                'ip_whitelist.admin_ips' => '127.0.0.1',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn      = $this->em->getConnection();
        $userIps   = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['ip_whitelist.user_ips']);
        $adminIps  = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['ip_whitelist.admin_ips']);

        $this->assertSame('192.168.1.0/24,10.0.0.1', $userIps);
        $this->assertSame('127.0.0.1', $adminIps);
    }

    /** @param array<string,string> $fields */
    private function saveIpWhitelist(array $fields): void
    {
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="ip-whitelist"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/ip-whitelist', ['_token' => $configToken, 'fields' => $fields]);
    }

    private function storedConfig(string $key): string|false
    {
        return $this->em->getConnection()->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);
    }

    // Issue #17: an admin list that does not admit the admin saving it would lock every admin out once sessions end.
    public function testAnAdminListThatExcludesTheSaversOwnIpIsRefused(): void
    {
        $this->loginAsAdmin('ipwhitelistconfig@example.com');

        $this->saveIpWhitelist(['ip_whitelist.user_ips' => '', 'ip_whitelist.admin_ips' => '198.51.100.0/24']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('127.0.0.1', (string) $this->client->getResponse()->getContent());
        $this->assertFalse($this->storedConfig('ip_whitelist.admin_ips'), 'nothing is saved');
    }

    // Issue #17: a malformed entry used to be stored and then rejected every login.
    public function testMalformedEntriesAreRefusedInsteadOfLockingEveryoneOut(): void
    {
        $this->loginAsAdmin('ipwhitelistconfig@example.com');

        $this->saveIpWhitelist(['ip_whitelist.user_ips' => '10.0.0.0/33', 'ip_whitelist.admin_ips' => '127.0.0.1 198.51.100.7']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFalse($this->storedConfig('ip_whitelist.admin_ips'));
        $this->assertFalse($this->storedConfig('ip_whitelist.user_ips'));
    }
}

