<?php

declare(strict_types=1);

// NOTE the deliberately non-`App\Bundle\...` namespace. Doctrine Migrations orders migrations across all
// registered paths by fully-qualified CLASS NAME (strcmp), so every `App\Bundle\...` migration sorts
// BEFORE every `DoctrineMigrations\...` one ('A' < 'D'). This satellite migration must run AFTER the core
// `DoctrineMigrations\...` migration that creates `user` (it declares a FOREIGN KEY against it). A
// namespace that sorts after "DoctrineMigrations" (here `TwoFactorMigrations`, 'T' > 'D') gives that
// ordering while the file still lives in the bundle's own Migrations dir and is registered ONLY when the
// bundle is enabled (Auth2faExtension::prepend) — so it never runs on a deploy without the bundle. See
// ADR-043.
namespace TwoFactorMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-2fa-bundle owns the `two_factor_settings` satellite table (FEATURE-143 / ADR-043).
 *
 * A plain create (exact ORM-mapped shape — DDL taken from `doctrine:schema:update --dump-sql`, so
 * SchemaSyncTest / getUpdateSchemaList stays green). No production deploy predates this point, so
 * there is no pre-extraction `user.totp_secret` / `is_totp_enabled` / `last_totp_counter` state left
 * to relocate — core `user` is created without those columns to begin with (see the migrations/
 * squash, ADR-054). down() is the faithful inverse: drop the satellite table.
 *
 * Registered via Auth2faExtension::prepend() ONLY when the bundle is enabled, so a deploy without the
 * bundle never runs it (the satellite table is never created).
 */
final class Version20260707160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-2fa-bundle owns the two_factor_settings satellite';
    }

    public function up(Schema $schema): void
    {
        // Unidirectional TwoFactorSettings -> User, unique user_id, CASCADE.
        $this->addSql('CREATE TABLE two_factor_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, totp_secret VARCHAR(64) DEFAULT NULL, is_totp_enabled BOOLEAN DEFAULT 0 NOT NULL, last_totp_counter INTEGER DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_69430517A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_69430517A76ED395 ON two_factor_settings (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE two_factor_settings');
    }
}
