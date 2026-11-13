<?php

declare(strict_types=1);

namespace PasswordPolicyBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-password-policy-bundle owns the `password_history` table (FEATURE-145 / ADR-045,
 * migration-ownership pattern; mirrors auth-security-bundle's create, ADR-044).
 * `password_history` IS ORM-mapped (its entity lives in this bundle).
 *
 * The sole, unconditional creator (no production deploy predates this point, so the legacy
 * app-owned create this used to guard against — and defer to — no longer exists; see the
 * migrations/ squash, ADR-054). Registered ONLY via AuthPasswordPolicyExtension::prepend(), so a
 * deploy without the bundle never runs it, and the table never exists without it.
 */
final class Version20260707180100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-password-policy-bundle owns password_history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_ph_user_id_created ON password_history (user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_history');
    }
}
