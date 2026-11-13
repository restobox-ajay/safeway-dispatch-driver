<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\DependencyInjection\Compiler;

use App\Bundle\AuthWebhook\Service\MessengerWebhookDispatcher;
use App\Service\NullWebhookDispatcher;
use App\Service\WebhookDispatcherInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the stable core alias App\Service\WebhookDispatcherInterface
 * from its null-object default (defined in the app's config/services.yaml) to the real
 * MessengerWebhookDispatcher, so the three core auth flows (registration, password-reset, lockout) and
 * the bundle's WebhookListener actually enqueue deliveries (FEATURE-141).
 *
 * It ONLY upgrades when the alias currently points at {@see NullWebhookDispatcher} — i.e. the prod/dev
 * default. The test and acceptance envs explicitly bind the port to InMemoryWebhookDispatcher (the
 * inspectable double the existing webhook tests read); checking the alias TARGET rather than the env
 * name leaves that override untouched without hardcoding env strings.
 *
 * A compiler pass rather than a services.php alias because the app's config/services.yaml — which
 * defines the null-object default — is loaded AFTER every bundle extension, so a bundle-level alias
 * would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthWebhookServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MessengerWebhookDispatcher::class)) {
            return;
        }

        $portId = WebhookDispatcherInterface::class;
        if (!$container->hasAlias($portId)) {
            return;
        }

        // Only upgrade the prod/dev null default; leave a test/acceptance InMemory override in place.
        if ((string) $container->getAlias($portId) === NullWebhookDispatcher::class) {
            $container->setAlias($portId, MessengerWebhookDispatcher::class);
        }
    }
}
