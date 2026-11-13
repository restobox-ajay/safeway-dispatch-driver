<?php

declare(strict_types=1);

// NOTE the deliberately non-`App\Bundle\...` namespace. Doctrine Migrations orders migrations across all
// registered paths by fully-qualified CLASS NAME (strcmp), so every `App\Bundle\...` migration sorts
// BEFORE every `DoctrineMigrations\...` one ('A' < 'D'). This satellite migration must run AFTER the core
// `DoctrineMigrations\...` migration that creates `user` (it declares a FOREIGN KEY against it). A
// namespace that sorts after "DoctrineMigrations" (here `PasswordPolicyBundleMigrations`, 'P' > 'D')
// gives that ordering while the file still lives in the bundle's own Migrations dir and is registered
// ONLY when the bundle is enabled (AuthPasswordPolicyExtension::prepend) — so it never runs on a deploy
// without the bundle. See ADR-045 (mirrors the auth-2fa / auth-security satellites, ADR-043 / ADR-044).
namespace PasswordPolicyBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-password-policy-bundle owns the `password_meta` satellite table (FEATURE-145 / ADR-045): the
 * password-changed timestamp.
 *
 * A plain create (exact ORM-mapped shape — DDL taken from `doctrine:schema:update --dump-sql`, so
 * SchemaSyncTest / getUpdateSchemaList stays green). No production deploy predates this point, so
 * there is no pre-extraction `user.password_changed_at` state left to relocate — core `user` is
 * created without that column to begin with (see the migrations/ squash, ADR-054). down() is the
 * faithful inverse: drop the satellite table.
 */
final class Version20260707180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-password-policy-bundle owns the password_meta satellite';
    }

    public function up(Schema $schema): void
    {
        // Unidirectional PasswordMeta -> User, unique user_id, CASCADE.
        $this->addSql('CREATE TABLE password_meta (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, password_changed_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_45A7137EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_45A7137EA76ED395 ON password_meta (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_meta');
    }
}
