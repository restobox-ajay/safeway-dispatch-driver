<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Core default binding of WebhookDispatcherInterface: a no-op. With auth-webhook-bundle absent the
 * three core auth flows that inject the port (RegistrationController, PasswordResetController,
 * Security\LoginRateLimitListener) still compile and run, they just never emit a webhook — the
 * "no webhooks fire when uninstalled" guarantee (FEATURE-141 AC3). When the bundle IS registered its
 * compiler pass (RegisterAuthWebhookServicesPass) upgrades this alias to the real
 * MessengerWebhookDispatcher. Mirrors the NullMagicLinkAuthenticator / NullUserTokenRevoker pattern
 * (ADR-010/ADR-040). The test/acceptance envs bind the port to InMemoryWebhookDispatcher instead so
 * dispatched calls can be inspected; the compiler pass leaves that override untouched.
 */
final class NullWebhookDispatcher implements WebhookDispatcherInterface
{
    public function dispatch(string $url, array $payload): void
    {
        // Intentionally empty — no webhook feature installed.
    }
}
