<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImpersonateTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();

        $this->createTestAdmin('imptest_admin@example.com', 'Impersonate Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'imptest_%'");
            $conn->executeStatement("DELETE FROM admin WHERE email = 'imptest_admin@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, array $roles = []): User
    {
        return $this->createTestUser($email, 'Impersonate Target', 'userpass', 'active', $roles);
    }

    private function submitImpersonateForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/impersonate-start"]')->form();
        $this->client->submit($form);
    }

    // AC1: Admin user list has an 'Impersonate' button
    public function testAdminUserListHasImpersonateButton(): void
    {
        $user = $this->createUser('imptest_user1@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/admin/users/' . $user->getId() . '/impersonate-start"]');
    }

    // AC2: Clicking impersonate switches the session to act as that user
    public function testImpersonateStartSwitchesSessionToUser(): void
    {
        $user = $this->createUser('imptest_user2@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        // Follow redirect to /impersonate/start → authenticator runs → redirect to /dashboard
        $this->client->followRedirect();
        // Follow redirect to /dashboard
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('imptest_user2@example.com', $content);
    }

    // AC3: A persistent banner indicates active impersonation
    public function testImpersonationBannerShownOnPage(): void
    {
        $user = $this->createUser('imptest_user3@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        $this->client->followRedirect(); // /impersonate/start → /dashboard
        $this->client->followRedirect(); // → /dashboard

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('impersonation-banner', $content);
        $this->assertStringContainsString('imptest_user3@example.com', $content);
    }

    // AC4: Clicking 'Exit impersonation' restores the admin session
    public function testExitImpersonationRestoresAdminSession(): void
    {
        $user = $this->createUser('imptest_user4@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());

        $this->client->followRedirect(); // /impersonate/start → /dashboard
        $this->client->followRedirect(); // → /dashboard

        // Submit the exit form from the banner
        $crawler = $this->client->getCrawler();
        $exitForm = $crawler->filter('form[action="/impersonate/exit"]')->form();
        $this->client->submit($exitForm);

        // Follow redirect to /admin/dashboard
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Admin Dashboard', $content);
    }

    // Issue #64: exit must actually sign the browser out of the impersonated user's account. ContextListener used
    // to write the still-loaded user token back into the session at response time, so after "Exit Impersonation"
    // the browser was still logged in as the user (no banner, no 2FA skip, but full access).
    public function testExitImpersonationSignsTheBrowserOutOfTheUsersAccount(): void
    {
        $user = $this->createUser('imptest_user5@example.com');

        $this->loginAsAdmin('imptest_admin@example.com');
        $this->submitImpersonateForm((int) $user->getId());
        $this->client->followRedirect(); // /impersonate/start → /dashboard
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user->getId()]), 'impersonating creates a user session row');

        $this->client->submit($this->client->getCrawler()->filter('form[action="/impersonate/exit"]')->form());
        $this->assertResponseRedirects('/admin/dashboard');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/staff/login', null, 'the user area must be closed after exit');
        $this->client->request('GET', '/account/settings');
        $this->assertResponseRedirects('/staff/login');
        $this->assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user->getId()]), 'the impersonated session row is gone');

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful('the admin stays signed in');
    }
}

