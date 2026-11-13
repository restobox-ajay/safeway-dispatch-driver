<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthMagicLink\AuthMagicLinkBundle;
use App\Bundle\AuthMagicLink\Config\MagicLinkConfigPage;
use App\Bundle\AuthMagicLink\Prune\MagicLinkTokenPruner;
use App\Bundle\AuthMagicLink\Repository\MagicLinkTokenRepository;
use App\Bundle\AuthMagicLink\Security\MagicLinkAuthenticator;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-magic-link-bundle (FEATURE-140 AC3): with the bundle
 * REGISTERED its routes + services + its /admin/config sub-page exist; with it NOT registered its
 * routes 404, its services are absent from the container, AND its config sub-page is gone — NOT a
 * 403 / feature flag. The negative case boots a kernel that filters the bundle out of
 * registerBundles() (the same code path as removing it from config/bundles.php) and asserts absence
 * directly, proving the app still compiles and runs without it.
 */
final class AuthMagicLinkBundleModularityTest extends WebTestCase
{
    private const MAGIC_LINK_ROUTES = [
        'app_magic_link',
        'app_magic_link_check',
        'app_magic_link_verify',
    ];

    public function testMagicLinkRoutesServicesAndConfigPageExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        $routes = $container->get('router')->getRouteCollection();
        foreach (self::MAGIC_LINK_ROUTES as $name) {
            self::assertNotNull($routes->get($name), "Route $name must exist when auth-magic-link-bundle is registered.");
        }

        self::assertTrue(
            $container->has(MagicLinkAuthenticator::class),
            'The magic-link authenticator service must exist when the bundle is registered.'
        );
        self::assertTrue(
            $container->has(MagicLinkTokenRepository::class),
            'The magic-link repository service must exist when the bundle is registered.'
        );
        // FEATURE-147 / ADR-048: the magic_link_tokens pruner is auth.pruner-tagged (hence part of
        // app:prune) ONLY when the bundle is registered.
        self::assertTrue(
            $container->has(MagicLinkTokenPruner::class),
            'The magic-link pruner service must exist when the bundle is registered.'
        );

        // Behavioural proof that the admin /config sub-page is registered via
        // ConfigPageProviderInterface when the bundle's autoconfigured services load (closes review
        // C12 for this bundle). Fetched through the WebTestCase test container, which exposes the
        // otherwise-private registry.
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('magic-link'),
            'The magic-link config sub-page must be present when the bundle is registered.'
        );
    }

    public function testMagicLinkRoutesReturn404ServicesAndConfigPageAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthMagicLinkKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            $routes = $container->get('router')->getRouteCollection();
            foreach (self::MAGIC_LINK_ROUTES as $name) {
                self::assertNull(
                    $routes->get($name),
                    "Route $name must NOT exist when auth-magic-link-bundle is not registered."
                );
            }

            self::assertFalse(
                $container->has(MagicLinkAuthenticator::class),
                'The magic-link authenticator service must be absent when the bundle is not registered.'
            );
            self::assertFalse(
                $container->has(MagicLinkTokenRepository::class),
                'The magic-link repository service must be absent when the bundle is not registered.'
            );
            // With the bundle unregistered its pruner is never tagged auth.pruner, so app:prune has no
            // magic_link_tokens pruner (FEATURE-147 / ADR-048).
            self::assertFalse(
                $container->has(MagicLinkTokenPruner::class),
                'The magic-link pruner service must be absent when the bundle is not registered.'
            );

            // The config sub-page provider is gone: with the bundle unregistered its
            // MagicLinkConfigPage is never loaded as a service, so nothing tagged auth.config_page can
            // put the 'magic-link' sub-page on /admin/config.
            self::assertFalse(
                $container->has(MagicLinkConfigPage::class),
                'The magic-link config sub-page provider must be absent when the bundle is not registered.'
            );

            // The route is gone, so the request falls through to a genuine 404 (the user firewall's
            // access_control permits ^/magic-link as PUBLIC_ACCESS, then routing 404s).
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/magic-link');
            self::assertSame(404, $browser->getResponse()->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-magic-link-bundle is not registered — the
 * automated stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its
 * compiled container separate from the primary test kernel's.
 */
final class NoAuthMagicLinkKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthMagicLinkBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authmagiclink';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authmagiclink';
    }
}
