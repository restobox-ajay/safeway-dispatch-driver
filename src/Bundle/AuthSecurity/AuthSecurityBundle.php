<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity;

use App\Bundle\AuthSecurity\DependencyInjection\Compiler\RegisterAuthSecurityServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-security-bundle — login rate-limiting + account lockout packaged as an OPTIONAL Symfony bundle
 * (FEATURE-144, C36 Option A). The SECOND satellite-table extraction: it owns a NEW `account_lockouts`
 * table (user_id FK) that replaces the old `locked_until` column on `user`, owns the DBAL rate-limit
 * stores (login_attempts, endpoint_rate_limits), and moves the login rate-limit listener, the endpoint
 * rate limiter, and its admin /config sub-page into itself.
 *
 * When it is NOT registered none of its services/config sub-page exist, its migration never runs (so
 * account_lockouts never exists and locked_until is never dropped) and core falls back to the null-object
 * bindings (App\Security\NullAccountLockManager + App\Security\NullEndpointRateLimiter) — so no login is
 * throttled and no account is ever locked. See ADR-044.
 */
final class AuthSecurityBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object aliases (AccountLockManagerInterface + EndpointRateLimiterInterface)
        // to the real bundle services. A compiler pass — not the extension — because the app's
        // services.yaml is loaded AFTER bundle extensions and would otherwise win.
        $container->addCompilerPass(new RegisterAuthSecurityServicesPass());
    }
}
