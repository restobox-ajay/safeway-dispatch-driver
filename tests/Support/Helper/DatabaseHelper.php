<?php

declare(strict_types=1);

namespace App\Tests\Support\Helper;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Entity\Admin;
use App\Entity\Config;
use App\Entity\Invitation;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Kernel;
use App\Service\TotpService;
use Codeception\Module;
use Codeception\TestInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelper;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Codeception module that seeds and resets the SQLite database shared with the
 * live acceptance server. It boots an in-process Symfony kernel (test env, same
 * var/test.db file the server reads) to persist entities through Doctrine.
 *
 * Public methods are exposed on the AcceptanceTester as $I->createUser(...) etc.
 */
class DatabaseHelper extends Module
{
    private const TABLES_TO_KEEP = ['doctrine_migration_versions', 'sqlite_sequence'];

    /** Host/port the live acceptance server listens on (see codeception.yml). */
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 8899;

    private ?Kernel $kernel = null;
    private ?EntityManagerInterface $em = null;
    private ?ContainerInterface $container = null;

    public function _before(TestInterface $test): void
    {
        $this->resetDatabase();
    }

    public function _afterSuite(): void
    {
        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            $this->kernel = null;
            $this->em = null;
            $this->container = null;
        }
    }

    /**
     * Remove every row from application tables, leaving the migrated schema in
     * place. Run before each test so scenarios are isolated.
     *
     * Tables are enumerated straight from sqlite_master rather than via the DBAL
     * schema manager: the connection carries a global schema_asset_filter
     * (config/packages/doctrine.yaml) that hides login_attempts / endpoint_rate_limits /
     * sessions / messenger_messages from listTableNames(). Reset MUST clear those too —
     * otherwise per-IP failed-login rows accumulate across suite runs and eventually trip
     * the login rate limiter, so a later scenario is rejected with "Too many login attempts"
     * (the FEATURE-129 cross-run flake). doctrine_migration_versions is preserved so the
     * migrated schema keeps its bookkeeping.
     */
    public function resetDatabase(): void
    {
        $em = $this->entityManager();
        $em->clear();

        $connection = $em->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');

        $tables = $connection->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );
        foreach ($tables as $table) {
            if (\in_array($table, self::TABLES_TO_KEEP, true)) {
                continue;
            }
            $connection->executeStatement(sprintf('DELETE FROM "%s"', $table));
        }

        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $em->clear();
        $this->releaseConnection();
    }

    /**
     * @param array{name?: string, roles?: array<int, string>, status?: string, verified?: bool} $overrides
     */
    public function createUser(string $email, string $password = 'password123', array $overrides = []): int
    {
        $em = $this->entityManager();

        $user = new User();
        $user->setEmail($email);
        $user->setName($overrides['name'] ?? 'Test User');
        $user->setPassword($this->hash($password));
        $user->setRoles($overrides['roles'] ?? []);
        $user->setStatus($overrides['status'] ?? 'active');
        $user->setIsVerified($overrides['verified'] ?? true);

        $em->persist($user);
        $em->flush();

        $id = $user->getId();
        $em->clear();
        $this->releaseConnection();

        return (int) $id;
    }

    /**
     * @param array{name?: string, roles?: array<int, string>} $overrides
     */
    public function createAdmin(string $email, string $password = 'password123', array $overrides = []): int
    {
        $em = $this->entityManager();

        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName($overrides['name'] ?? 'Test Admin');
        $admin->setPassword($this->hash($password));
        $admin->setRoles($overrides['roles'] ?? ['ROLE_ADMIN']);

        $em->persist($admin);
        $em->flush();

        $id = $admin->getId();
        $em->clear();
        $this->releaseConnection();

        return (int) $id;
    }

    /**
     * Persist an active ROLE_TECH_SUPPORT admin with TOTP already enabled.
     *
     * That role carries a mandatory-2FA floor (ADR-050), so an unenrolled one is bounced to
     * /admin/2fa/setup and can never reach the database console — enrolling here lets a scenario
     * drive the console itself rather than the enrolment flow.
     */
    public function createTechSupportAdminWith2fa(string $email, string $password, string $secret): int
    {
        $em = $this->entityManager();

        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Tech Support');
        $admin->setPassword($this->hash($password));
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setTotpSecret($secret);
        $admin->setIsTotpEnabled(true);

        $em->persist($admin);
        $em->flush();

        $id = $admin->getId();
        $em->clear();
        $this->releaseConnection();

        return (int) $id;
    }

    /**
     * Persist a verified, active user with TOTP 2FA already enabled and the given
     * base32 secret, so acceptance scenarios can drive the login challenge without
     * stepping through the setup flow first. Mirrors createUserWith2fa() in the
     * functional 2FA tests.
     */
    public function createUserWith2fa(string $email, string $password, string $secret): int
    {
        $em = $this->entityManager();

        $user = new User();
        $user->setEmail($email);
        $user->setName('Test User');
        $user->setPassword($this->hash($password));
        $user->setRoles([]);
        $user->setStatus('active');
        $user->setIsVerified(true);

        $em->persist($user);
        $em->flush();

        // The TOTP enrolment lives in the auth-2fa-bundle satellite (two_factor_settings), not on the
        // user row anymore (FEATURE-143 / ADR-043) — seed it through the bundle repository.
        $this->container()->get(TwoFactorSettingsRepository::class)->enable($user, $secret);

        $id = $user->getId();
        $em->clear();
        $this->releaseConnection();

        return (int) $id;
    }

    /**
     * Generate a fresh base32 TOTP secret via the same TotpService the server uses,
     * so seeded secrets are valid input for the production verifier.
     */
    public function generateTotpSecret(): string
    {
        return $this->container()->get(TotpService::class)->generateSecret();
    }

    /**
     * Produce the current 6-digit TOTP code for a secret using the container's
     * TotpService — the identical algorithm the server runs in verifyCode(), so the
     * code an acceptance scenario submits over HTTP matches what the server expects.
     */
    public function generateTotpCode(string $secret): string
    {
        return $this->container()->get(TotpService::class)->generateCode($secret);
    }

    /**
     * Assert the user's is_totp_enabled flag in the database, proving setup actually
     * enabled 2FA and an admin reset actually cleared it (not just flashed a message).
     *
     * Reads the auth-2fa-bundle satellite (two_factor_settings) now that the flag no longer lives on
     * the user row (FEATURE-143 / ADR-043). A user with no satellite row (never enrolled) reads as
     * disabled (fetchOne returns false -> 0).
     */
    public function seeUserTotpEnabled(int $userId, bool $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $flag = $connection->fetchOne('SELECT is_totp_enabled FROM two_factor_settings WHERE user_id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertSame(
            $expected ? 1 : 0,
            (int) $flag,
            sprintf('Expected user %d is_totp_enabled to be %s.', $userId, $expected ? 'true' : 'false')
        );
    }

    /**
     * Lock a user until now + $minutes by seeding the auth-security-bundle satellite account_lockouts via
     * DBAL, mirroring what the production lockout writes (FEATURE-144 / ADR-044). Lets an acceptance
     * scenario put an account into the locked state without driving the full failed-attempt sequence, so
     * the admin "locked indicator" and "unlock" flows can be exercised directly.
     */
    public function lockUserAccount(int $userId, int $minutes = 60): void
    {
        $connection = $this->entityManager()->getConnection();
        $lockedUntil = (new \DateTimeImmutable())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO account_lockouts (user_id, locked_until) VALUES (?, ?) '
            . 'ON CONFLICT(user_id) DO UPDATE SET locked_until = excluded.locked_until',
            [$userId, $lockedUntil]
        );
        $this->releaseConnection();
    }

    /**
     * Set a user's password_changed_at via DBAL using a relative modifier (e.g.
     * '-60 days'), so password-expiry scenarios can age a password past the configured
     * expiry without waiting. The change date now lives in the auth-password-policy-bundle
     * satellite table password_meta (FEATURE-145), so this upserts that row keyed by user_id.
     */
    public function setUserPasswordChangedAt(int $userId, string $relativeModifier): void
    {
        $connection = $this->entityManager()->getConnection();
        $changedAt = (new \DateTimeImmutable())->modify($relativeModifier)->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO password_meta (user_id, password_changed_at) VALUES (?, ?) '
            . 'ON CONFLICT(user_id) DO UPDATE SET password_changed_at = excluded.password_changed_at',
            [$userId, $changedAt]
        );
        $this->releaseConnection();
    }

    /**
     * Set a user's per-user IP whitelist via DBAL, so IP-whitelist scenarios can prove a per-user
     * override is respected over the global ip_whitelist.user_ips config. This is the only IP-whitelist
     * input not reachable through seedConfig.
     *
     * The override no longer lives on the `user` table: it moved into the auth-ip-whitelist-bundle
     * satellite `user_ip_whitelist` (FEATURE-146 / ADR-046). A row exists iff the user has a non-blank
     * override, so this deletes any existing row and inserts a fresh one only when the value is non-blank.
     */
    public function setUserAllowedIps(int $userId, ?string $allowedIps): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM user_ip_whitelist WHERE user_id = ?',
            [$userId]
        );
        if ($allowedIps !== null && trim($allowedIps) !== '') {
            $connection->executeStatement(
                'INSERT INTO user_ip_whitelist (user_id, allowed_ips) VALUES (?, ?)',
                [$userId, $allowedIps]
            );
        }
        $this->releaseConnection();
    }

    /**
     * Assert the user is locked (a satellite account_lockouts row with locked_until in the future),
     * proving real failed attempts actually wrote a lockout — not just that an error was rendered.
     */
    public function seeUserLocked(int $userId): void
    {
        $connection = $this->entityManager()->getConnection();
        $lockedUntil = $connection->fetchOne('SELECT locked_until FROM account_lockouts WHERE user_id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertTrue(
            $lockedUntil !== false && $lockedUntil !== null,
            sprintf('Expected user %d to have a lockout row.', $userId)
        );
        $this->assertGreaterThan(
            new \DateTimeImmutable(),
            new \DateTimeImmutable((string) $lockedUntil),
            sprintf('Expected user %d locked_until to be in the future.', $userId)
        );
    }

    /**
     * Assert the user is not locked (no active account_lockouts row), proving an admin unlock actually
     * removed the lockout rather than only flashing a message.
     */
    public function seeUserNotLocked(int $userId): void
    {
        $connection = $this->entityManager()->getConnection();
        $lockedUntil = $connection->fetchOne('SELECT locked_until FROM account_lockouts WHERE user_id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertTrue(
            $lockedUntil === null || $lockedUntil === false,
            sprintf('Expected user %d to have no lockout row, got "%s".', $userId, (string) $lockedUntil)
        );
    }

    public function seedConfig(string $key, string $value): void
    {
        $em = $this->entityManager();

        $config = $em->getRepository(Config::class)->findOneBy(['key' => $key]) ?? new Config();
        $config->setKey($key);
        $config->setValue($value);

        $em->persist($config);
        $em->flush();
        $em->clear();
        $this->releaseConnection();
    }

    /**
     * Assert a config value was persisted to the DB with the expected value, proving the
     * admin UI actually wrote it (not just echoed it back on the form). Assertions live
     * here because the acceptance actor has no Asserts module.
     */
    public function seeConfigValue(string $key, string $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $value = $connection->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);
        $this->releaseConnection();

        $this->assertSame(
            $expected,
            $value === false ? null : (string) $value,
            sprintf('Expected config "%s" to be persisted as "%s".', $key, $expected)
        );
    }

    /**
     * Insert a login_history row dated now for the given user, so tests can prove the
     * /account/login-history page shows the current user's logins (and only theirs)
     * without driving a separate real login for each seeded entry. The fingerprint is
     * SHA-256(ip + user_agent), matching the production LoginHistoryListener.
     */
    public function seedLoginHistory(int $userId, string $ip, string $userAgent): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO login_history (user_id, ip, user_agent, fingerprint, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $ip, $userAgent, hash('sha256', $ip . $userAgent), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        $this->releaseConnection();
    }

    /**
     * Insert a login_attempts row (the DBAL-only rate-limit store). Used to prove the
     * per-test reset actually clears this filtered table so failed-login counters cannot
     * leak across suite runs (FEATURE-129).
     */
    public function seedLoginAttempt(string $ip = '127.0.0.1', string $email = 'seed@example.com', string $realm = 'user'): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at, realm) VALUES (?, ?, ?, ?)',
            [$ip, $email, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $realm]
        );
        $this->releaseConnection();
    }

    /**
     * Insert an endpoint_rate_limits row (the other DBAL-only rate-limit store), same purpose
     * as {@see seedLoginAttempt}.
     */
    public function seedEndpointRateLimit(string $action = 'register', string $rateKey = '127.0.0.1'): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO endpoint_rate_limits (action, rate_key, hit_at) VALUES (?, ?, ?)',
            [$action, $rateKey, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        $this->releaseConnection();
    }

    /**
     * Assert the row count of a DBAL-only rate-limit table. Assertions live here because the
     * acceptance actor has no Asserts module.
     */
    public function seeRateLimitRowCount(string $table, int $expected): void
    {
        if (!\in_array($table, ['login_attempts', 'endpoint_rate_limits'], true)) {
            throw new \InvalidArgumentException(sprintf('Unexpected rate-limit table "%s".', $table));
        }

        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        $this->releaseConnection();

        $this->assertSame($expected, $count, sprintf('Expected %d row(s) in %s.', $expected, $table));
    }

    /**
     * Insert a password_history row holding a bcrypt hash of the given plaintext for
     * the user, so reuse-prevention scenarios can prove a previously-used password is
     * rejected. Mirrors production PasswordHistoryService::storeHash; password_verify
     * works regardless of the bcrypt cost used here.
     */
    public function seedPasswordHistory(int $userId, string $plaintextPassword): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO password_history (user_id, password_hash, created_at) VALUES (?, ?, ?)',
            [$userId, $this->hash($plaintextPassword), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        $this->releaseConnection();
    }

    /**
     * Assert the number of active (non-revoked) personal access tokens for the user,
     * proving a create wrote a real row, a revoke cleared it, and an over-limit create
     * was rejected rather than persisted. Assertions live here because the acceptance
     * actor has no Asserts module.
     */
    public function seeActiveTokenCountForUser(int $userId, int $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
        $this->releaseConnection();

        $this->assertSame(
            $expected,
            $count,
            sprintf('Expected user %d to have %d active token(s).', $userId, $expected)
        );
    }

    /**
     * Create an active personal access token for a User and return its plaintext value
     * (the Bearer credential for the user-side `api` firewall). Only the SHA-256 hash is
     * stored, matching the production PAT flow.
     */
    public function createPersonalAccessToken(int $userId, string $name = 'API Token'): string
    {
        $plainToken = bin2hex(random_bytes(32));

        $connection = $this->entityManager()->getConnection();
        $connection->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => hash('sha256', $plainToken),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->releaseConnection();

        return $plainToken;
    }

    /**
     * Create an active admin access token for an Admin and return its plaintext Bearer
     * credential (for the admin REST API, /admin-api). Only the SHA-256 hash is stored.
     * The admin_api firewall authenticates Admin entities via these tokens, so the admin
     * API can only ever be driven by a real Admin — never a User, whatever roles it holds.
     */
    public function createAdminAccessToken(int $adminId, string $name = 'Admin API Token'): string
    {
        $plainToken = bin2hex(random_bytes(32));

        $connection = $this->entityManager()->getConnection();
        $connection->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => $name,
            'token_hash' => hash('sha256', $plainToken),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->releaseConnection();

        return $plainToken;
    }

    /**
     * Assert the user's login_notifications_enabled flag, proving the account settings
     * form persisted the toggle to the database rather than only echoing it back.
     */
    public function seeLoginNotificationsEnabledFlag(int $userId, bool $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $flag = $connection->fetchOne('SELECT login_notifications_enabled FROM "user" WHERE id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertSame(
            $expected ? 1 : 0,
            (int) $flag,
            sprintf('Expected user %d login_notifications_enabled to be %s.', $userId, $expected ? 'true' : 'false')
        );
    }

    /**
     * Insert an audit_log row dated now, so tests can populate the admin audit-log view
     * (pagination, filtering) without exercising every action that records an entry.
     */
    public function seedAuditLog(
        string $actor,
        string $actorType = 'user',
        string $action = 'login',
        string $outcome = 'success',
        string $ip = '127.0.0.1'
    ): void {
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO audit_log (actor, actor_type, ip, action, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$actor, $actorType, $ip, $action, $outcome, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        $this->releaseConnection();
    }

    /**
     * Create an invitation row and return its plaintext token (the value that goes
     * in the /register?token=... link). Only the SHA-256 hash is stored, matching
     * the production invitation flow. Pass a negative $expiryDays to seed an expired
     * invitation.
     */
    public function createInvitation(string $email, int $expiryDays = 7): string
    {
        $em = $this->entityManager();

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = (new \DateTimeImmutable())->modify(sprintf('%+d days', $expiryDays));

        $em->persist(new Invitation($email, $tokenHash, $expiresAt));
        $em->flush();
        $em->clear();
        $this->releaseConnection();

        return $plainToken;
    }

    /**
     * Assert exactly one invitation row exists for the email, proving the admin invite
     * flow actually wrote a row (not just echoed a flash). Assertions live here because
     * the acceptance actor has no Asserts module.
     */
    public function seeExactlyOneInvitationForEmail(string $email): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM invitations WHERE email = ?', [$email]);
        $this->releaseConnection();

        $this->assertSame(1, $count, sprintf('Expected exactly one invitation for "%s".', $email));
    }

    /**
     * Assert the invitation for the email has been consumed (used_at set), proving that
     * completing registration via the invite invalidated it.
     */
    public function seeInvitationUsed(string $email): void
    {
        $connection = $this->entityManager()->getConnection();
        $usedAt = $connection->fetchOne('SELECT used_at FROM invitations WHERE email = ?', [$email]);
        $this->releaseConnection();

        $this->assertTrue(
            $usedAt !== false && $usedAt !== null,
            sprintf('Expected the invitation for "%s" to be marked used.', $email)
        );
    }

    /**
     * Assert the invitation's stored token_hash no longer matches the old plaintext
     * token, proving a resend rotated the token (new token generated, old invalidated).
     */
    public function seeInvitationTokenRotated(string $email, string $oldPlaintext): void
    {
        $connection = $this->entityManager()->getConnection();
        $currentHash = $connection->fetchOne('SELECT token_hash FROM invitations WHERE email = ?', [$email]);
        $this->releaseConnection();

        $this->assertNotFalse($currentHash, sprintf('Expected an invitation for "%s".', $email));
        $this->assertNotSame(
            hash('sha256', $oldPlaintext),
            $currentHash,
            'Expected the invitation token to be rotated on resend.'
        );
    }

    /**
     * Create a password-reset token row and return its plaintext token (the value
     * that goes in the /reset-password/{token} link). Only the SHA-256 hash is
     * stored, matching the production forgot-password flow. Pass a negative
     * $expiryMinutes to seed an already-expired token, or $used = true to seed a
     * token that has already been consumed.
     */
    public function createPasswordResetToken(string $email, int $expiryMinutes = 60, bool $used = false): string
    {
        $em = $this->entityManager();

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = (new \DateTimeImmutable())->modify(sprintf('%+d minutes', $expiryMinutes));

        $token = new PasswordResetToken($email, $tokenHash, $expiresAt);
        if ($used) {
            $token->markUsed();
        }

        $em->persist($token);
        $em->flush();
        $em->clear();
        $this->releaseConnection();

        return $plainToken;
    }

    /**
     * Assert a forgot-password request created exactly one reset token for the email
     * (AC1). Assertions live here because the acceptance actor has no Asserts module.
     */
    public function seeExactlyOnePasswordResetToken(string $email): void
    {
        $this->assertSame(
            1,
            $this->countPasswordResetTokens($email),
            sprintf('Expected exactly one password reset token for "%s".', $email)
        );
    }

    /**
     * Assert no reset token exists for the email (AC2, anti-enumeration): a request
     * for an unregistered address must not create a token.
     */
    public function dontSeePasswordResetToken(string $email): void
    {
        $this->assertSame(
            0,
            $this->countPasswordResetTokens($email),
            sprintf('Expected no password reset token for "%s".', $email)
        );
    }

    private function countPasswordResetTokens(string $email): int
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE email = ?',
            [$email]
        );
        $this->releaseConnection();

        return $count;
    }

    /**
     * Assert that the token identified by its plaintext value has been consumed
     * (used_at set), proving the reset flow invalidated it.
     */
    public function seePasswordResetTokenUsed(string $plaintext): void
    {
        $connection = $this->entityManager()->getConnection();
        $usedAt = $connection->fetchOne(
            'SELECT used_at FROM password_reset_tokens WHERE token_hash = ?',
            [hash('sha256', $plaintext)]
        );
        $this->releaseConnection();

        $this->assertNotNull($usedAt, 'Expected the password reset token to be marked used.');
    }

    /**
     * Assert the token identified by its plaintext value has NOT been consumed
     * (used_at still null), proving a rejected reset (e.g. password reuse) left the
     * token valid rather than silently applying the change.
     */
    public function seePasswordResetTokenNotUsed(string $plaintext): void
    {
        $connection = $this->entityManager()->getConnection();
        $usedAt = $connection->fetchOne(
            'SELECT used_at FROM password_reset_tokens WHERE token_hash = ?',
            [hash('sha256', $plaintext)]
        );
        $this->releaseConnection();

        $this->assertTrue(
            $usedAt === null || $usedAt === false,
            'Expected the password reset token to remain unused.'
        );
    }

    /**
     * Create a magic-link token row and return its plaintext token (the value that goes
     * in the /magic-link/verify?token=... link). Only the SHA-256 hash is stored, matching
     * the production magic-link flow. Pass a negative $expiryMinutes to seed an
     * already-expired token, or $used = true to seed a token that has already been consumed.
     */
    public function createMagicLinkToken(string $email, int $expiryMinutes = 15, bool $used = false): string
    {
        $em = $this->entityManager();

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = (new \DateTimeImmutable())->modify(sprintf('%+d minutes', $expiryMinutes));

        $token = new MagicLinkToken($email, $tokenHash, $expiresAt);
        if ($used) {
            $token->markUsed();
        }

        $em->persist($token);
        $em->flush();
        $em->clear();
        $this->releaseConnection();

        return $plainToken;
    }

    /**
     * Assert a magic-link request created exactly one token for the email (AC2), proving
     * the flow wrote a real row rather than only rendering the neutral confirmation page.
     */
    public function seeExactlyOneMagicLinkToken(string $email): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM magic_link_tokens WHERE email = ?',
            [$email]
        );
        $this->releaseConnection();

        $this->assertSame(1, $count, sprintf('Expected exactly one magic-link token for "%s".', $email));
    }

    /**
     * Assert the magic-link token identified by its plaintext value has been consumed
     * (used_at set), proving a successful login actually invalidated it (single use).
     */
    public function seeMagicLinkTokenUsed(string $plaintext): void
    {
        $connection = $this->entityManager()->getConnection();
        $usedAt = $connection->fetchOne(
            'SELECT used_at FROM magic_link_tokens WHERE token_hash = ?',
            [hash('sha256', $plaintext)]
        );
        $this->releaseConnection();

        $this->assertNotNull($usedAt, 'Expected the magic-link token to be marked used.');
    }

    /**
     * Generate a valid signed email-verification URL for the given user and return
     * just its path+query (host-relative), ready for $I->amOnPage().
     */
    public function generateVerificationPath(int $userId, string $email): string
    {
        $helper = $this->container()->get(VerifyEmailHelperInterface::class);

        return $this->signedVerificationPath($helper, $userId, $email);
    }

    /**
     * Generate a verification URL whose signature is valid but whose `expires`
     * timestamp is already in the past, so the application rejects it with the
     * bundle's ExpiredSignatureException (a genuine expiry, not a tampered token).
     */
    public function generateExpiredVerificationPath(int $userId, string $email): string
    {
        $helper = $this->container()->get(VerifyEmailHelperInterface::class);

        return $this->signedVerificationPath($this->withPastLifetime($helper), $userId, $email);
    }

    /**
     * Assert the user's verified flag in the database. The acceptance actor has no
     * PHPUnit assertions of its own (no Asserts module enabled), so the check lives
     * here where the Codeception Module base class provides assertion helpers.
     */
    public function seeUserVerified(int $userId): void
    {
        $this->assertSame(1, $this->verifiedFlag($userId), 'Expected user to be verified in the database.');
    }

    public function dontSeeUserVerified(int $userId): void
    {
        $this->assertSame(0, $this->verifiedFlag($userId), 'Expected user to remain unverified in the database.');
    }

    public function seeExactlyOneUserWithEmail(string $email): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]);
        $this->releaseConnection();

        $this->assertSame(1, $count, sprintf('Expected exactly one user with email "%s".', $email));
    }

    /**
     * Assert no user exists with the given email, proving a rejected registration
     * (e.g. a password-policy violation) did not create an account.
     */
    public function dontSeeUserWithEmail(string $email): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]);
        $this->releaseConnection();

        $this->assertSame(0, $count, sprintf('Expected no user with email "%s".', $email));
    }

    /**
     * Assert a user's status column (FEATURE-110 soft delete: "deleting" a user flips status to
     * 'inactive' rather than removing the row).
     */
    public function seeUserHasStatus(int $userId, string $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $status = $connection->fetchOne('SELECT status FROM "user" WHERE id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertNotFalse($status, sprintf('Expected user #%d to still exist.', $userId));
        $this->assertSame($expected, $status, sprintf('Expected user #%d to have status "%s".', $userId, $expected));
    }

    /**
     * Assert the number of user_sessions rows for a user, proving a teardown actually deleted
     * the row(s) — not just that the browser happened to get bounced to /login.
     */
    public function seeUserSessionCountForUser(int $userId, int $expected): void
    {
        $connection = $this->entityManager()->getConnection();
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);
        $this->releaseConnection();

        $this->assertSame(
            $expected,
            $count,
            sprintf('Expected user %d to have %d user_sessions row(s).', $userId, $expected)
        );
    }

    private function verifiedFlag(int $userId): int
    {
        $connection = $this->entityManager()->getConnection();
        $verified = $connection->fetchOne('SELECT is_verified FROM "user" WHERE id = ?', [$userId]);
        $this->releaseConnection();

        return (int) $verified;
    }

    /**
     * Sign a verification URL with the given helper. The router's RequestContext is
     * pointed at the live acceptance server's host/port first, so the host baked into
     * the signed URI matches the Host header phpBrowser sends — otherwise the
     * signature check on the server would fail before expiry/token checks run.
     */
    private function signedVerificationPath(VerifyEmailHelperInterface $helper, int $userId, string $email): string
    {
        /** @var RouterInterface $router */
        $router = $this->container()->get('router');
        $context = $router->getContext();
        $context->setHost(self::SERVER_HOST);
        $context->setHttpPort(self::SERVER_PORT);
        $context->setScheme('http');

        $components = $helper->generateSignature(
            'app_verify_email',
            (string) $userId,
            $email,
            ['id' => $userId]
        );

        $parts = parse_url($components->getSignedUrl());
        $path = ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * Build a VerifyEmailHelper that reuses the container helper's collaborators but
     * with a negative lifetime, so generated URLs are already expired.
     */
    private function withPastLifetime(VerifyEmailHelperInterface $helper): VerifyEmailHelper
    {
        $read = static function (string $property) use ($helper) {
            $ref = new \ReflectionProperty(VerifyEmailHelper::class, $property);
            $ref->setAccessible(true);

            return $ref->getValue($helper);
        };

        return new VerifyEmailHelper(
            $read('router'),
            $read('uriSigner'),
            $read('queryUtility'),
            $read('tokenGenerator'),
            -3600,
        );
    }

    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * Close the seeding connection so this process never holds a SQLite lock
     * while the live server is serving requests against the same file.
     */
    private function releaseConnection(): void
    {
        if ($this->em !== null) {
            $this->em->getConnection()->close();
        }
    }

    private function container(): ContainerInterface
    {
        $this->entityManager();

        return $this->container;
    }

    private function entityManager(): EntityManagerInterface
    {
        if ($this->em === null) {
            $this->kernel = new Kernel('test', true);
            $this->kernel->boot();

            $container = $this->kernel->getContainer();
            if ($container->has('test.service_container')) {
                $container = $container->get('test.service_container');
            }
            $this->container = $container;

            /** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
            $doctrine = $container->get('doctrine');
            /** @var EntityManagerInterface $em */
            $em = $doctrine->getManager();
            $this->em = $em;

            // WAL lets the live server keep reading while we seed; the busy
            // timeout makes brief writer overlaps retry instead of failing.
            $connection = $em->getConnection();
            $connection->executeStatement('PRAGMA journal_mode = WAL');
            $connection->executeStatement('PRAGMA busy_timeout = 10000');
        }

        return $this->em;
    }
}
