<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #39 / ADR-064: admin API tokens get a `token_hint` (last 6 characters of the plaintext) so the
 * token pages and the CLI can show WHICH token is which before you revoke it. Nullable: tokens issued before
 * this migration have no hint (the plaintext was never stored).
 *
 * Backfill: tokens of admins that are ALREADY inactive (deactivated or soft-deleted before this release) are
 * revoked now. Until this change those were only rejected while the admin stayed inactive, so reactivating the
 * account would have revived them — the exact bug this release fixes for future deactivations.
 *
 * SQLite 3.26.0-safe: `ADD COLUMN` (nullable, no default expression) has been supported for decades. down()
 * uses the table-rebuild idiom, never `DROP COLUMN` (3.35+).
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_access_tokens.token_hint (last 6 chars of the plaintext) for token identification (issue #39)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_access_tokens ADD COLUMN token_hint VARCHAR(6) DEFAULT NULL');
        $this->addSql("UPDATE admin_access_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE revoked_at IS NULL AND admin_id IN (SELECT id FROM admin WHERE status = 'inactive')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__admin_access_tokens AS SELECT id, admin_id, name, token_hash, expires_at, last_used_at, revoked_at, created_at FROM admin_access_tokens');
        $this->addSql('DROP TABLE admin_access_tokens');
        $this->addSql('CREATE TABLE admin_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, admin_id INTEGER NOT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO admin_access_tokens (id, admin_id, name, token_hash, expires_at, last_used_at, revoked_at, created_at) SELECT id, admin_id, name, token_hash, expires_at, last_used_at, revoked_at, created_at FROM __temp__admin_access_tokens');
        $this->addSql('DROP TABLE __temp__admin_access_tokens');
        $this->addSql('CREATE INDEX IDX_AAT_ADMIN_ID ON admin_access_tokens (admin_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86135BDBB3BC57DA ON admin_access_tokens (token_hash)');
    }
}
