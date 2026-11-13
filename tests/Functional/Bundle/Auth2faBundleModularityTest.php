<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\Auth2fa\Auth2faBundle;
use App\Bundle\Auth2fa\Config\TwoFactorConfigPage;
use App\Bundle\Auth2fa\Controller\TwoFactorController;
use App\Bundle\Auth2fa\Entity\TwoFactorSettings;
use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\Auth2fa\Security\TrustedDeviceManager;
use App\Bundle\Auth2fa\Security\TwoFactorGuard;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-2fa-bundle (FEATURE-143 AC4): with the bundle REGISTERED its
 * user-2FA routes + services + its /admin/config sub-page + the two_factor_settings mapping all exist;
 * with it NOT registered its routes 404, its services are absent from the container, its config sub-page
 * is gone, the two_factor_settings entity is unmapped, AND core login still works (no 2FA) — NOT a 403 /
 * feature flag. The negative case boots a kernel that filters the bundle out of registerBundles() (the
 * same code path as removing it from config/bundles.php) and asserts absence directly, proving the app
 * still compiles and runs without it.
 *
 * Note the two_factor_settings TABLE physically lingers in the shared var/test.db (the primary test
 * kernel migrated it in); "table/logic gone" is therefore proven at the mapping + service level — with
 * the bundle absent nothing maps or touches it, and on a real bundle-less deploy the bundle-owned
 * migration never runs so the table is never created.
 */
final class Auth2faBundleModularityTest extends WebTestCase
{
    private const TWO_FACTOR_ROUTES = [
        'app_2fa_setup',
        'app_2fa_disable',
        'app_2fa_challenge',
        // Admin-API "reset a user's 2FA" — moved into the bundle (review follow-up 2026-07-09); present
        // only when the bundle is registered, 404 when uninstalled.
        'app_api_admin_users_reset_2fa',
    ];

    public function testRoutesServicesConfigPageAndMappingExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        $routes = $container->get('router')->getRouteCollection();
        foreach (self::TWO_FACTOR_ROUTES as $name) {
            self::assertNotNull($routes->get($name), "Route $name must exist when auth-2fa-bundle is registered.");
        }

        self::assertTrue($container->has(TwoFactorController::class), 'The 2FA controller must exist when the bundle is registered.');
        self::assertTrue($container->has(TwoFactorGuard::class), 'TwoFactorGuard must exist when the bundle is registered.');
        self::assertTrue($container->has(TrustedDeviceManager::class), 'TrustedDeviceManager must exist when the bundle is registered.');
        self::assertTrue($container->has(TwoFactorSettingsRepository::class), 'The satellite repository must exist when the bundle is registered.');

        // Behavioural proof that the admin /config sub-page is registered via ConfigPageProviderInterface
        // when the bundle's autoconfigured services load (closes review C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull($registry->getBySlug('2fa'), 'The 2FA config sub-page must be present when the bundle is registered.');

        // The satellite entity is mapped only when the bundle prepends its doctrine mapping (isTransient
        // is false for a mapped entity).
        $em = $container->get(EntityManagerInterface::class);
        self::assertFalse(
            $em->getMetadataFactory()->isTransient(TwoFactorSettings::class),
            'two_factor_settings must be a mapped entity when the bundle is registered.'
        );
    }

    public function testRoutesServicesConfigPageAndMappingAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuth2faKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            $routes = $container->get('router')->getRouteCollection();
            foreach (self::TWO_FACTOR_ROUTES as $name) {
                self::assertNull($routes->get($name), "Route $name must NOT exist when auth-2fa-bundle is not registered.");
            }

            self::assertFalse($container->has(TwoFactorController::class), 'The 2FA controller must be absent when the bundle is not registered.');
            self::assertFalse($container->has(TwoFactorGuard::class), 'TwoFactorGuard must be absent when the bundle is not registered.');
            self::assertFalse($container->has(TrustedDeviceManager::class), 'TrustedDeviceManager must be absent when the bundle is not registered.');
            self::assertFalse($container->has(TwoFactorSettingsRepository::class), 'The satellite repository must be absent when the bundle is not registered.');
            // With the bundle unregistered its TwoFactorConfigPage is never loaded as a service, so
            // nothing tagged auth.config_page can put the '2fa' sub-page on /admin/config. (The raw
            // kernel container doesn't expose the private ConfigPageRegistry, so the sub-page's absence
            // is proven here by the provider service being absent.)
            self::assertFalse($container->has(TwoFactorConfigPage::class), 'The 2FA config sub-page provider must be absent when the bundle is not registered.');

            // The satellite entity is unmapped — no 2FA table/logic in play (the bundle's doctrine
            // mapping is prepended only when the bundle is present). Reach the EM via the public
            // `doctrine` registry since the raw kernel container hides the private EM alias.
            $em = $container->get('doctrine')->getManager();
            self::assertTrue(
                $em->getMetadataFactory()->isTransient(TwoFactorSettings::class),
                'two_factor_settings must be unmapped when the bundle is not registered.'
            );

            // Core login still works with no 2FA: the login page renders normally.
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/staff/login');
            self::assertSame(200, $browser->getResponse()->getStatusCode(), 'Core login must still work when auth-2fa-bundle is absent.');
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-2fa-bundle is not registered — the automated
 * stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its compiled container
 * separate from the primary test kernel's.
 */
final class NoAuth2faKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof Auth2faBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_auth2fa';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_auth2fa';
    }
}
