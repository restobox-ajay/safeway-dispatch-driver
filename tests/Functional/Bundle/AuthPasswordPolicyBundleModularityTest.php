<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthPasswordPolicy\AuthPasswordPolicyBundle;
use App\Bundle\AuthPasswordPolicy\Config\PasswordPolicyConfigPage;
use App\Bundle\AuthPasswordPolicy\Entity\PasswordHistory;
use App\Bundle\AuthPasswordPolicy\Entity\PasswordMeta;
use App\Bundle\AuthPasswordPolicy\EventListener\PasswordExpiryListener;
use App\Bundle\AuthPasswordPolicy\Repository\PasswordMetaRepository;
use App\Bundle\AuthPasswordPolicy\Security\PasswordExpiryChecker;
use App\Bundle\AuthPasswordPolicy\Security\PasswordPolicyManager;
use App\Bundle\AuthPasswordPolicy\Service\PasswordHistoryService;
use App\Bundle\AuthPasswordPolicy\Service\PasswordPolicyService;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-password-policy-bundle (FEATURE-145 AC4): with the bundle
 * REGISTERED its policy / expiry / reuse services + its /admin/config sub-page + the password_meta /
 * password_history mappings all exist; with it NOT registered those services are absent from the container,
 * its config sub-page is gone, the satellite entities are unmapped, AND core login still works with no
 * policy/expiry/reuse enforcement — NOT a 403 / feature flag. The bundle owns NO routes (the change-password
 * / forced-change routes stay in core AccountController; /admin/config is served by the core
 * AdminConfigController), so — like auth-security-bundle — there is nothing to 404; absence is proven at the
 * service + config-page + mapping level.
 *
 * The negative case boots a kernel that filters the bundle out of registerBundles() (the same code path as
 * removing it from config/bundles.php) and asserts absence directly, proving the app still compiles and runs
 * without it: core's App\Security\PasswordPolicyManagerInterface falls back to its null-object, so nothing is
 * validated, no password expires, and no reuse is checked — the "no policy/expiry/reuse enforcement when
 * uninstalled" guarantee.
 *
 * Note the password_meta / password_history TABLES physically linger in the shared var/test.db (the primary
 * test kernel migrated them in); "table/logic gone" is therefore proven at the mapping + service level —
 * with the bundle absent nothing maps or touches them, and on a real bundle-less deploy the bundle-owned
 * migrations never run so password_meta is never created and password_changed_at is never dropped.
 */
final class AuthPasswordPolicyBundleModularityTest extends WebTestCase
{
    /** Bundle-owned services that must exist iff the bundle is registered. */
    private const POLICY_SERVICES = [
        PasswordPolicyService::class,
        PasswordHistoryService::class,
        PasswordExpiryChecker::class,
        PasswordPolicyManager::class,
        PasswordExpiryListener::class,
        PasswordMetaRepository::class,
        PasswordPolicyConfigPage::class,
    ];

    public function testServicesConfigPageAndMappingExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        foreach (self::POLICY_SERVICES as $service) {
            self::assertTrue(
                $container->has($service),
                "Service $service must exist when auth-password-policy-bundle is registered."
            );
        }

        // Behavioural proof that the admin /config sub-page is registered via ConfigPageProviderInterface
        // when the bundle's autoconfigured services load (closes review C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('password-policy'),
            'The password-policy config sub-page must be present when the bundle is registered.'
        );

        // The satellite entities are mapped only when the bundle prepends its doctrine mapping.
        $em = $container->get(EntityManagerInterface::class);
        self::assertFalse(
            $em->getMetadataFactory()->isTransient(PasswordMeta::class),
            'password_meta must be a mapped entity when the bundle is registered.'
        );
        self::assertFalse(
            $em->getMetadataFactory()->isTransient(PasswordHistory::class),
            'password_history must be a mapped entity when the bundle is registered.'
        );
    }

    public function testServicesConfigPageAndMappingAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthPasswordPolicyKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            // PasswordPolicyConfigPage is in POLICY_SERVICES: its absence here is the proof the
            // 'password-policy' /admin/config sub-page is gone — with the bundle unregistered the class is
            // never loaded as a service, so nothing tagged auth.config_page can register the sub-page.
            foreach (self::POLICY_SERVICES as $service) {
                self::assertFalse(
                    $container->has($service),
                    "Service $service must be absent when auth-password-policy-bundle is not registered."
                );
            }

            // The satellite entities are unmapped — no policy/expiry/reuse table/logic in play (the bundle's
            // doctrine mapping is prepended only when the bundle is present). Reach the EM via the public
            // `doctrine` registry since the raw kernel container hides the private EM alias.
            $em = $container->get('doctrine')->getManager();
            self::assertTrue(
                $em->getMetadataFactory()->isTransient(PasswordMeta::class),
                'password_meta must be unmapped when the bundle is not registered.'
            );
            self::assertTrue(
                $em->getMetadataFactory()->isTransient(PasswordHistory::class),
                'password_history must be unmapped when the bundle is not registered.'
            );

            // Core login still works with no policy/expiry/reuse enforcement: the login page renders normally.
            $browser = new KernelBrowser($kernel);
            $browser->request('GET', '/staff/login');
            self::assertSame(
                200,
                $browser->getResponse()->getStatusCode(),
                'Core login must still work when auth-password-policy-bundle is absent.'
            );
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-password-policy-bundle is not registered — the
 * automated stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its compiled
 * container separate from the primary test kernel's.
 */
final class NoAuthPasswordPolicyKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthPasswordPolicyBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authpasswordpolicy';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authpasswordpolicy';
    }
}
