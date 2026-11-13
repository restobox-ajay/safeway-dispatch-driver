<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfigValidationTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $this->createTestAdmin('configvalidation@example.com', 'Config Validation Admin', roles: ['ROLE_SUPER_ADMIN']); // only a superadmin changes settings (audit #2)
    }

    protected function tearDown(): void
    {
        foreach (['rate_limit.max_attempts', 'rate_limit.window_seconds', 'lockout.max_attempts', 'lockout.duration_minutes', 'registration.mode'] as $key) {
            $this->cleanupConfigKey($key);
        }
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'configvalidation@example.com']);
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
            $this->em->getConnection()->executeStatement('DELETE FROM config WHERE config_key = ?', [$key]);
        } catch (\Throwable) {}
    }

    private function tokenFor(string $slug): string
    {
        return $this->client->request('GET', '/admin/config')
            ->filter('[data-slug="' . $slug . '"] input[name="_token"]')->attr('value');
    }

    private function configRow(string $key): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);
    }

    // AC2/AC4: a non-integer for an int field is rejected, shows a field error, and is not persisted.
    public function testNonIntegerRateLimitMaxAttemptsRejected(): void
    {
        $this->loginAsAdmin('configvalidation@example.com');
        $token = $this->tokenFor('security');

        $this->client->request('POST', '/admin/config/security', [
            '_token' => $token,
            'fields' => [
                'rate_limit.max_attempts'   => 'banana',
                'rate_limit.window_seconds' => '300',
                'lockout.max_attempts'      => '0',
                'lockout.duration_minutes'  => '15',
            ],
        ]);

        // Rejected: re-rendered form (422), not a redirect.
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('.field-error[data-field="rate_limit.max_attempts"]');

        // Nothing from this page was persisted — including the fields that were valid.
        $this->assertFalse($this->configRow('rate_limit.max_attempts'));
        $this->assertFalse($this->configRow('rate_limit.window_seconds'));
    }

    // AC2: an out-of-set enum value is rejected and not persisted.
    public function testOutOfSetEnumRejected(): void
    {
        $this->loginAsAdmin('configvalidation@example.com');
        $token = $this->tokenFor('general');

        $this->client->request('POST', '/admin/config/general', [
            '_token' => $token,
            'fields' => ['registration.mode' => 'bogus-mode'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('.field-error[data-field="registration.mode"]');
        $this->assertFalse($this->configRow('registration.mode'));
    }

    // AC3: valid values still save and persist.
    public function testValidSaveStillPersists(): void
    {
        $this->loginAsAdmin('configvalidation@example.com');
        $token = $this->tokenFor('security');

        $this->client->request('POST', '/admin/config/security', [
            '_token' => $token,
            'fields' => [
                'rate_limit.max_attempts'   => '7',
                'rate_limit.window_seconds' => '600',
                'lockout.max_attempts'      => '3',
                'lockout.duration_minutes'  => '30',
            ],
        ]);

        $this->assertResponseRedirects('/admin/config');
        $this->assertSame('7', $this->configRow('rate_limit.max_attempts'));
        $this->assertSame('600', $this->configRow('rate_limit.window_seconds'));
    }
}
