<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ConfigService;

/**
 * Resolves the effective 2FA enforcement level (off | optional | required) for a principal from
 * its roles, implementing per-role enforcement (spec: "off / optional / required per role",
 * review C37 / FEATURE-126).
 *
 * Each role can be configured independently via `2fa.enforcement.role.<ROLE>` with values
 * inherit | off | optional | required. `inherit` (and an absent key) mean "not configured for
 * this role". When a principal holds several configured roles the STRICTEST wins
 * (off < optional < required), so the most-privileged role forces the strongest factor.
 *
 * Backward compatibility: when NONE of the principal's roles is configured, the resolver falls
 * back to a default. The legacy single global `2fa.enforcement` key was historically the USER
 * realm's enforcement (admins had no 2FA at all before this feature), so it is applied ONLY to a
 * user-realm principal (one holding ROLE_USER). A plain user with no per-role key therefore
 * behaves exactly as before, and deployments that only ever set the global key are unaffected.
 * An admin principal with no per-role admin key falls back to `optional` — admins can enrol and,
 * once enrolled, are challenged, but an admin who never enrolled is never forced (matching the
 * pre-feature observable behaviour). Setting the legacy global key must NOT retroactively force
 * admins into 2FA.
 *
 * MANDATORY floor (ADR-050 / FEATURE-149): some roles ALWAYS require 2FA, regardless of config.
 * ROLE_TECH_SUPPORT is a maintainer tier with full superadmin powers, so a second factor is
 * non-negotiable — it is enforced HERE, in code, not via a `2fa.enforcement.role.*` config field,
 * because such a field would render the role's name on the client-visible admin config page and
 * defeat the tier's invisibility (ADR-050). Any role in MANDATORY_2FA_ROLES resolves to `required`
 * and cannot be relaxed by configuration.
 */
final class TwoFactorEnforcementResolver
{
    private const RANK = ['off' => 0, 'optional' => 1, 'required' => 2];

    private const ROLE_KEY_PREFIX = '2fa.enforcement.role.';

    private const DEFAULT_LEVEL = 'optional';

    /** Roles for which 2FA is always `required`, un-overridable by config. */
    private const MANDATORY_2FA_ROLES = ['ROLE_TECH_SUPPORT'];

    public function __construct(
        private ConfigService $configService,
    ) {}

    /**
     * @param string[] $roles
     */
    public function resolveForRoles(array $roles): string
    {
        // A mandatory-2FA role forces `required` and cannot be relaxed by any config key.
        if (array_intersect($roles, self::MANDATORY_2FA_ROLES) !== []) {
            return 'required';
        }

        $strictest = null;

        foreach ($roles as $role) {
            $level = $this->configService->getString(self::ROLE_KEY_PREFIX . $role, '');
            if (!isset(self::RANK[$level])) {
                // Empty, 'inherit', or any unexpected value ⇒ not configured for this role.
                continue;
            }
            if ($strictest === null || self::RANK[$level] > self::RANK[$strictest]) {
                $strictest = $level;
            }
        }

        if ($strictest !== null) {
            return $strictest;
        }

        // No per-role key configured. The legacy global key is user-scoped by history.
        if (\in_array('ROLE_USER', $roles, true)) {
            return $this->configService->getString('2fa.enforcement', self::DEFAULT_LEVEL);
        }

        return self::DEFAULT_LEVEL;
    }
}
