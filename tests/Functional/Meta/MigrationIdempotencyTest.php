<?php

declare(strict_types=1);

namespace App\Tests\Functional\Meta;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Guards FEATURE-137 AC3: "the migration step is idempotent on a populated test.db".
 *
 * The verify gate (bin/verify-fast.sh) runs `doctrine:migrations:migrate` before each
 * suite. That step is only safe if, on an already-migrated database, migrate is a no-op
 * that exits 0 (rather than re-applying DDL and erroring on existing tables). This test
 * runs the real command against the suite's test DB — which is already at the latest
 * version by the time PHPUnit runs — and asserts it reports up-to-date and exits cleanly.
 *
 * It goes RED if a future migration stops being a safe no-op on an up-to-date DB, which
 * is precisely the class of change that would reintroduce a non-repeatable gate.
 */
final class MigrationIdempotencyTest extends KernelTestCase
{
    public function testMigrateOnAlreadyMigratedDatabaseIsANoOpAndExitsZero(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $tester = new CommandTester($application->find('doctrine:migrations:migrate'));
        $exitCode = $tester->execute(
            ['--allow-no-migration' => true],
            ['interactive' => false],
        );

        $this->assertSame(0, $exitCode, 'Re-running migrations on a populated test DB must exit 0.');
        $this->assertStringContainsStringIgnoringCase(
            'latest version',
            $tester->getDisplay(),
            'Migrate on an up-to-date DB must report it is already at the latest version (a no-op).',
        );
    }

    public function testSchemaIsUpToDateWithNoPendingMigrations(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $tester = new CommandTester($application->find('doctrine:migrations:up-to-date'));
        $exitCode = $tester->execute([], ['interactive' => false]);

        $this->assertSame(
            0,
            $exitCode,
            'The test DB schema must be fully migrated with no pending migrations (repeatable clean state).',
        );
    }
}
