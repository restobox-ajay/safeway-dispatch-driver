<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\Service\ConfigService;
use App\Service\LoginNotificationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginNotificationConfigTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = self::getContainer()->get('doctrine.dbal.default_connection');
            foreach (['lnconfig1@example.com'] as $email) {
                $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [$email]);
            }
            $conn->executeStatement(
                "DELETE FROM config WHERE config_key = ?",
                ['login_notifications.enabled']
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $name, bool $notificationsEnabled = true): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setLoginNotificationsEnabled($notificationsEnabled);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function loginAs(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'testpassword',
        ]);
    }

    // AC1: Admin config has a 'login_notifications.enabled' boolean setting
    public function testAdminConfigHasLoginNotificationsEnabledSetting(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);

        // Default should be true (no row in DB)
        $this->assertTrue($configService->getBool('login_notifications.enabled', true));

        // Saving '1' persists correctly
        $configService->set('login_notifications.enabled', '1');
        $this->assertTrue($configService->getBool('login_notifications.enabled', true));

        // Saving '0' persists correctly
        $configService->set('login_notifications.enabled', '0');
        $this->assertFalse($configService->getBool('login_notifications.enabled', true));

        $row = $this->em->getConnection()->fetchOne(
            'SELECT config_value FROM config WHERE config_key = ?',
            ['login_notifications.enabled']
        );
        $this->assertSame('0', $row);
    }

    // AC2: User entity has a login_notifications_enabled boolean field (default true)
    public function testUserEntityHasLoginNotificationsEnabledColumn(): void
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $columns = $conn->fetchAllAssociative('PRAGMA table_info("user")');
        $columnNames = array_column($columns, 'name');

        $this->assertContains('login_notifications_enabled', $columnNames);

        // Default value is 1 (true)
        $defaultRow = array_filter($columns, fn($c) => $c['name'] === 'login_notifications_enabled');
        $defaultRow = array_values($defaultRow)[0];
        $this->assertSame('1', (string) $defaultRow['dflt_value']);
    }

    // AC3: GET /account/settings shows a toggle for the user's own login notification preference
    public function testSettingsPageShowsToggle(): void
    {
        $this->createUser('lnconfig1@example.com', 'LN Config User');
        $this->loginAs('lnconfig1@example.com');

        $this->client->request('GET', '/account/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="login_notifications_enabled"]');
        $this->assertSelectorExists('input[type="checkbox"]');
    }

    // AC4: Toggling the user preference persists the change
    public function testTogglingPreferencePersists(): void
    {
        $this->createUser('lnconfig1@example.com', 'LN Config User');
        $this->loginAs('lnconfig1@example.com');

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        // Default is true — toggle off by submitting the form with the checkbox unchecked
        $crawler = $this->client->request('GET', '/account/settings');
        $form = $crawler->selectButton('Save Settings')->form();
        $form['login_notifications_enabled']->untick();
        $this->client->submit($form);

        $this->assertResponseRedirects('/account/settings');

        $value = $conn->fetchOne(
            'SELECT login_notifications_enabled FROM "user" WHERE email = ?',
            ['lnconfig1@example.com']
        );
        $this->assertSame('0', (string) $value);

        // Toggle back on by submitting with checkbox checked
        $crawler = $this->client->request('GET', '/account/settings');
        $form = $crawler->selectButton('Save Settings')->form();
        $form['login_notifications_enabled']->tick();
        $this->client->submit($form);

        $this->assertResponseRedirects('/account/settings');

        $value = $conn->fetchOne(
            'SELECT login_notifications_enabled FROM "user" WHERE email = ?',
            ['lnconfig1@example.com']
        );
        $this->assertSame('1', (string) $value);
    }

    // AC5: When a user's preference is false, no notification is sent regardless of global config
    public function testCheckerReturnsFalseWhenUserPrefDisabled(): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $checker = new LoginNotificationChecker($configService);

        // Global enabled, user pref disabled → shouldNotify = false
        $configService->set('login_notifications.enabled', '1');

        $user = new User();
        $user->setEmail('lnconfig1@example.com');
        $user->setName('LN Test');
        $user->setPassword('x');
        $user->setLoginNotificationsEnabled(false);

        $this->assertFalse($checker->shouldNotifyUser($user));

        // Global disabled, user pref enabled → shouldNotify = false
        $configService->set('login_notifications.enabled', '0');
        $user->setLoginNotificationsEnabled(true);

        $this->assertFalse($checker->shouldNotifyUser($user));

        // Global enabled, user pref enabled → shouldNotify = true
        $configService->set('login_notifications.enabled', '1');
        $this->assertTrue($checker->shouldNotifyUser($user));
    }
}
