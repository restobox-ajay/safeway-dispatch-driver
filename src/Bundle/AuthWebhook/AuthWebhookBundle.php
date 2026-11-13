<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook;

use App\Bundle\AuthWebhook\DependencyInjection\Compiler\RegisterAuthWebhookServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-webhook-bundle — the outbound auth-event webhook feature packaged as an OPTIONAL Symfony bundle
 * (FEATURE-141, C36 Option A). Reuses the auth-magic-link-bundle own-table template (FEATURE-140 /
 * ADR-040): the bundle owns the WebhookDelivery entity + repository + migration, the async Messenger
 * delivery path (SendWebhookMessage + handler + HttpWebhookDispatcher, ADR-023), the SSRF guard
 * (ADR-027), the login-event WebhookListener, and its admin /config sub-page (WebhookConfigPage).
 * Registered per deploy in config/bundles.php.
 *
 * When it is NOT registered none of its services/config sub-page exist and no webhooks fire: core
 * falls back to the null-object binding App\Service\NullWebhookDispatcher (dispatch() is a no-op). See
 * ADR-042.
 */
final class AuthWebhookBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object alias (App\Service\WebhookDispatcherInterface) to the real
        // MessengerWebhookDispatcher. A compiler pass — not the extension — because the app's
        // services.yaml (which defines the null default) is loaded AFTER bundle extensions and would
        // otherwise win.
        $container->addCompilerPass(new RegisterAuthWebhookServicesPass());
    }
}
