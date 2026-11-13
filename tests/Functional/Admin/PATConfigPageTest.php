<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PATConfigPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('patconfig@example.com', 'PAT Config Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        $this->cleanupConfigKey('pat.default_expiry_days');
        $this->cleanupConfigKey('pat.max_tokens_per_user');
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'patconfig@example.com']);
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

    // AC1: PAT config sub-page appears in /admin/config when the bundle is installed
    public function testPATConfigSubPageAppearsInAdminConfig(): void
    {
        $this->loginAsAdmin('patconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="pat"]');
    }

    // AC2: Sub-page fields: default_expiry_days, max_tokens_per_user
    public function testPATConfigSubPageHasRequiredFields(): void
    {
        $this->loginAsAdmin('patconfig@example.com');
        $this->client->request('GET', '/admin/config');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-slug="pat"] input[name="fields[pat.default_expiry_days]"]');
        $this->assertSelectorExists('[data-slug="pat"] input[name="fields[pat.max_tokens_per_user]"]');
    }

    // AC3: Saving the form persists config values to the database
    public function testSavingPATConfigFormPersistsValues(): void
    {
        $this->loginAsAdmin('patconfig@example.com');
        $configToken = $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="pat"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/config/pat', [
            '_token' => $configToken,
            'fields' => [
                'pat.default_expiry_days' => '30',
                'pat.max_tokens_per_user' => '5',
            ],
        ]);
        $this->assertResponseRedirects('/admin/config');

        $conn = $this->em->getConnection();

        $expiryValue = $conn->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['pat.default_expiry_days']
        );
        $this->assertSame('30', $expiryValue);

        $maxValue = $conn->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['pat.max_tokens_per_user']
        );
        $this->assertSame('5', $maxValue);
    }
}
