<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Security;

use App\Security\EndpointRateLimiterInterface;
use App\Service\ConfigService;
use Doctrine\DBAL\Connection;

/**
 * Reusable DBAL-backed sliding-window rate limiter for non-login authentication endpoints
 * (forgot-password, magic-link, resend-verification, 2FA challenge). Moved into auth-security-bundle
 * (FEATURE-144 / ADR-044); it implements the core {@see EndpointRateLimiterInterface} port so core
 * controllers and other feature bundles depend only on the interface. With this bundle absent, core binds
 * the port to {@see \App\Security\NullEndpointRateLimiter} and nothing is throttled.
 *
 * Login form throttling is handled separately by {@see LoginRateLimitListener}, which hooks the security
 * CheckPassportEvent. Both share the same admin config keys (rate_limit.max_attempts /
 * rate_limit.window_seconds) so a single knob governs all auth throttling.
 *
 * Fail-closed: when the admin has never configured a value, the safe non-zero DEFAULT_MAX_ATTEMPTS
 * applies. An explicit configured value of 0 disables throttling (ConfigService returns the default only
 * when the key is absent).
 */
final class EndpointRateLimiter implements EndpointRateLimiterInterface
{
    public const DEFAULT_MAX_ATTEMPTS = 10;
    public const DEFAULT_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigService $configService,
    ) {}

    public function tooManyAttempts(string $action, string $key): bool
    {
        $maxAttempts = $this->configService->getInt('rate_limit.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        if ($maxAttempts <= 0) {
            return false;
        }

        $windowSeconds = $this->configService->getInt('rate_limit.window_seconds', self::DEFAULT_WINDOW_SECONDS);
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        // Keep the table bounded: drop anything older than a day.
        $this->connection->executeStatement(
            'DELETE FROM endpoint_rate_limits WHERE hit_at < ?',
            [$now->modify('-1 day')->format('Y-m-d H:i:s')]
        );

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM endpoint_rate_limits WHERE action = ? AND rate_key = ? AND hit_at > ?',
            [$action, $key, $cutoff]
        );

        if ($count >= $maxAttempts) {
            return true;
        }

        $this->connection->executeStatement(
            'INSERT INTO endpoint_rate_limits (action, rate_key, hit_at) VALUES (?, ?, ?)',
            [$action, $key, $now->format('Y-m-d H:i:s')]
        );

        return false;
    }
}
