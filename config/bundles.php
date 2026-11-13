<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-138). Toggle per deploy: remove this line and
    // the Personal Access Token feature (its /api + /account/tokens routes, entity, migration and
    // services) disappears cleanly — core falls back to App\Security\NullUserTokenRevoker. See ADR-010.
    App\Bundle\AuthPat\AuthPatBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-140). Toggle per deploy: remove this line and
    // the passwordless magic-link feature (its /magic-link* routes, entity, migration, admin /config
    // sub-page and services) disappears cleanly — core falls back to the null-object bindings
    // (App\Security\NullMagicLinkAuthenticator + App\Service\NullMagicLinkTokenMaintainer). See ADR-040.
    App\Bundle\AuthMagicLink\AuthMagicLinkBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-142). Toggle per deploy: remove this line and
    // the impersonation feature (its /impersonate/* routes, user-firewall authenticator, admin /config
    // sub-page and services) disappears cleanly — core falls back to the null-object binding
    // (App\Security\NullImpersonationAuthenticator) and the admin impersonate buttons hide (the
    // `impersonation_available` Twig global is then undefined). No new table. See ADR-041.
    App\Bundle\AuthImpersonation\AuthImpersonationBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-141). Toggle per deploy: remove this line and the
    // outbound auth-event webhook feature (its WebhookDelivery entity + migration, the async Messenger
    // delivery path, the SSRF guard, the login WebhookListener, and its admin /config sub-page) disappears
    // cleanly — no webhooks fire and core falls back to the null-object binding
    // (App\Service\NullWebhookDispatcher). Own table webhook_delivery. See ADR-042.
    App\Bundle\AuthWebhook\AuthWebhookBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-143). FIRST satellite-table extraction: toggle per
    // deploy — remove this line and the USER 2FA feature (its /account/2fa* + /2fa/challenge routes, the
    // TwoFactorSettings entity + two_factor_settings migration, the challenge listener, guard,
    // trusted-device manager and its admin /config sub-page) disappears cleanly. Core falls back to the
    // null-object bindings (App\Security\NullTwoFactorGuard + App\Security\NullUserTwoFactorManager) and
    // the user-2FA links hide (the `two_factor_available` Twig global is then undefined). See ADR-043.
    App\Bundle\Auth2fa\Auth2faBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-144). SECOND satellite-table extraction: toggle per
    // deploy — remove this line and login rate-limiting + account lockout (the LoginRateLimitListener,
    // the EndpointRateLimiter, the AccountLockout entity + account_lockouts migration, and its admin
    // /config sub-page) disappears cleanly. Core falls back to the null-object bindings
    // (App\Security\NullAccountLockManager + App\Security\NullEndpointRateLimiter) so no login is throttled
    // and no account is locked. Owns account_lockouts + the DBAL stores login_attempts/endpoint_rate_limits.
    // See ADR-044.
    App\Bundle\AuthSecurity\AuthSecurityBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-145). THIRD satellite-table extraction: toggle per
    // deploy — remove this line and the password-policy feature (strength rules, expiry + its listener,
    // reuse-prevention, the PasswordMeta entity + password_meta migration, the PasswordHistory mapping, and
    // its admin /config sub-page) disappears cleanly. Core falls back to the null-object binding
    // (App\Security\NullPasswordPolicyManager) so no password is validated, expired, or reuse-checked.
    // Owns password_meta + password_history. See ADR-045.
    App\Bundle\AuthPasswordPolicy\AuthPasswordPolicyBundle::class => ['all' => true],
    // Optional feature bundle (C36 Option A, FEATURE-146). FOURTH / FINAL satellite-table extraction: toggle
    // per deploy — remove this line and the login IP-whitelist feature (the IpWhitelistListener, the
    // UserIpWhitelist entity + user_ip_whitelist migration, and its admin /config sub-page) disappears
    // cleanly, leaving NO IP restriction on login. Core falls back to the null-object binding
    // (App\Security\NullIpWhitelistManager) so no per-user override is ever stored or read. The global
    // whitelist lives in the core `config` store but is only enforced by (and edited through) this bundle.
    // Owns user_ip_whitelist. See ADR-046.
    App\Bundle\AuthIpWhitelist\AuthIpWhitelistBundle::class => ['all' => true],
];
