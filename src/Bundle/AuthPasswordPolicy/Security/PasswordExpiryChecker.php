<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Security;

use App\Bundle\AuthPasswordPolicy\Repository\PasswordMetaRepository;
use App\Entity\User;
use App\Service\ConfigService;

/**
 * Single source of truth for "is this user's password past the configured expiry window".
 *
 * Moved into auth-password-policy-bundle (FEATURE-145 / ADR-045). It now reads the change date from the
 * bundle-owned `password_meta` satellite ({@see PasswordMetaRepository}) rather than a column on `user`.
 * Shared by {@see \App\Bundle\AuthPasswordPolicy\EventListener\PasswordExpiryListener} (which redirects an
 * expired user to the core forced-change page) and, via the PasswordPolicyManager port, by the core
 * AccountController (which refuses to serve that page to a non-expired user), so the two can never drift.
 */
final class PasswordExpiryChecker
{
    public function __construct(
        private ConfigService $configService,
        private PasswordMetaRepository $metaRepo,
    ) {}

    public function isExpired(User $user): bool
    {
        $expiryDays = $this->configService->getInt('password_policy.expiry_days', 0);
        if ($expiryDays <= 0) {
            return false;
        }

        $passwordChangedAt = $this->metaRepo->getPasswordChangedAt($user);
        if ($passwordChangedAt === null) {
            // No recorded password change date — treat as not expired.
            return false;
        }

        return $passwordChangedAt->modify("+{$expiryDays} days") <= new \DateTimeImmutable();
    }
}
