<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Null-object default for {@see EndpointRateLimiterInterface}: with auth-security-bundle absent there is
 * no rate-limit store, so no endpoint is ever throttled. The bundle's compiler pass re-aliases the port to
 * the real EndpointRateLimiter when registered (FEATURE-144 / ADR-044).
 */
final class NullEndpointRateLimiter implements EndpointRateLimiterInterface
{
    public function tooManyAttempts(string $action, string $key): bool
    {
        return false;
    }
}
