<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Security;

use App\Bundle\AuthPasswordPolicy\Repository\PasswordMetaRepository;
use App\Bundle\AuthPasswordPolicy\Service\PasswordHistoryService;
use App\Bundle\AuthPasswordPolicy\Service\PasswordPolicyService;
use App\Entity\User;
use App\Security\PasswordPolicyManagerInterface;

/**
 * The bundle's implementation of the core {@see PasswordPolicyManagerInterface} port (FEATURE-145 / ADR-045):
 * the single façade core code uses to reach the password-policy feature. It delegates to the moved
 * strength validator, the reuse-history service, the expiry checker and the password_meta satellite so
 * that core controllers/services never reference any bundle class directly. The bundle compiler pass
 * aliases the interface to this service; when the bundle is absent core falls back to
 * {@see \App\Security\NullPasswordPolicyManager} and none of these operations do anything.
 */
final class PasswordPolicyManager implements PasswordPolicyManagerInterface
{
    public function __construct(
        private readonly PasswordPolicyService $policyService,
        private readonly PasswordHistoryService $historyService,
        private readonly PasswordExpiryChecker $expiryChecker,
        private readonly PasswordMetaRepository $metaRepo,
    ) {}

    public function validate(string $password): array
    {
        return $this->policyService->validate($password);
    }

    public function checkReuse(User $user, string $newPlaintextPassword): ?string
    {
        return $this->historyService->checkReuse($user, $newPlaintextPassword);
    }

    public function recordPasswordChange(User $user, string $hashedPassword): void
    {
        // Stamp the change time (drives expiry) — always — and store the hash in history (self-gates on
        // reuse_count>0). Mirrors the old pre-flush setPasswordChangedAt() + post-flush storeHash() pair.
        $this->metaRepo->recordChange($user);
        $this->historyService->storeHash($user, $hashedPassword);
    }

    public function isExpired(User $user): bool
    {
        return $this->expiryChecker->isExpired($user);
    }
}
