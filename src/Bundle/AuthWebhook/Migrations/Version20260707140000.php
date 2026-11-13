<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * auth-webhook-bundle owns the `webhook_delivery` schema (FEATURE-141, migration-ownership pattern —
 * mirrors auth-magic-link-bundle's Version20260707130000). This migration lives under the bundle's own
 * migrations dir, registered via AuthWebhookExtension::prepend() ONLY when the bundle is enabled — so a
 * deploy without the bundle never runs it.
 *
 * It is GUARDED (create-if-not-exists): on the existing history the earlier app migration
 * (Version20260531000000) already created the table with the final shape, so this is a no-op there; on a
 * fresh DB where the app migration was removed and the bundle fully owns the table it recreates the exact
 * ORM-mapped shape (two named indexes). Idempotent and reversible.
 */
final class Version20260707140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth-webhook-bundle owns webhook_delivery (guarded create-if-not-exists)';
    }

    public function up(Schema $schema): void
    {
        // Create-if-not-exists, declaratively (IF NOT EXISTS, valid on SQLite 3.26): on a history where the
        // table already exists this is a no-op that is still RECORDED as executed, so the version never stays
        // pending. Not an introspection of the live database: in a --dry-run / --write-sql nothing executes, so
        // this and the core migration would both see no table and both emit a plain CREATE, and the written SQL
        // would not replay (issue #38).
        $this->addSql('CREATE TABLE IF NOT EXISTS webhook_delivery (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            url VARCHAR(2048) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            payload CLOB NOT NULL,
            status VARCHAR(20) NOT NULL,
            response_code INTEGER DEFAULT NULL,
            attempted_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_webhook_delivery_event_type ON webhook_delivery (event_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_webhook_delivery_status ON webhook_delivery (status)');
    }

    public function down(Schema $schema): void
    {
        // Intentional no-op. up() is guarded (it only creates the table when absent), so on the existing
        // history it did nothing — the table's creation is owned by the earlier app migration, whose own
        // down() drops it. Dropping here would double-drop during a full rollback. A no-op is the
        // faithful inverse of a skipped up().
    }
}
