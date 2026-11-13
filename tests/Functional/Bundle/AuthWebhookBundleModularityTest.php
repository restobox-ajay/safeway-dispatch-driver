<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bundle;

use App\Bundle\AuthWebhook\AuthWebhookBundle;
use App\Bundle\AuthWebhook\Config\WebhookConfigPage;
use App\Bundle\AuthWebhook\EventListener\WebhookListener;
use App\Bundle\AuthWebhook\MessageHandler\SendWebhookMessageHandler;
use App\Bundle\AuthWebhook\Prune\WebhookDeliveryPruner;
use App\Bundle\AuthWebhook\Repository\WebhookDeliveryRepository;
use App\Bundle\AuthWebhook\Service\HttpWebhookDispatcher;
use App\Config\ConfigPageRegistry;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The REAL modularity guarantee for auth-webhook-bundle (FEATURE-141 AC3): with the bundle REGISTERED
 * its webhook services + its /admin/config sub-page exist; with it NOT registered those services are
 * absent from the container and its config sub-page is gone — so no webhooks fire. The webhook feature
 * owns NO routes (its config is served by the core AdminConfigController), so — unlike the magic-link /
 * impersonate bundles — there is nothing to 404; absence is proven at the service + config-page level.
 *
 * The negative case boots a kernel that filters the bundle out of registerBundles() (the same code path
 * as removing it from config/bundles.php) and asserts absence directly, proving the app still compiles
 * and runs without it. With the WebhookListener service gone and core's WebhookDispatcherInterface
 * bound to App\Service\NullWebhookDispatcher (prod) / InMemoryWebhookDispatcher (test), dispatch() emits
 * nothing — the "no webhooks fire when uninstalled" guarantee.
 */
final class AuthWebhookBundleModularityTest extends WebTestCase
{
    /** Bundle-owned services that must exist iff the bundle is registered. */
    private const WEBHOOK_SERVICES = [
        WebhookListener::class,
        HttpWebhookDispatcher::class,
        SendWebhookMessageHandler::class,
        WebhookDeliveryRepository::class,
        WebhookConfigPage::class,
        // FEATURE-147 / ADR-048: the webhook_delivery pruner is auth.pruner-tagged (hence part of
        // app:prune) ONLY when the bundle is registered.
        WebhookDeliveryPruner::class,
    ];

    public function testWebhookServicesAndConfigPageExistWhenBundleRegistered(): void
    {
        static::createClient();
        $container = static::getContainer();

        foreach (self::WEBHOOK_SERVICES as $service) {
            self::assertTrue(
                $container->has($service),
                "Service $service must exist when auth-webhook-bundle is registered."
            );
        }

        // Behavioural proof that the admin /config sub-page is registered via
        // ConfigPageProviderInterface when the bundle's autoconfigured services load (closes review
        // C12 for this bundle).
        $registry = $container->get(ConfigPageRegistry::class);
        self::assertNotNull(
            $registry->getBySlug('webhook'),
            'The webhook config sub-page must be present when the bundle is registered.'
        );
    }

    public function testWebhookServicesAndConfigPageAbsentWhenBundleNotRegistered(): void
    {
        $kernel = new NoAuthWebhookKernel('test', true);
        $kernel->boot();

        try {
            // Issue #21: the bundle's services are all PRIVATE, and a compiled container's has() never reports a
            // private service — so asserting absence on $kernel->getContainer() passed whether or not the bundle
            // was registered. test.service_container (test env) sees private services, as the positive case does.
            $container = $kernel->getContainer()->get('test.service_container');

            foreach (self::WEBHOOK_SERVICES as $service) {
                self::assertFalse(
                    $container->has($service),
                    "Service $service must be absent when auth-webhook-bundle is not registered."
                );
            }

            // Behavioural proof, not just service ids: the 'webhook' /admin/config sub-page is gone, and no
            // WebhookListener is attached to any event — so no webhook can fire.
            self::assertNull(
                $container->get(ConfigPageRegistry::class)->getBySlug('webhook'),
                'The webhook config sub-page must be gone when the bundle is not registered.'
            );
            foreach ($container->get('event_dispatcher')->getListeners() as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $target = \is_array($listener) ? $listener[0] : $listener;
                    if ($target instanceof \Closure) {
                        $target = (new \ReflectionFunction($target))->getClosureThis() ?? $target;
                    }
                    self::assertNotInstanceOf(WebhookListener::class, $target, "No WebhookListener may listen to $event.");
                }
            }
        } finally {
            $kernel->shutdown();
        }
    }
}

/**
 * A kernel identical to the app kernel except that auth-webhook-bundle is not registered — the
 * automated stand-in for "removed from config/bundles.php". A distinct cache/build dir keeps its
 * compiled container separate from the primary test kernel's.
 */
final class NoAuthWebhookKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        foreach (parent::registerBundles() as $bundle) {
            if ($bundle instanceof AuthWebhookBundle) {
                continue;
            }
            yield $bundle;
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/no_authwebhook';
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '/no_authwebhook';
    }
}
