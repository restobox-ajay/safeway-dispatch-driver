<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-magic-link-bundle owns the `magic_link_tokens` schema (FEATURE-140, migration-ownership
 * pattern — mirrors auth-pat-bundle's Version20260707120000). This migration lives under the bundle's
 * own migrations dir, registered via AuthMagicLinkExtension::prepend() ONLY when the bundle is
 * enabled — so a deploy without the bundle never runs it, and the table never exists without it.
 *
 * The sole, unconditional creator (no production deploy predates this point, so the legacy
 * app-owned create this used to guard against — and defer to — no longer exists; see the
 * migrations/ squash, ADR-054).
 */
final class Version20260707130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-magic-link-bundle owns magic_link_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magic_link_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_99E6B427B3BC57DA ON magic_link_tokens (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE magic_link_tokens');
    }
}
