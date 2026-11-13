<?php

declare(strict_types=1);

namespace App\Tests\Functional\Schema;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FEATURE-129 (review C40): the DBAL schema_asset_filter must exclude the four DBAL-only tables
 * from ORM schema management (FEATURE-121/C29 protection: a future doctrine:migrations:diff must
 * never emit DROP TABLE for the rate-limiting / session / messenger stores) WITHOUT also hiding
 * `doctrine_migration_versions`.
 *
 * Hiding the migration-bookkeeping table from introspection is what made
 * `doctrine:migrations:migrate` non-idempotent: the migrations bundle's TableMetadataStorage
 * calls tablesExist(['doctrine_migration_versions']), and a filtered-away table reads as missing,
 * so migrate tries to CREATE it on an already-migrated DB and dies with "table already exists"
 * (verify-fast step 1 failing on every 2nd consecutive run). The migration table is instead kept
 * out of the ORM comparison by {@see SchemaSyncTest}, mirroring what the console does.
 */
final class SchemaFilterTest extends KernelTestCase
{
    /** Tables intentionally excluded from ORM schema management (no entity maps them). */
    private const DBAL_ONLY_TABLES = ['login_attempts', 'endpoint_rate_limits', 'sessions', 'messenger_messages'];

    private function schemaManager(): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em->getConnection()->createSchemaManager();
    }

    public function testMigrationMetadataTableIsIntrospectable(): void
    {
        // If the filter hides this table, migrate believes it is uninitialised and re-creates it.
        self::assertTrue(
            $this->schemaManager()->tablesExist(['doctrine_migration_versions']),
            'doctrine_migration_versions must be visible to introspection so migrate stays idempotent.',
        );
    }

    public function testDbalOnlyTablesRemainExcludedFromOrmManagement(): void
    {
        $sm = $this->schemaManager();
        $managed = $sm->listTableNames();

        foreach (self::DBAL_ONLY_TABLES as $table) {
            self::assertNotContains(
                $table,
                $managed,
                sprintf('%s must stay filtered so migrations:diff never emits DROP TABLE for it.', $table),
            );
        }
    }
}
