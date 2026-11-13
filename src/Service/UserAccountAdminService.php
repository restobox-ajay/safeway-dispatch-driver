<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\IpWhitelistManagerInterface;
use App\Security\PasswordPolicyManagerInterface;
use App\Security\RecoveryTokenInvalidator;
use App\Security\UserTokenRevokerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The single site for admin-initiated user-account writes (FEATURE-103 AC1 / review C8).
 *
 * The web ({@see \App\Controller\AdminUserController}) and API
 * ({@see \App\Controller\Api\AdminApiUserController}) admin surfaces operate on the SAME user
 * model, so their create/update/deactivate/delete logic MUST be collapsed here: one validation
 * path, one atomic audit path, and one place for password-lifecycle bookkeeping. This makes the
 * folded-in review fixes (C22 role/status reject-not-coerce, C27/ADR-030 unique-violation catch,
 * C10 atomic audit) single-site and structurally hard to reintroduce as drift.
 *
 * This is a within-realm (web↔API) collapse only. There is deliberately NO shared body across the
 * user/admin realms (ADR-003, FEATURE-103 AC6): the admin realm keeps its own controllers.
 *
 * The caller supplies the audit actor identity as plain strings so the service depends on neither
 * the security token nor the Request and stays unit-testable.
 */
final class UserAccountAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        // The password-policy feature is an optional bundle (FEATURE-145): depend on the core port, not the
        // moved services. With auth-password-policy-bundle absent this is the null-object (no validation,
        // no history/changed-at recording).
        private readonly PasswordPolicyManagerInterface $passwordPolicy,
        // The per-user IP-whitelist override is an optional bundle (FEATURE-146): depend on the core port,
        // not the bundle repository. With auth-ip-whitelist-bundle absent this is the null-object (the
        // override is neither stored nor read).
        private readonly IpWhitelistManagerInterface $ipWhitelist,
        private readonly UserFieldValidator $fieldValidator,
        private readonly RecoveryTokenInvalidator $recoveryTokenInvalidator,
        private readonly AuditLogger $auditLogger,
        private readonly UserSessionRepository $sessionRepository,
        // The PAT feature is an optional bundle (FEATURE-138): depend on the core port, not the
        // bundle repository. With auth-pat-bundle absent this is the null-object (revoke is a no-op).
        private readonly UserTokenRevokerInterface $tokenRevoker,
    ) {}

    /**
     * Validate and create a user. Returns field-keyed validation errors on failure (nothing is
     * persisted) or the created user on success.
     *
     * @param array<string, mixed> $input keys: email, name, password, role, status
     */
    public function create(array $input, string $actorEmail, string $actorIp): UserWriteResult
    {
        $email    = trim((string) ($input['email'] ?? ''));
        $name     = trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role     = (string) ($input['role'] ?? 'ROLE_USER');
        $status   = (string) ($input['status'] ?? 'active');

        $errors = $this->validateEmail($email, null);

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } else {
            $policyErrors = $this->passwordPolicy->validate($password);
            if ($policyErrors !== []) {
                $errors['password'] = $policyErrors[0];
            }
        }

        // Reject an unknown role/status instead of silently coercing it (review C22).
        $errors += $this->validateRoleStatus($role, $status);

        if ($errors !== []) {
            return UserWriteResult::withErrors($errors);
        }

        // User roles are fixed to ROLE_USER (User::ALLOWED_ROLES, ADR-024); a validated ROLE_USER
        // is a no-op, so we never mutate roles here.
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setStatus($status);

        // The findByEmail pre-check above is racy; the DB user.email UNIQUE index is the real
        // guard. Catch a concurrent-duplicate violation and surface the same clean validation
        // error instead of a 500 (FEATURE-119 / review C27, ADR-030). The user INSERT and its
        // 'success' audit row commit atomically (C10): if the flush throws, both roll back.
        try {
            $this->em->wrapInTransaction(function () use ($user, $actorEmail, $actorIp): void {
                $this->em->persist($user);
                $this->auditLogger->logDeferred($actorEmail, 'admin', $actorIp, 'admin.user_create', 'success', $user->getEmail());
            });
        } catch (UniqueConstraintViolationException) {
            return UserWriteResult::withErrors(['email' => 'This email address is already registered.']);
        }

        // Record the password change (FEATURE-103 AC1): stamp the change time so expiry measures age from
        // creation rather than treating the account as never-changed, and seed password history so the new
        // password counts as a recent password for reuse checks. Requires the flushed user id, hence after
        // the transaction. Routed through the password-policy port (FEATURE-145): a no-op when the bundle
        // is absent.
        $this->passwordPolicy->recordPasswordChange($user, $user->getPassword());

        return UserWriteResult::success($user);
    }

    /**
     * Apply a partial set of field changes to a user. Only keys PRESENT in $changes are validated
     * and applied, so the web surface (which always submits every field, plus allowed_ips) and the
     * API PATCH surface (which submits only the changed fields) share one path.
     *
     * @param array<string, mixed> $changes keys: email, name, role, status, allowed_ips
     */
    public function update(User $user, array $changes, string $actorEmail, string $actorIp): UserWriteResult
    {
        $errors = [];
        // Validate first and only mutate the entity once every field is clean, so a validation
        // failure never leaves a stray (unflushed) change on the managed entity.
        $apply         = [];
        $statusChanged = false;

        if (\array_key_exists('email', $changes)) {
            $email       = trim((string) $changes['email']);
            $emailErrors = $this->validateEmail($email, $user);
            if ($emailErrors !== []) {
                $errors += $emailErrors;
            } else {
                $apply['email'] = $email;
            }
        }

        if (\array_key_exists('name', $changes)) {
            $name = trim((string) $changes['name']);
            if ($name === '') {
                $errors['name'] = 'Name is required.';
            } else {
                $apply['name'] = $name;
            }
        }

        // Roles are fixed to ROLE_USER (ADR-024): an unknown role is rejected, a valid ROLE_USER
        // never mutates anything.
        if (\array_key_exists('role', $changes)) {
            $roleError = $this->fieldValidator->validateRole((string) $changes['role']);
            if ($roleError !== null) {
                $errors['role'] = $roleError;
            }
        }

        if (\array_key_exists('status', $changes)) {
            $status      = (string) $changes['status'];
            $statusError = $this->fieldValidator->validateStatus($status);
            if ($statusError !== null) {
                $errors['status'] = $statusError;
            } else {
                $apply['status'] = $status;
                $statusChanged   = true;
            }
        }

        if (\array_key_exists('allowed_ips', $changes)) {
            $allowedIps            = trim((string) $changes['allowed_ips']);
            $apply['allowed_ips'] = $allowedIps === '' ? null : $allowedIps;
        }

        if ($errors !== []) {
            return UserWriteResult::withErrors($errors);
        }

        // What the edit changes, for the audit row (issue #46): the target alone does not tell an investigator that
        // an email was moved (the takeover step) or an account switched off. Read before anything is mutated.
        $changeLog = $this->describeChanges($user, $apply);

        $previousEmail = $user->getEmail();
        if (\array_key_exists('email', $apply)) {
            $user->setEmail($apply['email']);
        }
        if (\array_key_exists('name', $apply)) {
            $user->setName($apply['name']);
        }
        // Moving an active account to inactive is a deactivation, whatever the route (edit form, API PATCH):
        // it must cut live access exactly like deactivate() does — sessions, PATs, remember-me — or a later
        // reactivation revives every stolen credential (issue #61).
        $deactivating = \array_key_exists('status', $apply) && $apply['status'] === 'inactive' && $user->getStatus() !== 'inactive';
        if (\array_key_exists('status', $apply)) {
            $user->setStatus($apply['status']);
        }
        if ($deactivating) {
            $this->tearDownLiveAccess($user);
        }

        // The business change and its 'success' audit row commit atomically (C10): the audit entry
        // is persisted (not flushed) inside the transaction whose commit flushes the field edits.
        // As in create(), the email pre-check is racy and the user.email UNIQUE index is the real guard: a
        // concurrent edit to the same address rolls back here and gets the same clean error, not a 500 (#59).
        try {
            $this->em->wrapInTransaction(function () use ($user, $changeLog, $actorEmail, $actorIp, $deactivating): void {
                $this->auditLogger->logDeferred(
                    $actorEmail, 'admin', $actorIp, 'admin.user_edit', 'success',
                    implode('; ', [$user->getEmail(), ...$changeLog]),
                );
                if ($deactivating) {
                    $this->auditLogger->logDeferred($actorEmail, 'admin', $actorIp, 'admin.user_deactivate', 'success', $user->getEmail());
                }
            });
        } catch (UniqueConstraintViolationException) {
            return UserWriteResult::withErrors(['email' => 'This email address is already registered.']);
        }

        // The per-user IP-whitelist override lives in the auth-ip-whitelist-bundle satellite, not on `user`
        // (FEATURE-146): route the write through the port (a no-op when the bundle is absent). Done after the
        // flush above so the user is guaranteed to carry an id for the satellite FK.
        if (\array_key_exists('allowed_ips', $apply)) {
            $this->ipWhitelist->setAllowedIps($user, $apply['allowed_ips']);
        }

        // Deactivating an account kills its outstanding recovery tokens (FEATURE-102).
        if ($statusChanged && $user->getStatus() === 'inactive') {
            $this->recoveryTokenInvalidator->invalidateForUser($user->getEmail());
        }

        // Recovery tokens are resolved by email when used: an email change frees the old address, so kill the
        // reset / magic links still pending for it before another account can be given that address (issue #23).
        if ($previousEmail !== $user->getEmail()) {
            $this->recoveryTokenInvalidator->invalidateForUser($previousEmail);
        }

        return UserWriteResult::success($user);
    }

    /**
     * Soft-delete a user (ADR-020 / FEATURE-110): the row is never physically removed — there are
     * no DB foreign keys and every satellite table keys on a scalar user_id/email, so removing the
     * user would strand its history. Setting status='inactive' disables authentication while
     * keeping the row and all its history intact.
     */
    public function delete(User $user, string $actorEmail, string $actorIp): void
    {
        $deletedEmail = $user->getEmail();

        $user->setStatus('inactive');
        // Delete must never be weaker than deactivate: tear down live access NOW (drop session rows +
        // stamp sessionsInvalidatedAt + revoke PATs), not just block the next login — a soft-deleted
        // user's already-open browser session would otherwise survive until logout/expiry.
        $this->tearDownLiveAccess($user);

        // Soft-delete + teardown + its 'success' audit row commit atomically (C10).
        $this->em->wrapInTransaction(function () use ($deletedEmail, $actorEmail, $actorIp): void {
            $this->auditLogger->logDeferred($actorEmail, 'admin', $actorIp, 'admin.user_delete', 'success', $deletedEmail);
        });

        // Soft-delete disables the account; kill its outstanding recovery tokens too (FEATURE-102).
        $this->recoveryTokenInvalidator->invalidateForUser($deletedEmail);
    }

    /**
     * Deactivate a user AND tear down live access now (not just at next login): drop the user's
     * sessions, revoke their PATs, and invalidate their remember-me cookies + recovery tokens.
     */
    public function deactivate(User $user, string $actorEmail, string $actorIp): void
    {
        $user->setStatus('inactive');
        $this->tearDownLiveAccess($user);
        $this->em->flush();

        // Kill outstanding password-reset AND magic-link recovery tokens too (FEATURE-102).
        $this->recoveryTokenInvalidator->invalidateForUser($user->getEmail());

        $this->auditLogger->log($actorEmail, 'admin', $actorIp, 'admin.user_deactivate', 'success', $user->getEmail());
    }

    /**
     * The fields an edit actually changes, for its audit row: email and status as old → new; name and the IP
     * whitelist override by name only (free text).
     *
     * @param array<string, mixed> $apply the validated changes about to be applied
     *
     * @return list<string>
     */
    private function describeChanges(User $user, array $apply): array
    {
        $changes = [];
        if (\array_key_exists('email', $apply) && $apply['email'] !== $user->getEmail()) {
            $changes[] = sprintf('email: %s → %s', $user->getEmail(), $apply['email']);
        }
        if (\array_key_exists('name', $apply) && $apply['name'] !== $user->getName()) {
            $changes[] = 'name changed';
        }
        if (\array_key_exists('status', $apply) && $apply['status'] !== $user->getStatus()) {
            $changes[] = sprintf('status: %s → %s', $user->getStatus(), $apply['status']);
        }
        if (\array_key_exists('allowed_ips', $apply) && $apply['allowed_ips'] !== $this->ipWhitelist->getAllowedIps($user)) {
            $changes[] = 'allowed_ips changed';
        }

        return $changes;
    }

    /**
     * Cut a disabled account's live access NOW (not just at next login): delete its session rows,
     * revoke its PATs, and stamp sessionsInvalidatedAt so remember-me cannot re-authenticate. Shared
     * by deactivate() and delete() so delete is never weaker than deactivate (review follow-up).
     */
    private function tearDownLiveAccess(User $user): void
    {
        $this->sessionRepository->deleteAllByUserId((int) $user->getId());
        $this->tokenRevoker->revokeAllByUserId((int) $user->getId());
        $user->setSessionsInvalidatedAt(new \DateTimeImmutable());
    }

    /**
     * @return array<string, string>
     */
    private function validateEmail(string $email, ?User $current): array
    {
        if ($email === '') {
            return ['email' => 'Email is required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => 'Please enter a valid email address.'];
        }

        // On create ($current === null) any existing row is a conflict; on update, only a row that
        // belongs to a DIFFERENT user is a conflict.
        if ($current === null) {
            if ($this->userRepository->findByEmail($email) !== null) {
                return ['email' => 'This email address is already registered.'];
            }
        } elseif ($email !== $current->getEmail()) {
            $existing = $this->userRepository->findByEmail($email);
            if ($existing !== null && $existing->getId() !== $current->getId()) {
                return ['email' => 'This email address is already registered.'];
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function validateRoleStatus(string $role, string $status): array
    {
        $errors = [];
        if (($roleError = $this->fieldValidator->validateRole($role)) !== null) {
            $errors['role'] = $roleError;
        }
        if (($statusError = $this->fieldValidator->validateStatus($status)) !== null) {
            $errors['status'] = $statusError;
        }

        return $errors;
    }
}
