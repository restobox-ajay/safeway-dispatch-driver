<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Stable core port for the whole password-policy feature (strength rules, expiry, reuse-prevention) that
 * CORE (non-feature) code needs to invoke: the user password flows (registration, self-service change,
 * forced expired-password change, reset), the admin user-write service, and the admin password flows.
 *
 * Core defaults it to {@see NullPasswordPolicyManager} (no enforcement when auth-password-policy-bundle is
 * absent). The bundle's {@see \App\Bundle\AuthPasswordPolicy\Security\PasswordPolicyManager} implements it,
 * delegating to the moved PasswordPolicyService / PasswordHistoryService / PasswordExpiryChecker + the
 * password_meta satellite; the bundle compiler pass aliases this interface to it (FEATURE-145 / ADR-045).
 */
interface PasswordPolicyManagerInterface
{
    /**
     * Validate a plaintext password against the configured strength rules.
     *
     * @return list<string> violation messages; empty = valid (and empty when the bundle is absent)
     */
    public function validate(string $password): array;

    /**
     * Reject reuse of one of the user's recent passwords: returns an error string on a match, null when
     * the password is acceptable, the feature is disabled, or the bundle is absent.
     */
    public function checkReuse(User $user, string $newPlaintextPassword): ?string;

    /**
     * Record that the user's password has just changed: stamp the change time (drives expiry) and store
     * the hash in history (drives reuse-prevention). Call AFTER the user has been flushed (needs its id).
     * A no-op when the bundle is absent.
     */
    public function recordPasswordChange(User $user, string $hashedPassword): void;

    /**
     * Whether the user's password is past the configured expiry window. False when expiry is disabled,
     * the user has no recorded change date, or the bundle is absent.
     */
    public function isExpired(User $user): bool;
}
