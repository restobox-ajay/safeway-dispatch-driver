<?php

declare(strict_types=1);

namespace App\Tests\Functional\Schema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FEATURE-121 (review C29): the ORM entity metadata must be in sync with the migration-built
 * database, and the DBAL-only tables (login_attempts, endpoint_rate_limits, sessions,
 * messenger_messages) must be excluded from ORM schema management so a future
 * doctrine:migrations:diff never emits DROP TABLE for the rate-limiting / session stores.
 */
final class SchemaSyncTest extends KernelTestCase
{
    private function validator(): SchemaValidator
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return new SchemaValidator($em);
    }

    public function testMappingIsValid(): void
    {
        $errors = $this->validator()->validateMapping();

        self::assertSame(
            [],
            $errors,
            "Entity mapping is invalid:\n" . print_r($errors, true),
        );
    }

    public function testSchemaInSyncWithMetadata(): void
    {
        $diff = $this->validator()->getUpdateSchemaList();

        // The migrations-bundle bookkeeping table `doctrine_migration_versions` has no entity, so
        // the raw SchemaValidator wants to DROP it. The console excludes the migration table from
        // schema:validate on its own, and it is intentionally no longer in the DBAL schema_filter
        // (so migrate stays idempotent — FEATURE-129); replicate that console exclusion here
        // rather than re-hiding it globally. Real entity/table drift is still asserted in full.
        $diff = array_values(array_filter(
            $diff,
            static fn (string $sql): bool => !str_contains($sql, 'doctrine_migration_versions'),
        ));

        self::assertSame(
            [],
            $diff,
            "Database schema is not in sync with the entity metadata. Outstanding SQL:\n"
            . implode(";\n", $diff),
        );
    }
}
