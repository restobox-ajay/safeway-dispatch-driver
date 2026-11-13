<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-134 (review C18): admin-impersonation EXIT must restore the original superadmin by
 * identifier (loaded via the admin user provider) — never by unserialize()ing a session blob —
 * and must fail closed if that superadmin can no longer be loaded (disabled/deleted).
 */
final class AdminImpersonationExitTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
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
            $this->em->getConnection()->executeStatement("DELETE FROM admin WHERE email LIKE 'imp_exit_test_%'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email, array $roles = []): Admin
    {
        return $this->createTestAdmin($email, 'Test Admin ' . $email, roles: $roles);
    }

    /**
     * Start impersonation of $target and return the crawler for the page rendering the
     * admin-impersonation exit form (the banner in base.html.twig).
     */
    private function startImpersonation(Admin $target): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form    = $crawler->filter('form[action="/admin/superadmin/admins/' . $target->getId() . '/impersonate"]')->form();
        $this->client->submit($form);

        $dashCrawler = $this->client->followRedirect(); // /admin/dashboard, banner rendered
        $this->assertStringContainsString(
            'admin-impersonation-banner',
            (string) $this->client->getResponse()->getContent(),
            'Impersonation should be active after start'
        );

        return $dashCrawler;
    }

    // AC5: exit restores the original superadmin (identity + roles), no unserialize involved.
    public function testExitRestoresOriginalSuperadmin(): void
    {
        $superadmin = $this->createAdmin('imp_exit_test_super@example.com', ['ROLE_SUPER_ADMIN']);
        $target     = $this->createAdmin('imp_exit_test_target@example.com', []);

        $this->loginAsAdmin($superadmin->getEmail());
        $dashCrawler = $this->startImpersonation($target);

        // Exit via the banner form (supplies the real admin_impersonate_exit CSRF token).
        $exitForm = $dashCrawler->filter('form[action="/admin/impersonate-admin-exit"]')->form();
        $this->client->submit($exitForm);
        $this->assertResponseStatusCodeSame(302);

        $this->client->followRedirect(); // -> /admin/superadmin/admins

        // The superadmin-only page is reachable (ROLE_SUPER_ADMIN restored), not bounced to login,
        // and the impersonation banner is gone.
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('admin-impersonation-banner', $content);
    }

    // AC6: if the original superadmin is disabled mid-impersonation, exit fails closed —
    // no restored/forged admin session survives.
    public function testExitFailsClosedWhenOriginalSuperadminDisabled(): void
    {
        $superadmin = $this->createAdmin('imp_exit_test_super2@example.com', ['ROLE_SUPER_ADMIN']);
        $target     = $this->createAdmin('imp_exit_test_target2@example.com', []);

        $this->loginAsAdmin($superadmin->getEmail());
        $dashCrawler = $this->startImpersonation($target);

        // Disable the original superadmin while impersonation is in progress. Clear the EM so the
        // provider reloads the (now inactive) row rather than the stale identity-map copy.
        $this->em->getConnection()->executeStatement(
            'UPDATE admin SET status = ? WHERE email = ?',
            ['inactive', 'imp_exit_test_super2@example.com']
        );
        $this->em->clear();

        $exitForm = $dashCrawler->filter('form[action="/admin/impersonate-admin-exit"]')->form();
        $this->client->submit($exitForm);

        // Exit fails closed: it drops the admin token and redirects to a clean admin login.
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));

        // No live admin session remains: a superadmin-only page bounces to login.
        $this->client->request('GET', '/admin/superadmin/admins');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
