<?php

declare(strict_types=1);

namespace App\AdminPassword;

use App\Entity\Admin;
use App\Entity\AdminPasswordResetToken;
use App\Repository\AdminPasswordResetTokenRepository;
use App\Repository\AdminRepository;
use App\Repository\AdminSessionRepository;
use App\Repository\DbConsoleSessionRepository;
use App\Security\PasswordPolicyManagerInterface;
use App\Security\RecoveryTokenInvalidator;
use App\Security\TechSupportVisibility;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one place an EXISTING admin's password changes (ADR-073). Whichever way it is asked for — the emailed
 * reset link, "change my password", or tech support setting another admin's password — change() does, in
 * order: the permission check, validation, the write (hash, token consumption), the audit row, and the
 * revocations (pending reset links, live admin sessions, open DB consoles). Controllers only collect input
 * and map the result to a response; AdminPasswordGateArchitectureTest keeps it that way.
 *
 * Account CREATION (the create-* commands, the admin "new" form) is not a change and stays where it is.
 * Sending a reset link changes nothing, so it also stays out (AdminPasswordResetService).
 */
final class AdminPasswordGate
{
    public const AUDIT_RESET = 'admin.password_reset';
    public const AUDIT_OWN = 'admin.password_change';
    public const AUDIT_SUPPORT = 'admin.password_set';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminRepository $admins,
        private readonly AdminPasswordResetTokenRepository $resetTokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicyManagerInterface $policy,
        private readonly TechSupportVisibility $visibility,
        private readonly AuditLogger $audit,
        private readonly RecoveryTokenInvalidator $recoveryTokens,
        private readonly AdminSessionRepository $sessions,
        private readonly DbConsoleSessionRepository $consoles,
    ) {
    }

    /**
     * Read-only: is this reset link usable right now? Returns the message to show, or null when it is.
     * Lets the reset form refuse a dead link on GET using the very same rules change() applies on POST.
     */
    public function resetLinkProblem(string $token): ?string
    {
        return $this->resolveResetLink($token)[2];
    }

    public function change(AdminPasswordChange $change): AdminPasswordChangeResult
    {
        $resetToken = null;

        switch ($change->source) {
            case PasswordChangeSource::ResetLink:
                [$resetToken, $target, $problem] = $this->resolveResetLink((string) $change->resetToken);
                if ($problem !== null || $target === null) {
                    return AdminPasswordChangeResult::invalid($problem ?? 'This password reset link is invalid or has already been used.');
                }
                $actorEmail = $target->getEmail();
                $action = self::AUDIT_RESET;
                $context = null;
                break;

            case PasswordChangeSource::Own:
                $target = $change->actor;
                if ($target === null) {
                    return AdminPasswordChangeResult::forbidden();
                }
                $actorEmail = $target->getEmail();
                $action = self::AUDIT_OWN;
                $context = 'self-service';
                if (!$this->hasher->isPasswordValid($target, (string) $change->currentPassword)) {
                    $this->audit->log($actorEmail, 'admin', $change->ip, $action, 'failure', 'wrong current password');

                    return AdminPasswordChangeResult::rejected('Your current password is incorrect.');
                }
                break;

            case PasswordChangeSource::Support:
                $actor = $change->actor;
                $target = $change->target;
                $action = self::AUDIT_SUPPORT;
                if ($actor === null || !$this->visibility->isTechSupport($actor)) {
                    $this->audit->log($actor?->getEmail() ?? 'unknown', 'admin', $change->ip, $action, 'failure', 'not permitted' . ($target ? ': ' . $target->getEmail() : ''));

                    return AdminPasswordChangeResult::forbidden();
                }
                if ($target === null) {
                    return AdminPasswordChangeResult::invalid('Admin not found.');
                }
                if ($target->getId() === $actor->getId()) {
                    return AdminPasswordChangeResult::rejected('Use "Change My Password" for your own account.');
                }
                if (!$target->isActive()) {
                    return AdminPasswordChangeResult::invalid('This account is inactive. Reactivate it before setting its password.');
                }
                $actorEmail = $actor->getEmail();
                $context = $target->getEmail();
                break;
        }

        $new = $change->newPassword;
        if ($new === '') {
            return AdminPasswordChangeResult::rejected('Password is required.');
        }
        if ($change->confirmation !== null && $change->confirmation !== $new) {
            return AdminPasswordChangeResult::rejected('The new passwords do not match.');
        }
        if (($violations = $this->policy->validate($new)) !== []) {
            return AdminPasswordChangeResult::rejected(implode(' ', $violations));
        }

        // The write, the audit row and every revocation commit together — all or nothing (C10).
        $this->em->wrapInTransaction(function () use ($target, $new, $resetToken, $change, $actorEmail, $action, $context): void {
            $target->setPassword($this->hasher->hashPassword($target, $new));
            $resetToken?->markUsed();

            // A changed password retires every other pending reset link, closes every session the admin
            // has (Admin::isEqualTo already refuses a live one on its next request; this removes the rows
            // too, so none lingers as a ghost "active session"), and closes any open DB console (issue #48).
            $this->recoveryTokens->invalidateForAdmin($target->getEmail());
            $this->sessions->deleteAllByAdminId((int) $target->getId());
            $this->consoles->deleteAllByAdminId((int) $target->getId());

            $this->audit->logDeferred($actorEmail, 'admin', $change->ip, $action, 'success', $context);
        });

        return AdminPasswordChangeResult::changed($target);
    }

    /** @return array{0:?AdminPasswordResetToken,1:?Admin,2:?string} token, account, and the problem (null = usable) */
    private function resolveResetLink(string $token): array
    {
        $resetToken = $this->resetTokens->findOneBy(['tokenHash' => hash('sha256', $token)]);

        if ($resetToken === null || !$resetToken->isValid()) {
            return [null, null, $resetToken?->isExpired()
                ? 'This password reset link has expired. Please request a new one.'
                : 'This password reset link is invalid or has already been used.'];
        }

        // A link issued before deactivation cannot reset an inactive admin (FEATURE-102 / ADR-020).
        $account = $this->admins->findByEmail($resetToken->getEmail());
        if ($account === null || !$account->isActive()) {
            return [null, null, 'This password reset link is no longer valid.'];
        }

        return [$resetToken, $account, null];
    }
}
