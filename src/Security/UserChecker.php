<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\ConfigService;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly AccountLockManagerInterface $lockManager,
    ) {}

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getStatus() !== 'active') {
            throw new DisabledException();
        }

        // Lockout state lives in the auth-security-bundle satellite, read via the port. With that bundle
        // absent the null-object reports "never locked", so this branch is skipped (FEATURE-144 / ADR-044).
        $lockedUntil = $this->lockManager->lockedUntil($user);
        if ($lockedUntil !== null && $lockedUntil > new \DateTimeImmutable()) {
            $now = new \DateTimeImmutable();
            $diff = $now->diff($lockedUntil);
            $minutes = $diff->i + ($diff->h * 60) + ($diff->d * 24 * 60);
            $minutesRemaining = max(1, $minutes);
            throw new CustomUserMessageAuthenticationException(
                "Your account is temporarily locked. Please try again in {$minutesRemaining} minute(s)."
            );
        }

        if (!$user->isVerified()) {
            $mode = $this->configService->getString('email_verification.mode', 'disabled');
            if ($mode === 'required') {
                throw new CustomUserMessageAuthenticationException(
                    'Your email address has not been verified. Please check your inbox or request a new verification email.'
                );
            }
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getStatus() !== 'active') {
            throw new DisabledException();
        }

        if (!$user->isVerified()) {
            $mode = $this->configService->getString('email_verification.mode', 'disabled');
            if ($mode === 'required') {
                throw new CustomUserMessageAuthenticationException(
                    'Your email address has not been verified. Please check your inbox or request a new verification email.'
                );
            }
        }
    }
}
