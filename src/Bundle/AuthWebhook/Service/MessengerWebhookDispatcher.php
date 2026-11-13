<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Service;

use App\Bundle\AuthWebhook\Message\SendWebhookMessage;
use App\Service\WebhookDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Production/dev binding of WebhookDispatcherInterface. Instead of making the outbound HTTP call
 * on the request path, it enqueues a SendWebhookMessage; SendWebhookMessageHandler performs the
 * delivery on a Messenger worker (ADR-023). The request returns after a single message dispatch.
 *
 * The core alias App\Service\WebhookDispatcherInterface is upgraded to this class by the bundle's
 * RegisterAuthWebhookServicesPass (from the NullWebhookDispatcher default) when the bundle is registered.
 */
final class MessengerWebhookDispatcher implements WebhookDispatcherInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function dispatch(string $url, array $payload): void
    {
        $this->bus->dispatch(new SendWebhookMessage($url, $payload));
    }
}
