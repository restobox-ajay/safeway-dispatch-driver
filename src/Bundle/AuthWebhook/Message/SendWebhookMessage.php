<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Message;

/**
 * A pending webhook delivery. Dispatched on the request (login, registration, reset, lockout)
 * and handled off the request path by SendWebhookMessageHandler on a Messenger worker, so a
 * slow or unreachable webhook endpoint can never hang or 500 the triggering request (ADR-023).
 */
final class SendWebhookMessage
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
    ) {
    }
}
