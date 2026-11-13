<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Security;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Entity\User;
use App\Security\TwoFactorChallengeGuardInterface;
use App\Security\TwoFactorEnforcementResolver;
use App\Service\ConfigService;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Answers "does this session still owe a TOTP challenge for the given user".
 *
 * Moved into auth-2fa-bundle (FEATURE-143 / ADR-043) and now reads the user's enrolment from the
 * two_factor_settings satellite rather than from User columns. Implements the core port
 * {@see TwoFactorChallengeGuardInterface} so core's AccountController (which stays in core) can depend
 * on the stable interface; the bundle compiler pass aliases the interface to this class. Shared by
 * TwoFactorChallengeListener (redirects such sessions to the challenge) and AccountController (must not
 * let a pre-2FA session act through routes the listener deliberately skips), so the two agree on what
 * "pre-2FA" means. TwoFactorEnforcementResolver stays in core (shared with the admin realm).
 */
final class TwoFactorGuard implements TwoFactorChallengeGuardInterface
{
    public function __construct(
        private ConfigService $configService,
        private TrustedDeviceManager $trustedDeviceManager,
        private TwoFactorEnforcementResolver $enforcementResolver,
        private TwoFactorSettingsRepository $settings,
    ) {}

    public function isChallengePending(Request $request, User $user): bool
    {
        // 2FA disabled for this principal's roles — nothing is ever pending (FEATURE-126: the
        // level is now resolved per role, falling back to the legacy global key).
        if ($this->enforcementResolver->resolveForRoles($user->getRoles()) === 'off') {
            return false;
        }

        $secret = $this->settings->getSecret($user);

        // No enrolled TOTP secret — there is no challenge to complete.
        if ($secret === null) {
            return false;
        }

        if (!$request->hasSession()) {
            return false;
        }

        // Trusted IP bypass.
        $trustedIpsConfig = $this->configService->getString('2fa.trusted_ips', '');
        if ($trustedIpsConfig !== '') {
            $trustedIps = array_values(array_filter(array_map('trim', explode(',', $trustedIpsConfig))));
            $clientIp = $request->getClientIp() ?? '0.0.0.0';
            if ($trustedIps !== [] && IpUtils::checkIp($clientIp, $trustedIps)) {
                return false;
            }
        }

        // Trusted device bypass.
        if ($this->trustedDeviceManager->isDeviceTrusted($request, $user, $secret)) {
            return false;
        }

        // Challenge already passed this session BY THIS USER. The marker holds the id of the user who passed
        // it, so a session that later signs in as someone else (no logout) is challenged again.
        return $user->getId() === null || $request->getSession()->get('_2fa_verified') !== $user->getId();
    }
}
