<?php

declare(strict_types=1);

namespace SecurityBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-security-bundle owns the DBAL-only `endpoint_rate_limits` store (FEATURE-144 / ADR-044,
 * migration-ownership pattern; mirrors auth-pat-bundle's create, ADR-010).
 * `endpoint_rate_limits` is DBAL-only (no entity) and stays in the core doctrine `schema_filter`.
 *
 * The sole, unconditional creator (no production deploy predates this point, so the legacy
 * app-owned create this used to guard against — and defer to — no longer exists; see the
 * migrations/ squash, ADR-054). Registered ONLY via AuthSecurityExtension::prepend(), so a deploy
 * without the bundle never runs it, and the table never exists without it.
 */
final class Version20260707170200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-security-bundle owns endpoint_rate_limits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE endpoint_rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, action VARCHAR(64) NOT NULL, rate_key VARCHAR(255) NOT NULL, hit_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_endpoint_rate_limits_lookup ON endpoint_rate_limits (action, rate_key, hit_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE endpoint_rate_limits');
    }
}
