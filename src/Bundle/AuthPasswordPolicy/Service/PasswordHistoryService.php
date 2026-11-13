<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Service;

use App\Bundle\AuthPasswordPolicy\Entity\PasswordHistory;
use App\Bundle\AuthPasswordPolicy\Repository\PasswordHistoryRepository;
use App\Entity\User;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Password reuse-prevention. Moved into auth-password-policy-bundle (FEATURE-145 / ADR-045); the logic is
 * unchanged. Core reaches it only through the {@see \App\Security\PasswordPolicyManagerInterface} port.
 */
class PasswordHistoryService
{
    public function __construct(
        private readonly PasswordHistoryRepository $historyRepo,
        private readonly EntityManagerInterface $em,
        private readonly ConfigService $configService,
    ) {}

    /**
     * Returns an error string if the new password matches a recent stored hash, null if OK.
     * Returns null when the feature is disabled (reuse_count=0) or the user has no ID yet.
     */
    public function checkReuse(User $user, string $newPlaintextPassword): ?string
    {
        $count = $this->configService->getInt('password_policy.reuse_count', 0);
        if ($count <= 0 || $user->getId() === null) {
            return null;
        }

        $history = $this->historyRepo->findRecentByUserId((int) $user->getId(), $count);
        foreach ($history as $entry) {
            if (password_verify($newPlaintextPassword, $entry->getPasswordHash())) {
                return "You cannot reuse one of your last {$count} passwords.";
            }
        }

        return null;
    }

    /**
     * Stores the hashed password in history and prunes old entries.
     * Call after the new password has been hashed, set on the user, and the user flushed.
     * No-op when the feature is disabled (reuse_count=0) or the user has no ID.
     */
    public function storeHash(User $user, string $hashedPassword): void
    {
        $count = $this->configService->getInt('password_policy.reuse_count', 0);
        if ($count <= 0 || $user->getId() === null) {
            return;
        }

        $entry = new PasswordHistory((int) $user->getId(), $hashedPassword);
        $this->em->persist($entry);
        $this->em->flush();

        $this->historyRepo->pruneOldEntries((int) $user->getId(), $count);
    }
}
