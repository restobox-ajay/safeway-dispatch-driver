<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Prune;

use App\Bundle\AuthWebhook\Repository\WebhookDeliveryRepository;
use App\Prune\PrunerInterface;
use App\Service\ConfigService;

/**
 * Prunes webhook_delivery log rows attempted before `webhook.delivery_retention_days` (default 30),
 * regardless of status. Closes C11 residue for the delivery log (FEATURE-147 / ADR-048). Owned by
 * auth-webhook-bundle: autoconfigured (hence `auth.pruner`-tagged from PrunerInterface) ONLY when the
 * bundle is registered, so it appears in app:prune exclusively when webhooks are installed.
 */
final readonly class WebhookDeliveryPruner implements PrunerInterface
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private ConfigService $config,
    ) {}

    public function name(): string
    {
        return 'webhook_delivery';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->deliveries->countAttemptedBefore($this->cutoff($now));
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->deliveries->deleteAttemptedBefore($this->cutoff($now));
    }

    private function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $days = $this->config->getInt('webhook.delivery_retention_days', 30);

        return $now->modify("-{$days} days");
    }
}
