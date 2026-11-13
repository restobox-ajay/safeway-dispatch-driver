<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminPasswordResetToken;
use App\Entity\PasswordResetToken;
use App\Service\MagicLinkTokenMaintainerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Immediately kills all outstanding account-recovery artefacts (password-reset and magic-link
 * tokens) for an email when the owning account is deactivated or soft-deleted (FEATURE-102 /
 * review C7 / ADR-020).
 *
 * A disabled account cannot log in (UserChecker / AdminChecker), but a reset/magic link issued
 * before deactivation would otherwise still be consumable. This service marks every still-unused
 * token as used so the link is dead the instant the account is disabled — independent of whether
 * the "delete" was soft (status=inactive) or, hypothetically, a hard row removal (the kill is
 * driven by the deactivate/delete action, not by the row going away).
 *
 * User and admin recovery tokens live in separate tables (a user and admin sharing an email must
 * never cross-consume tokens), so the two realms have dedicated methods.
 */
final class RecoveryTokenInvalidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        // The magic-link half is owned by the optional auth-magic-link-bundle (FEATURE-140). Core
        // reaches it through this port so a deactivate still works with the bundle absent (the port
        // then resolves to App\Service\NullMagicLinkTokenMaintainer — a no-op, since there is no
        // magic_link_tokens table to touch).
        private readonly MagicLinkTokenMaintainerInterface $magicLinkTokens,
    ) {
    }

    /**
     * Invalidate every still-unused password-reset AND magic-link token for a user's email.
     */
    public function invalidateForUser(string $email): void
    {
        $this->markAllUnusedTokens(PasswordResetToken::class, $email);
        $this->magicLinkTokens->invalidateUnusedForEmail($email);
    }

    /**
     * Invalidate every still-unused admin password-reset token for an admin's email.
     */
    public function invalidateForAdmin(string $email): void
    {
        $this->markAllUnusedTokens(AdminPasswordResetToken::class, $email);
    }

    /**
     * @param class-string $tokenClass
     */
    private function markAllUnusedTokens(string $tokenClass, string $email): void
    {
        // Bulk DQL UPDATE mirrors PasswordResetTokenRepository::invalidateOtherUnusedTokens.
        // Exact-email match (consistent with that method); idempotent when nothing is unused.
        $this->em->createQuery(
            sprintf(
                'UPDATE %s t SET t.usedAt = :now WHERE t.email = :email AND t.usedAt IS NULL',
                $tokenClass
            )
        )
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('email', $email)
            ->execute();
    }
}
