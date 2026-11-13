<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthIpWhitelist\AuthIpWhitelistBundle;
use App\Bundle\AuthIpWhitelist\Config\IpWhitelistConfigPage;
use App\Bundle\AuthIpWhitelist\Entity\UserIpWhitelist;
use App\Bundle\AuthIpWhitelist\EventListener\IpWhitelistListener;
use App\Bundle\AuthIpWhitelist\Repository\UserIpWhitelistRepository;
use App\Bundle\AuthIpWhitelist\Security\IpWhitelistManager;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-ip-whitelist-bundle (FEATURE-146 AC4): with the bundle REGISTERED
 * its login listener + façade manager + satellite repository + its /admin/config sub-page + the
 * user_ip_whitelist mapping all exist; with it NOT registered those services are absent from the container,
 * its config sub-page is gone, the satellite entity is unmapped, AND core login still works with NO IP
 * restriction — NOT a 403 / feature flag. The bundle owns NO routes (login enforcement is a CheckPassport
 * event listener, not a route; /admin/config is served by the core AdminConfigController), so — like
 * auth-security-bundle / auth-password-policy-bundle — there is nothing to 404; absence is proven at the
 * service + config-page + mapping level.
 *
 * The negative case boots a kernel that filters the bundle out of registerBundles() (the same code path as
 * removing it from config/bundles.php) and asserts absence directly, proving the app still compiles and runs
 * without it: with the listener gone there is no IP whitelist enforcement at all, and core's
 * App\Security\IpWhitelistManagerInterface falls back to its null-object so no per-user override is stored or
 * read — the "no IP restriction on login when uninstalled" guarantee.
 *
 * Note the user_ip_whitelist TABLE physically lingers in the shared var/test.db (the primary test kernel
 * migrated it in); "table/logic gone" is therefore proven at the mapping + service level — with the bundle
 * absent nothing maps or touches it, and on a real bundle-less deploy the bundle-owned migration never runs
 * so user_ip_whitelist is never created and allowed_ips is never dropped.
 */
final class AuthIpWhitelistBundleModularityTest extends WebTestCase
{
    /** Bundle-owned services that must exist iff the bundle is registered. */
    private const WHITELIST_SERVICES = [
        IpWhitelistListener::class,
        IpWhitelistManager::class,
        UserIpWhitelistRepository::class,
        IpWhitelistConfigPage::class,
    ];

    public function testServicesConfigPageAndMappingExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        foreach (self::WHITELIST_SERVICES as $service) {
            self::assertTrue(
                $container->has($service),
                "Service $service must exist when auth-ip-whitelist-bundle is registered."
            );
        }

        // Behavioural proof that the admin /config sub-page is registered via ConfigPageProviderInterface
        // when the bundle's autoconfigured services load (closes review C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('ip-whitelist'),
            'The ip-whitelist config sub-page must be present when the bundle is registered.'
        );

        // The satellite entity is mapped only when the bundle prepends its doctrine mapping.
        $em = $container->get(EntityManagerInterface::class);
        self::assertFalse(
            $em->getMetadataFactory()->isTransient(UserIpWhitelist::class),
            'user_ip_whitelist must be a mapped entity when the bundle is registered.'
        );

        // The lockout-recovery command (issue #17) ships with the bundle.
        self::assertTrue(
            (new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->has('app:ip-whitelist:clear'),
            'app:ip-whitelist:clear must exist when the bundle is registered.'
        );
    }

    public function testServicesConfigPageAndMappingAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthIpWhitelistKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            // IpWhitelistConfigPage is in WHITELIST_SERVICES: its absence here is the proof the
            // 'ip-whitelist' /admin/config sub-page is gone — with the bundle unregistered the class is
            // never loaded as a service, so nothing tagged auth.config_page can register the sub-page.
            foreach (self::WHITELIST_SERVICES as $service) {
                self::assertFalse(
                    $container->has($service),
                    "Service $service must be absent when auth-ip-whitelist-bundle is not registered."
                );
            }

            // The satellite entity is unmapped — no IP-whitelist table/logic in play (the bundle's doctrine
            // mapping is prepended only when the bundle is present). Reach the EM via the public `doctrine`
            // registry since the raw kernel container hides the private EM alias.
            $em = $container->get('doctrine')->getManager();
            self::assertTrue(
                $em->getMetadataFactory()->isTransient(UserIpWhitelist::class),
                'user_ip_whitelist must be unmapped when the bundle is not registered.'
            );

            self::assertFalse(
                (new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel))->has('app:ip-whitelist:clear'),
                'app:ip-whitelist:clear must be absent when the bundle is not registered.'
            );

            // Core login still works with NO IP restriction: the login page renders normally.
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/staff/login');
            self::assertSame(
                200,
                $browser->getResponse()->getStatusCode(),
                'Core login must still work when auth-ip-whitelist-bundle is absent.'
            );
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-ip-whitelist-bundle is not registered — the
 * automated stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its compiled
 * container separate from the primary test kernel's.
 */
final class NoAuthIpWhitelistKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthIpWhitelistBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authipwhitelist';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authipwhitelist';
    }
}
