<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImpersonateConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('impersonateconfig@example.com', 'Impersonate Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('impersonate.enabled');
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'impersonateconfig@example.com']);
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

    // AC1: Impersonation config sub-page appears in /admin/config when the bundle is installed
    public function testImpersonateConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('impersonateconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="impersonate"]');
    }

    // AC2: Saving the form persists any config values to the database
    public function testSavingImpersonateConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('impersonateconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="impersonate"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/impersonate', [
            '_token' => $configToken,
            'fields' => [
                'impersonate.enabled' => '1',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();
        $value = $conn->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['impersonate.enabled']
        );
        $this->assertSame('1', $value);
    }
}
