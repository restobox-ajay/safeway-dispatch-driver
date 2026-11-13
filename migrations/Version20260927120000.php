<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squashed core schema (no production deploys predate this point, so the 38 incremental
 * `DoctrineMigrations\...` migrations that built and reshaped this schema over time — including the
 * intermediate SQLite table-rebuild dances and the columns later relocated into bundle-owned satellite
 * tables — collapse into this single migration; only the FINAL shape matters going forward).
 *
 * Excludes every table now owned outright by an optional bundle's own migrations dir (migration-ownership
 * pattern, ADR-010/040/043/044/045/046): `personal_access_tokens` (auth-pat-bundle), `magic_link_tokens`
 * (auth-magic-link-bundle), `login_attempts` / `endpoint_rate_limits` (auth-security-bundle),
 * `password_history` (auth-password-policy-bundle), and the satellite tables `two_factor_settings`
 * (auth-2fa-bundle), `account_lockouts` (auth-security-bundle), `password_meta`
 * (auth-password-policy-bundle), `user_ip_whitelist` (auth-ip-whitelist-bundle) — `user` / `admin` are
 * created here in their FINAL shape, without the columns those four satellites now hold, since there is no
 * pre-extraction history left to relocate data out of. `webhook_delivery` stays here on purpose (ADR-042):
 * auth-webhook-bundle's AC3 requires the table to persist even when that bundle is uninstalled, so core
 * keeps owning its creation and the bundle's own migration stays a guarded no-op.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed core schema (pre-production; supersedes the 38 incremental app migrations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, roles CLOB NOT NULL, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'active\', totp_secret VARCHAR(64) DEFAULT NULL, is_totp_enabled BOOLEAN NOT NULL DEFAULT 0, last_totp_counter INTEGER DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_880E0D76E7927C74 ON admin (email)');

        $this->addSql('CREATE TABLE admin_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_id INTEGER NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IDX_AAT_ADMIN_ID ON admin_access_tokens (admin_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86135BDBB3BC57DA ON admin_access_tokens (token_hash)');

        $this->addSql('CREATE TABLE admin_login_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_id INTEGER NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_admin_login_history_admin_id ON admin_login_history (admin_id)');

        $this->addSql('CREATE TABLE admin_login_notification_seen (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_id INTEGER NOT NULL, marker VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_admin_login_notification_seen_admin_id ON admin_login_notification_seen (admin_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_admin_login_notification_seen_admin_marker ON admin_login_notification_seen (admin_id, marker)');

        $this->addSql('CREATE TABLE admin_password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IDX_APRT_EMAIL ON admin_password_reset_tokens (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D427069FB3BC57DA ON admin_password_reset_tokens (token_hash)');

        $this->addSql('CREATE TABLE admin_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, session_id VARCHAR(128) NOT NULL, admin_id INTEGER NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, last_active_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IDX_admin_sessions_admin_id ON admin_sessions (admin_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_admin_sessions_session_id ON admin_sessions (session_id)');

        $this->addSql('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor VARCHAR(255) NOT NULL, actor_type VARCHAR(10) NOT NULL, ip VARCHAR(45) NOT NULL, action VARCHAR(100) NOT NULL, outcome VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, context TEXT DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_AUDIT_LOG_CREATED_AT ON audit_log (created_at)');
        $this->addSql('CREATE INDEX IDX_AUDIT_LOG_ACTION ON audit_log (action)');

        $this->addSql('CREATE TABLE config (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, config_key VARCHAR(255) NOT NULL, config_value CLOB DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D48A2F7C95D1CAA6 ON config (config_key)');

        $this->addSql('CREATE TABLE db_console_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, admin_id INTEGER NOT NULL, expires_at DATETIME NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_db_console_session_token ON db_console_session (token_hash)');
        $this->addSql('CREATE INDEX idx_db_console_session_admin ON db_console_session (admin_id)');

        $this->addSql('CREATE TABLE db_console_throttle (ip_address VARCHAR(45) PRIMARY KEY NOT NULL, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL)');

        $this->addSql('CREATE TABLE invitations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_232710AEB3BC57DA ON invitations (token_hash)');

        $this->addSql('CREATE TABLE login_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_login_history_user_id ON login_history (user_id)');

        $this->addSql('CREATE TABLE login_notification_seen (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, marker VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_login_notification_seen_user_id ON login_notification_seen (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_login_notification_seen_user_marker ON login_notification_seen (user_id, marker)');

        $this->addSql('CREATE TABLE messenger_messages (id INTEGER NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_MSG_QUEUE_NAME ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_MSG_AVAILABLE_AT ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_MSG_DELIVERED_AT ON messenger_messages (delivered_at)');

        $this->addSql('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A216B3BC57DA ON password_reset_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_PRT_EMAIL ON password_reset_tokens (email)');

        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BLOB NOT NULL, sess_time INTEGER NOT NULL, sess_lifetime INTEGER NOT NULL, PRIMARY KEY (sess_id))');

        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, roles CLOB NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, login_notifications_enabled BOOLEAN NOT NULL DEFAULT 1, is_verified BOOLEAN NOT NULL DEFAULT 0, sessions_invalidated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');

        $this->addSql('CREATE TABLE user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, session_id VARCHAR(128) NOT NULL, user_id INTEGER NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, last_active_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_user_sessions_session_id ON user_sessions (session_id)');
        $this->addSql('CREATE INDEX IDX_user_sessions_user_id ON user_sessions (user_id)');

        // Create-if-not-exists: auth-webhook-bundle's own migration
        // (App\Bundle\AuthWebhook\Migrations\Version20260707140000) sorts BEFORE this one across
        // registered migration paths ('A' < 'D' by fully-qualified class name), so it may already have
        // created the table when the bundle is enabled. Core still owns creating it (ADR-042: the
        // bundle's AC3 requires webhook_delivery to persist even when the bundle is uninstalled).
        // The guard is declarative (IF NOT EXISTS, valid on SQLite 3.26), not an introspection of the live
        // database: in a --dry-run / --write-sql nothing executes, so both migrations would see no table and
        // both emit a plain CREATE, and the written SQL would not replay (issue #38).
        $this->addSql('CREATE TABLE IF NOT EXISTS webhook_delivery (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, event_type VARCHAR(100) NOT NULL, payload CLOB NOT NULL, status VARCHAR(20) NOT NULL, response_code INTEGER DEFAULT NULL, attempted_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_webhook_delivery_event_type ON webhook_delivery (event_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_webhook_delivery_status ON webhook_delivery (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_delivery');
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE login_notification_seen');
        $this->addSql('DROP TABLE login_history');
        $this->addSql('DROP TABLE invitations');
        $this->addSql('DROP TABLE db_console_throttle');
        $this->addSql('DROP TABLE db_console_session');
        $this->addSql('DROP TABLE config');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE admin_sessions');
        $this->addSql('DROP TABLE admin_password_reset_tokens');
        $this->addSql('DROP TABLE admin_login_notification_seen');
        $this->addSql('DROP TABLE admin_login_history');
        $this->addSql('DROP TABLE admin_access_tokens');
        $this->addSql('DROP TABLE admin');
    }
}
