<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Thrown by {@see LoginRateLimitListener} when a login attempt exceeds the configured rate limit. Moved
 * into auth-security-bundle with the listener (FEATURE-144 / ADR-044); it is an internal signal of that
 * listener and no core code references it.
 */
final class TooManyLoginAttemptsException extends CustomUserMessageAuthenticationException
{
    public function __construct()
    {
        parent::__construct('Too many login attempts. Please try again later.');
    }
}
