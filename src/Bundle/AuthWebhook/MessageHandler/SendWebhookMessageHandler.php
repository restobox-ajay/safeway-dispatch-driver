<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\MessageHandler;

use App\Bundle\AuthWebhook\Message\SendWebhookMessage;
use App\Bundle\AuthWebhook\Service\HttpWebhookDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs on the Messenger worker (transport: async). Performs the actual outbound HTTP POST and
 * writes the WebhookDelivery audit row via the single-attempt sender. On a non-2xx / blocked /
 * unreachable result it throws, so Messenger applies its retry_strategy (exponential backoff);
 * once retries are exhausted the message lands in the `failed` failure transport (ADR-023).
 */
#[AsMessageHandler]
final class SendWebhookMessageHandler
{
    public function __construct(
        private readonly HttpWebhookDispatcher $sender,
    ) {
    }

    public function __invoke(SendWebhookMessage $message): void
    {
        $delivery = $this->sender->send($message->url, $message->payload);

        if ($delivery->getStatus() !== 'delivered') {
            // Throwing hands control back to Messenger, which retries per retry_strategy and
            // finally dead-letters to the failure transport. Each attempt logs its own row.
            throw new \RuntimeException(sprintf(
                'Webhook delivery to %s failed (response=%s); Messenger will retry.',
                $message->url,
                $delivery->getResponseCode() !== null ? (string) $delivery->getResponseCode() : 'none',
            ));
        }
    }
}
