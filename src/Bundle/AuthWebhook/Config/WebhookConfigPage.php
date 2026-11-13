<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Config;

use App\Config\ConfigPageProviderInterface;

/**
 * Admin /config sub-page for the webhook feature — owned by auth-webhook-bundle (FEATURE-141). It
 * implements the core {@see ConfigPageProviderInterface} (which carries the `auth.config_page`
 * autoconfigure tag), so with the bundle's autoconfigured service loading it is collected by
 * ConfigPageRegistry ONLY when the bundle is registered; with the bundle absent the sub-page is gone
 * (closes review C12 for this bundle). Slug 'webhook' is unchanged from the pre-extraction page.
 */
class WebhookConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'webhook';
    }

    public function getTitle(): string
    {
        return 'Webhooks';
    }

    public function getFields(): array
    {
        return [
            'webhook.global_url' => [
                'label'   => 'Global Webhook URL',
                'type'    => 'text',
                'default' => '',
            ],
            'webhook.login_url' => [
                'label'   => 'Login Event URL',
                'type'    => 'text',
                'default' => '',
            ],
            'webhook.registration_url' => [
                'label'   => 'Registration Event URL',
                'type'    => 'text',
                'default' => '',
            ],
            'webhook.password_reset_url' => [
                'label'   => 'Password Reset Event URL',
                'type'    => 'text',
                'default' => '',
            ],
            'webhook.lockout_url' => [
                'label'   => 'Account Lockout Event URL',
                'type'    => 'text',
                'default' => '',
            ],
            'webhook.max_retry_attempts' => [
                'label'   => 'Max Retry Attempts',
                'type'    => 'int',
                'min'     => 0,
                'default' => '3',
            ],
            // FEATURE-147 / ADR-048: retention for the webhook_delivery log. app:prune deletes rows
            // whose attemptedAt is older than this many days (all statuses) via WebhookDeliveryPruner.
            'webhook.delivery_retention_days' => [
                'label'   => 'Webhook Delivery Log Retention (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
            // ADR-027 / FEATURE-105: SSRF guard switch. ON (default) refuses delivery to
            // private/reserved/internal targets; an operator can turn it OFF to allow internal
            // webhook endpoints. The blocklist itself is hard-coded, not admin-editable.
            'webhook.block_internal_targets' => [
                'label'   => 'Block Internal Targets (SSRF Guard)',
                'type'    => 'bool',
                'default' => '1',
            ],
        ];
    }
}
