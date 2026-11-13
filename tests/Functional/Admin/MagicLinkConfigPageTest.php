<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MagicLinkConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('magiclinkconfig@example.com', 'Magic Link Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('magic_link.expiry_minutes');
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'magiclinkconfig@example.com']);
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

    // AC1: Magic link config sub-page appears in /admin/config when the bundle is installed
    public function testMagicLinkConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('magiclinkconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="magic-link"]');
    }

    // AC2: Sub-page field: link_expiry_minutes
    public function testMagicLinkConfigSubPageHasRequiredField(): void
    {
        $this->loginAsAdmin('magiclinkconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="magic-link"] input[name="fields[magic_link.expiry_minutes]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingMagicLinkConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('magiclinkconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="magic-link"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/magic-link', [
            '_token' => $configToken,
            'fields' => [
                'magic_link.expiry_minutes' => '30',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn  = $this->em->getConnection();
        $value = $conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', ['magic_link.expiry_minutes']);
        $this->assertSame('30', $value);
    }
}
