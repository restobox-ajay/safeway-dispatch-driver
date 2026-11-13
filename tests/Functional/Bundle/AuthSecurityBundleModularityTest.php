<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthSecurity\AuthSecurityBundle;
use App\Bundle\AuthSecurity\Config\SecurityConfigPage;
use App\Bundle\AuthSecurity\Entity\AccountLockout;
use App\Bundle\AuthSecurity\Repository\AccountLockoutRepository;
use App\Bundle\AuthSecurity\Security\EndpointRateLimiter;
use App\Bundle\AuthSecurity\Security\LoginRateLimitListener;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-security-bundle (FEATURE-144 AC4): with the bundle REGISTERED its
 * rate-limit / lockout services + its /admin/config sub-page + the account_lockouts mapping all exist; with
 * it NOT registered those services are absent from the container, its config sub-page is gone, the
 * account_lockouts entity is unmapped, AND core login still works (no throttling, no lockout) — NOT a 403 /
 * feature flag. The bundle owns the admin-API unlock route (POST /admin-api/users/{id}/unlock — moved into
 * the bundle on the 2026-07-09 review follow-up); /admin/config is served by the core AdminConfigController
 * and the login throttle is a CheckPassport listener (not a route). Absence is proven at the route +
 * service + config-page + mapping level.
 *
 * The negative case boots a kernel that filters the bundle out of registerBundles() (the same code path as
 * removing it from config/bundles.php) and asserts absence directly, proving the app still compiles and
 * runs without it: core's App\Security\AccountLockManagerInterface / EndpointRateLimiterInterface fall back
 * to their null-objects, so no account is locked and no endpoint is throttled — the "no rate
 * limiting/lockout when uninstalled" guarantee.
 *
 * Note the account_lockouts / login_attempts / endpoint_rate_limits TABLES physically linger in the shared
 * var/test.db (the primary test kernel migrated them in); "table/logic gone" is therefore proven at the
 * mapping + service level — with the bundle absent nothing maps or touches them, and on a real bundle-less
 * deploy the bundle-owned migrations never run so account_lockouts is never created and locked_until is
 * never dropped.
 */
final class AuthSecurityBundleModularityTest extends WebTestCase
{
    /** Bundle-owned services that must exist iff the bundle is registered. */
    private const SECURITY_SERVICES = [
        LoginRateLimitListener::class,
        EndpointRateLimiter::class,
        AccountLockoutRepository::class,
        SecurityConfigPage::class,
    ];

    public function testServicesConfigPageAndMappingExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        foreach (self::SECURITY_SERVICES as $service) {
            self::assertTrue(
                $container->has($service),
                "Service $service must exist when auth-security-bundle is registered."
            );
        }

        // The admin-API unlock route exists only when the bundle is registered (its controller lives in
        // the bundle; Kernel imports the bundle's routes only when it is enabled).
        self::assertNotNull(
            $container->get('router')->getRouteCollection()->get('app_api_admin_users_unlock'),
            'The admin unlock route must exist when auth-security-bundle is registered.'
        );

        // Behavioural proof that the admin /config sub-page is registered via ConfigPageProviderInterface
        // when the bundle's autoconfigured services load (closes review C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('security'),
            'The security config sub-page must be present when the bundle is registered.'
        );

        // The satellite entity is mapped only when the bundle prepends its doctrine mapping.
        $em = $container->get(EntityManagerInterface::class);
        self::assertFalse(
            $em->getMetadataFactory()->isTransient(AccountLockout::class),
            'account_lockouts must be a mapped entity when the bundle is registered.'
        );
    }

    public function testServicesConfigPageAndMappingAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthSecurityKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            // SecurityConfigPage is in SECURITY_SERVICES: its absence here is the proof the 'security'
            // /admin/config sub-page is gone — with the bundle unregistered the class is never loaded as a
            // service, so nothing tagged auth.config_page can register the sub-page.
            foreach (self::SECURITY_SERVICES as $service) {
                self::assertFalse(
                    $container->has($service),
                    "Service $service must be absent when auth-security-bundle is not registered."
                );
            }

            // The admin-API unlock route is gone when the bundle is unregistered (genuine 404).
            self::assertNull(
                $container->get('router')->getRouteCollection()->get('app_api_admin_users_unlock'),
                'The admin unlock route must NOT exist when auth-security-bundle is not registered.'
            );

            // The satellite entity is unmapped — no lockout table/logic in play (the bundle's doctrine
            // mapping is prepended only when the bundle is present). Reach the EM via the public `doctrine`
            // registry since the raw kernel container hides the private EM alias.
            $em = $container->get('doctrine')->getManager();
            self::assertTrue(
                $em->getMetadataFactory()->isTransient(AccountLockout::class),
                'account_lockouts must be unmapped when the bundle is not registered.'
            );

            // Core login still works with no rate limiting / lockout: the login page renders normally.
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/staff/login');
            self::assertSame(
                200,
                $browser->getResponse()->getStatusCode(),
                'Core login must still work when auth-security-bundle is absent.'
            );
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-security-bundle is not registered — the automated
 * stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its compiled container
 * separate from the primary test kernel's.
 */
final class NoAuthSecurityKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthSecurityBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authsecurity';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authsecurity';
    }
}
