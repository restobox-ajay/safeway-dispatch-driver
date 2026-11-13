<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FEATURE-193 / ADR-074: driver_api_session holds a signed-in driver's fvstexpress-admin API tokens, keyed by
 * the SHA-256 of a random key kept in the driver's PHP session.
 *
 * SQLite 3.26.0-safe: plain CREATE TABLE / CREATE UNIQUE INDEX.
 */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add driver_api_session (driver web app: admin API tokens per signed-in driver, ADR-074)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE driver_api_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, key_hash VARCHAR(64) NOT NULL, driver_id INTEGER NOT NULL, driver_json CLOB NOT NULL, access_token CLOB NOT NULL, refresh_token CLOB NOT NULL, access_expires_at DATETIME NOT NULL, device_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_driver_api_session_key ON driver_api_session (key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE driver_api_session');
    }
}
