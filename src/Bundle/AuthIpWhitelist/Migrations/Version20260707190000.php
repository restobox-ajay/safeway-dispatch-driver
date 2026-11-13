<?php

declare(strict_types=1);

// NOTE the deliberately non-`App\Bundle\...` namespace. Doctrine Migrations orders migrations across all
// registered paths by fully-qualified CLASS NAME (strcmp), so every `App\Bundle\...` migration sorts
// BEFORE every `DoctrineMigrations\...` one ('A' < 'D'). This satellite migration must run AFTER the core
// `DoctrineMigrations\...` migration that creates `user` (it declares a FOREIGN KEY against it). A
// namespace that sorts after "DoctrineMigrations" (here `IpWhitelistBundleMigrations`, 'I' > 'D') gives
// that ordering while the file still lives in the bundle's own Migrations dir and is registered ONLY when
// the bundle is enabled (AuthIpWhitelistExtension::prepend) — so it never runs on a deploy without the
// bundle. See ADR-046 (mirrors the auth-2fa / auth-security / auth-password-policy satellites, ADR-043 /
// ADR-044 / ADR-045).
namespace IpWhitelistBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-ip-whitelist-bundle owns the `user_ip_whitelist` satellite table (FEATURE-146 / ADR-046): the
 * per-user IP-whitelist override.
 *
 * A plain create (exact ORM-mapped shape — DDL taken from `doctrine:schema:update --dump-sql`, so
 * SchemaSyncTest / getUpdateSchemaList stays green). No production deploy predates this point, so
 * there is no pre-extraction `user.allowed_ips` state left to relocate — core `user` is created
 * without that column to begin with (see the migrations/ squash, ADR-054). down() is the faithful
 * inverse: drop the satellite table.
 */
final class Version20260707190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-ip-whitelist-bundle owns the user_ip_whitelist satellite';
    }

    public function up(Schema $schema): void
    {
        // Unidirectional UserIpWhitelist -> User, unique user_id, CASCADE.
        $this->addSql('CREATE TABLE user_ip_whitelist (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, allowed_ips CLOB NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_D5D0EF08A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D5D0EF08A76ED395 ON user_ip_whitelist (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_ip_whitelist');
    }
}
