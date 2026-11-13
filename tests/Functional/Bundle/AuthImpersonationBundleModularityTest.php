<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthImpersonation\AuthImpersonationBundle;
use App\Bundle\AuthImpersonation\Config\ImpersonateConfigPage;
use App\Bundle\AuthImpersonation\Security\ImpersonationAuthenticator;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-impersonate-bundle (FEATURE-142 AC3): with the bundle
 * REGISTERED its routes + services + its /admin/config sub-page exist; with it NOT registered its
 * routes 404, its services are absent from the container, AND its config sub-page is gone — NOT a
 * 403 / feature flag. The negative case boots a kernel that filters the bundle out of
 * registerBundles() (the same code path as removing it from config/bundles.php) and asserts absence
 * directly, proving the app still compiles and runs without it (core login unaffected — impersonation
 * owns no table, ADR-014/ADR-041).
 */
final class AuthImpersonationBundleModularityTest extends WebTestCase
{
    private const IMPERSONATE_ROUTES = [
        'app_impersonate_start',
        'app_impersonate_exit',
        'app_admin_impersonate_admin_exit',
    ];

    public function testImpersonateRoutesServicesAndConfigPageExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        $routes = $container->get('router')->getRouteCollection();
        foreach (self::IMPERSONATE_ROUTES as $name) {
            self::assertNotNull($routes->get($name), "Route $name must exist when auth-impersonate-bundle is registered.");
        }

        self::assertTrue(
            $container->has(ImpersonationAuthenticator::class),
            'The impersonation authenticator service must exist when the bundle is registered.'
        );

        // Behavioural proof that the admin /config sub-page is registered via
        // ConfigPageProviderInterface when the bundle's autoconfigured services load (closes review
        // C12 for this bundle). Fetched through the WebTestCase test container, which exposes the
        // otherwise-private registry.
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('impersonate'),
            'The impersonate config sub-page must be present when the bundle is registered.'
        );
    }

    public function testImpersonateRoutesReturn404ServicesAndConfigPageAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthImpersonationKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            $routes = $container->get('router')->getRouteCollection();
            foreach (self::IMPERSONATE_ROUTES as $name) {
                self::assertNull(
                    $routes->get($name),
                    "Route $name must NOT exist when auth-impersonate-bundle is not registered."
                );
            }

            self::assertFalse(
                $container->has(ImpersonationAuthenticator::class),
                'The impersonation authenticator service must be absent when the bundle is not registered.'
            );

            // The config sub-page provider is gone: with the bundle unregistered its
            // ImpersonateConfigPage is never loaded as a service, so nothing tagged auth.config_page
            // can put the 'impersonate' sub-page on /admin/config.
            // The config sub-page provider is gone: with the bundle unregistered its
            // ImpersonateConfigPage is never loaded as a service, so nothing tagged auth.config_page
            // can put the 'impersonate' sub-page on /admin/config. (The registry itself is a private
            // service not fetchable from this manually-booted kernel's real container, so absence is
            // asserted on the provider service directly — same as AuthMagicLinkBundleModularityTest.)
            self::assertFalse(
                $container->has(ImpersonateConfigPage::class),
                'The impersonate config sub-page provider must be absent when the bundle is not registered.'
            );

            // The route is gone, so the request falls through to a genuine 404 (the user firewall's
            // access_control permits ^/impersonate/start$ as PUBLIC_ACCESS, then routing 404s).
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/impersonate/start');
            self::assertSame(404, $browser->getResponse()->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-impersonate-bundle is not registered — the
 * automated stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its
 * compiled container separate from the primary test kernel's.
 */
final class NoAuthImpersonationKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthImpersonationBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authimpersonation';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authimpersonation';
    }
}
