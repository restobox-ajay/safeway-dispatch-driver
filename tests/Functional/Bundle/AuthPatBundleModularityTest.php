<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthPat\AuthPatBundle;
use App\Bundle\AuthPat\Config\PATConfigPage;
use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use App\Bundle\AuthPat\Security\TokenAuthenticator;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The REAL modularity guarantee for auth-pat-bundle (FEATURE-138 AC4): with the bundle REGISTERED
 * its routes + services exist; with it NOT registered its routes 404 and its services are absent from
 * the container — NOT a 403 / feature flag. The negative case boots a kernel that filters the bundle
 * out of registerBundles() (the same code path as removing it from config/bundles.php) and asserts
 * absence directly, proving the app still compiles and runs without it.
 */
final class AuthPatBundleModularityTest extends WebTestCase
{
    private const PAT_ROUTES = [
        'app_api_ping',
        'app_account_tokens',
        'app_account_tokens_new',
        'app_account_tokens_revoke',
        // The admin-API "revoke all of a user's PATs" endpoint (FEATURE-062 AC2/AC5). Moved into the
        // bundle by FEATURE-139 so it is genuinely absent when the bundle is uninstalled — the honest
        // form of "endpoints are only registered when their respective bundle is installed" (previously
        // faked as always-present-in-the-monorepo). Route-collection absence is the definitive signal:
        // an HTTP probe on /admin-api/* returns 401 for ANY path (access_control {path: ^/admin-api}
        // runs before routing), so the router — not a status code — is what proves non-registration.
        'app_api_admin_users_revoke_tokens',
    ];

    public function testPatRoutesAndServicesExistWhenBundleRegistered(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $routes = $container->get('router')->getRouteCollection();
        foreach (self::PAT_ROUTES as $name) {
            self::assertNotNull($routes->get($name), "Route $name must exist when auth-pat-bundle is registered.");
        }

        self::assertTrue(
            $container->has(PersonalAccessTokenRepository::class),
            'The PAT repository service must exist when the bundle is registered.'
        );
        self::assertTrue(
            $container->has(TokenAuthenticator::class),
            'The PAT authenticator service must exist when the bundle is registered.'
        );
        self::assertTrue(
            $container->has(PATConfigPage::class),
            'The PAT config-page service must exist when the bundle is registered.'
        );

        // Behavioural proof the admin /config sub-page is registered via ConfigPageProviderInterface
        // when the bundle's autoconfigured services load (closes review C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('pat'),
            'The PAT config sub-page must be present when the bundle is registered.'
        );

        // The bundle wires the stateless `api` firewall: /api demands a valid PAT (401, not 404),
        // which distinguishes "installed but unauthenticated" from "uninstalled" below.
        $client->request('GET', '/api/ping');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testPatRoutesReturn404AndServicesAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthPatKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            $routes = $container->get('router')->getRouteCollection();
            foreach (self::PAT_ROUTES as $name) {
                self::assertNull(
                    $routes->get($name),
                    "Route $name must NOT exist when auth-pat-bundle is not registered."
                );
            }

            self::assertFalse(
                $container->has(PersonalAccessTokenRepository::class),
                'The PAT repository service must be absent when the bundle is not registered.'
            );
            self::assertFalse(
                $container->has(TokenAuthenticator::class),
                'The PAT authenticator service must be absent when the bundle is not registered.'
            );
            // Config-page service absent -> nothing tagged auth.config_page -> the 'pat' /admin/config
            // sub-page is gone when the bundle is unregistered.
            self::assertFalse(
                $container->has(PATConfigPage::class),
                'The PAT config-page service must be absent when the bundle is not registered.'
            );

            // The route is gone AND there is no `api` firewall left to answer 401 — a genuine 404.
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/api/ping');
            self::assertSame(404, $browser->getResponse()->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    // Issue #27: without the bundle an application-level /api route must still be blocked. It used to run
    // anonymously: the null authenticator never supports a request and no access_control rule covered /api.
    public function testAnAppLevelApiRouteIsBlockedWithoutTheBundle(): void
    {
        $kernel = new NoAuthPatAppApiKernel('test', true);
        $kernel->boot();

        try {
            self::assertNotNull($kernel->getContainer()->get('router')->getRouteCollection()->get('test_app_api_orders'));

            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/api/app-orders');
            self::assertSame(401, $browser->getResponse()->getStatusCode());
            self::assertStringNotContainsString('secret order data', (string) $browser->getResponse()->getContent());

            // Presenting a bearer token changes nothing: without the bundle nothing can authenticate to /api.
            $browser->request('GET', '/api/app-orders', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('a', 64)]);
            self::assertSame(401, $browser->getResponse()->getStatusCode());
            self::assertStringNotContainsString('secret order data', (string) $browser->getResponse()->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    private const NAV_USER = 'patnav-user@example.com';

    /** Log a real user in on $browser and return the dashboard HTML (issue #14: core user pages must render). */
    private static function loginAndOpenDashboard(KernelBrowser $browser, \Doctrine\ORM\EntityManagerInterface $em): string
    {
        self::cleanupNavUser($em);
        $user = new \App\Entity\User();
        $user->setEmail(self::NAV_USER);
        $user->setName('PAT Nav User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $em->persist($user);
        $em->flush();
        $em->clear();

        $browser->request('GET', '/staff/login');
        $browser->submitForm('Sign in', ['email' => self::NAV_USER, 'password' => 'testpassword']);
        $browser->request('GET', '/dashboard');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'a logged-in user page must render: ' . substr((string) $browser->getResponse()->getContent(), 0, 300));

        return (string) $browser->getResponse()->getContent();
    }

    private static function cleanupNavUser(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::NAV_USER]);
        $conn->executeStatement('DELETE FROM login_history WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::NAV_USER]);
        $conn->executeStatement('DELETE FROM login_notification_seen WHERE user_id IN (SELECT id FROM "user" WHERE email = ?)', [self::NAV_USER]);
        $conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [self::NAV_USER]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::NAV_USER]);
    }

    public function testTheUserSidebarLinksApiTokensWhenTheBundleIsRegistered(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        try {
            $html = self::loginAndOpenDashboard($client, $em);
            self::assertStringContainsString('href="/account/tokens"', $html);
        } finally {
            self::cleanupNavUser($em);
        }
    }

    // Issue #14: the sidebar linked a bundle route unconditionally, so with the bundle removed every
    // logged-in user page threw RouteNotFoundException (500).
    public function testUserPagesStillRenderWithoutTheBundleAndHideTheApiTokensLink(): void
    {
        $kernel = new NoAuthPatKernel('test', true);
        $kernel->boot();
        $em = $kernel->getContainer()->get('doctrine')->getManager();

        try {
            $browser = new KernelBrowser($kernel);
            $html = self::loginAndOpenDashboard($browser, $em);
            self::assertStringNotContainsString('/account/tokens', $html);

            foreach (['/account/settings', '/account/sessions', '/account/password', '/account/login-history'] as $page) {
                $browser->request('GET', $page);
                self::assertSame(200, $browser->getResponse()->getStatusCode(), "$page must render without auth-pat-bundle");
            }
        } finally {
            self::cleanupNavUser($em);
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-pat-bundle is not registered — the automated
 * stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its compiled
 * container separate from the primary test kernel's.
 */
final class NoAuthPatKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthPatBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authpat';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authpat';
    }
}
