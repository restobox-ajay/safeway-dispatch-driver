<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-pat-bundle owns the `personal_access_tokens` schema (FEATURE-138 AC2, migration-ownership
 * pattern). This migration lives under the bundle's own migrations dir, registered via
 * AuthPatExtension::prepend() ONLY when the bundle is enabled — so a deploy without the bundle never
 * runs it, and the table never exists without it.
 *
 * The sole, unconditional creator (no production deploy predates this point, so the legacy
 * app-owned create this used to guard against — and defer to — no longer exists; see the
 * migrations/ squash, ADR-054).
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-pat-bundle owns personal_access_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_access_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            user_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE INDEX IDX_PAT_USER_ID ON personal_access_tokens (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E63C2166B3BC57DA ON personal_access_tokens (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_access_tokens');
    }
}
