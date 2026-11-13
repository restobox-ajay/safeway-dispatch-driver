<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Stable core port for the reusable non-login endpoint rate limiter (forgot-password, magic-link,
 * resend-verification, 2FA challenge). The real DBAL-backed sliding-window implementation now lives in
 * auth-security-bundle ({@see \App\Bundle\AuthSecurity\Security\EndpointRateLimiter}); core controllers and
 * other feature bundles depend only on this interface so they never couple to the security bundle
 * (FEATURE-144 / ADR-044).
 *
 * Core defaults it to {@see NullEndpointRateLimiter} (never throttles when auth-security-bundle is absent).
 * The bundle's compiler pass re-aliases this interface to the real limiter when registered.
 */
interface EndpointRateLimiterInterface
{
    /**
     * Record an attempt for ($action, $key) and report whether the caller has reached the configured
     * limit within the window. When the limit is reached the attempt is NOT recorded.
     *
     * @param string $action Logical endpoint name, e.g. 'forgot_password'
     * @param string $key    Per-actor key, e.g. client IP or user id
     */
    public function tooManyAttempts(string $action, string $key): bool;
}
