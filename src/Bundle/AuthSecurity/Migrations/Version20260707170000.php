<?php

declare(strict_types=1);

// NOTE the deliberately non-`App\Bundle\...` namespace. Doctrine Migrations orders migrations across all
// registered paths by fully-qualified CLASS NAME (strcmp), so every `App\Bundle\...` migration sorts
// BEFORE every `DoctrineMigrations\...` one ('A' < 'D'). This satellite migration must run AFTER the core
// `DoctrineMigrations\...` migration that creates `user` (it declares a FOREIGN KEY against it). A
// namespace that sorts after "DoctrineMigrations" (here `SecurityBundleMigrations`, 'S' > 'D') gives that
// ordering while the file still lives in the bundle's own Migrations dir and is registered ONLY when the
// bundle is enabled (AuthSecurityExtension::prepend) — so it never runs on a deploy without the bundle.
// See ADR-044 (mirrors the auth-2fa-bundle satellite, ADR-043).
namespace SecurityBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-security-bundle owns the `account_lockouts` satellite table (FEATURE-144 / ADR-044): the account
 * hard-lockout state.
 *
 * A plain create (exact ORM-mapped shape — DDL taken from `doctrine:schema:update --dump-sql`, so
 * SchemaSyncTest / getUpdateSchemaList stays green). No production deploy predates this point, so
 * there is no pre-extraction `user.locked_until` state left to relocate — core `user` is created
 * without that column to begin with (see the migrations/ squash, ADR-054). down() is the faithful
 * inverse: drop the satellite table.
 */
final class Version20260707170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-security-bundle owns the account_lockouts satellite';
    }

    public function up(Schema $schema): void
    {
        // Unidirectional AccountLockout -> User, unique user_id, CASCADE.
        $this->addSql('CREATE TABLE account_lockouts (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locked_until DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_34F45E15A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_34F45E15A76ED395 ON account_lockouts (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_lockouts');
    }
}
