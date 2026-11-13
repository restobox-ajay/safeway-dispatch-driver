<?php

declare(strict_types=1);

namespace App\Tests\Functional\Schema;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FEATURE-122 (review C30): the hot-column indexes must exist in the migration-built database.
 * password_reset_tokens filters by email (sibling-invalidation on every reset), and audit_log
 * sorts/filters/prunes by created_at and filters by action on every viewer page.
 */
final class AuditIndexTest extends KernelTestCase
{
    private function connection(): Connection
    {
        self::bootKernel();

        return self::getContainer()->get(Connection::class);
    }

    /** @return string[] the column list of every index on $table that covers exactly the wanted columns */
    private function assertIndexOn(string $table, array $columns, string $message): void
    {
        $indexes = $this->connection()->createSchemaManager()->listTableIndexes($table);

        foreach ($indexes as $index) {
            if ($index->getColumns() === $columns) {
                self::assertTrue(true, $message);

                return;
            }
        }

        self::fail($message . ' (indexes present: ' . implode(', ', array_map(
            static fn ($i) => $i->getName() . '(' . implode(',', $i->getColumns()) . ')',
            $indexes,
        )) . ')');
    }

    public function testPasswordResetTokensHasEmailIndex(): void
    {
        $this->assertIndexOn('password_reset_tokens', ['email'], 'password_reset_tokens must have an index on email');
    }

    public function testAuditLogHasCreatedAtIndex(): void
    {
        $this->assertIndexOn('audit_log', ['created_at'], 'audit_log must have an index on created_at');
    }

    public function testAuditLogHasActionIndex(): void
    {
        $this->assertIndexOn('audit_log', ['action'], 'audit_log must have an index on action');
    }
}
