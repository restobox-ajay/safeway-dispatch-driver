<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Admin;
use App\Entity\AdminAccessToken;
use App\Repository\AdminAccessTokenRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place admin API tokens are issued and revoked (issue #39 / ADR-064), so the console commands, the
 * admin's own "API Tokens" page, tech support's per-admin token page and the deactivate/delete teardown all
 * hash, hint, expire, revoke and audit identically.
 *
 * Before this, tokens could only be created (no expiry) and nothing ever revoked one: a leaked token stayed
 * valid until its admin was deactivated — and came back to life if the admin was reactivated.
 */
final class AdminApiTokenManager
{
    /** Default lifetime for a new token. A deliberate `--no-expiry` is still possible from the shell. */
    public const DEFAULT_LIFETIME_DAYS = 365;

    public const ACTION_CREATE = 'admin.api_token_create';
    public const ACTION_REVOKE = 'admin.api_token_revoke';
    public const ACTION_REVOKE_ALL = 'admin.api_token_revoke_all';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminAccessTokenRepository $tokens,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Issue a token. The plaintext is returned ONCE and never stored: only its SHA-256 hash and its last
     * {@see AdminAccessToken::HINT_LENGTH} characters (so it can be told apart later) are kept.
     *
     * @return array{token: AdminAccessToken, plaintext: string}
     */
    public function issue(Admin $owner, string $name, ?\DateTimeImmutable $expiresAt, string $actor, string $actorIp): array
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new AdminAccessToken(
            (int) $owner->getId(),
            $name,
            hash('sha256', $plaintext),
            $expiresAt,
            substr($plaintext, -AdminAccessToken::HINT_LENGTH),
        );

        $this->em->persist($token);
        $this->em->flush();

        $this->auditLogger->log($actor, 'admin', $actorIp, self::ACTION_CREATE, 'success', $this->describe($token, $owner->getEmail()));

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /** @return bool false when the token was already revoked (nothing changed, nothing audited) */
    public function revoke(AdminAccessToken $token, string $ownerEmail, string $actor, string $actorIp): bool
    {
        if ($token->isRevoked()) {
            return false;
        }

        $token->revoke();
        $this->em->flush();

        $this->auditLogger->log($actor, 'admin', $actorIp, self::ACTION_REVOKE, 'success', $this->describe($token, $ownerEmail));

        return true;
    }

    /**
     * Revoke every still-unrevoked token of one admin — the "this account must lose API access" action, used
     * by tech support's revoke-all, the CLI's --all, and automatically when an admin is deactivated or
     * soft-deleted (so reactivating the account can never bring a leaked token back).
     *
     * @return int how many tokens were revoked
     */
    public function revokeAllFor(Admin $owner, string $actor, string $actorIp, string $reason): int
    {
        $count = 0;
        foreach ($this->tokens->findAllByAdminId((int) $owner->getId()) as $token) {
            if (!$token->isRevoked()) {
                $token->revoke();
                ++$count;
            }
        }

        if ($count > 0) {
            $this->em->flush();
            $this->auditLogger->log($actor, 'admin', $actorIp, self::ACTION_REVOKE_ALL, 'success', sprintf('owner=%s count=%d reason=%s', $owner->getEmail(), $count, $reason));
        }

        return $count;
    }

    private function describe(AdminAccessToken $token, string $ownerEmail): string
    {
        return sprintf(
            'owner=%s id=%d name=%s hint=%s expires=%s',
            $ownerEmail,
            (int) $token->getId(),
            $token->getName(),
            $token->getTokenHint() !== null ? '…' . $token->getTokenHint() : '(none)',
            $token->getExpiresAt()?->format('Y-m-d') ?? 'never',
        );
    }
}
