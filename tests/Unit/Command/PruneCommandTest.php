<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PruneCommand;
use App\Prune\PrunerInterface;
use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit-level coverage of the harness mechanics (FEATURE-147), driving PruneCommand directly with
 * anonymous-class stub pruners + a spy AuditLogger — no kernel, no DB. Repository/predicate behaviour
 * is exercised by the functional PruneCommandTest.
 */
final class PruneCommandTest extends TestCase
{
    private function pruner(string $name, int $count, int $pruned, ?\Throwable $throwOnPrune = null): PrunerInterface
    {
        return new class($name, $count, $pruned, $throwOnPrune) implements PrunerInterface {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(
                private string $n,
                private int $countValue,
                private int $prunedValue,
                private ?\Throwable $throwOnPrune,
            ) {}

            public function name(): string
            {
                return $this->n;
            }

            public function count(\DateTimeImmutable $now): int
            {
                $this->calls[] = 'count';

                return $this->countValue;
            }

            public function prune(\DateTimeImmutable $now): int
            {
                $this->calls[] = 'prune';
                if ($this->throwOnPrune !== null) {
                    throw $this->throwOnPrune;
                }

                return $this->prunedValue;
            }
        };
    }

    private function spyAuditLogger(): AuditLogger
    {
        return new class extends AuditLogger {
            /** @var list<array<int, mixed>> */
            public array $logged = [];

            public function __construct()
            {
                // Bypass parent ctor: this spy never touches the EntityManager.
            }

            public function log(string $actor, string $actorType, string $ip, string $action, string $outcome, ?string $context = null): void
            {
                $this->logged[] = [$actor, $actorType, $ip, $action, $outcome, $context];
            }
        };
    }

    /**
     * @param list<PrunerInterface> $pruners
     */
    private function tester(array $pruners, AuditLogger $auditLogger): CommandTester
    {
        // Construct the tester directly from the command (its options are set in configure(), called by
        // the Command constructor) — no Symfony\Console Application, whose add() is deprecated in 7.4.
        return new CommandTester(new PruneCommand($pruners, $auditLogger));
    }

    public function testRunsAllPrunersSortedByNameAndReportsTotal(): void
    {
        $audit = $this->spyAuditLogger();
        $tester = $this->tester([
            $this->pruner('zulu', 0, 2),
            $this->pruner('alpha', 0, 3),
        ], $audit);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        // alpha listed before zulu (sorted).
        $this->assertLessThan(strpos($display, 'zulu:'), strpos($display, 'alpha:'));
        $this->assertStringContainsString('alpha: 3', $display);
        $this->assertStringContainsString('zulu: 2', $display);
        $this->assertStringContainsString('Pruned 5 rows across 2 pruners.', $display);
        $this->assertCount(1, $audit->logged, 'a real run with total>0 writes exactly one audit row');
        $this->assertSame('maintenance.prune', $audit->logged[0][3]);
        $this->assertSame('success', $audit->logged[0][4]);
    }

    public function testDryRunCallsCountOnlyAndNeverAudits(): void
    {
        $audit = $this->spyAuditLogger();
        $p = $this->pruner('alpha', 7, 99);
        $tester = $this->tester([$p], $audit);

        $exit = $tester->execute(['--dry-run' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('alpha: 7', $display);
        $this->assertStringContainsString('Would prune 7 rows', $display);
        $this->assertSame(['count'], $p->calls, 'dry-run calls count() only, never prune()');
        $this->assertCount(0, $audit->logged, 'dry-run never audit-logs');
    }

    public function testOnlyFiltersToNamedPruners(): void
    {
        $audit = $this->spyAuditLogger();
        $alpha = $this->pruner('alpha', 0, 1);
        $bravo = $this->pruner('bravo', 0, 1);
        $tester = $this->tester([$alpha, $bravo], $audit);

        $tester->execute(['--only' => ['alpha']]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('alpha: 1', $display);
        $this->assertStringNotContainsString('bravo:', $display);
        $this->assertStringContainsString('across 1 pruners', $display);
        $this->assertSame(['prune'], $alpha->calls);
        $this->assertSame([], $bravo->calls, 'filtered-out pruner is never invoked');
    }

    public function testUnknownOnlyNameExitsOneListingAvailableNames(): void
    {
        $audit = $this->spyAuditLogger();
        $tester = $this->tester([$this->pruner('alpha', 0, 0)], $audit);

        $exit = $tester->execute(['--only' => ['bogus']]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown pruner(s): bogus', $display);
        $this->assertStringContainsString('Available: alpha', $display);
        $this->assertCount(0, $audit->logged);
    }

    public function testDuplicatePrunerNamesThrow(): void
    {
        $audit = $this->spyAuditLogger();
        $tester = $this->tester([
            $this->pruner('dup', 0, 0),
            $this->pruner('dup', 0, 0),
        ], $audit);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate pruner name "dup".');
        $tester->execute([]);
    }

    public function testThrowingPrunerIsIsolatedOthersRunAndExitIsFailure(): void
    {
        $audit = $this->spyAuditLogger();
        $boom = $this->pruner('boom', 0, 0, new \RuntimeException('kaboom'));
        $ok = $this->pruner('ok', 0, 4);
        $tester = $this->tester([$boom, $ok], $audit);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exit, 'overall exit is FAILURE when any pruner fails');
        $this->assertStringContainsString('boom: FAILED (kaboom)', $display);
        $this->assertStringContainsString('ok: 4', $display, 'the healthy pruner still runs');
        $this->assertCount(1, $audit->logged, 'a run with a failure writes one audit row');
        $this->assertSame('failure', $audit->logged[0][4]);
    }

    public function testIdleRealRunWritesNoAuditRow(): void
    {
        $audit = $this->spyAuditLogger();
        $tester = $this->tester([$this->pruner('alpha', 0, 0)], $audit);

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 0 rows', $tester->getDisplay());
        $this->assertCount(0, $audit->logged, 'an idle run (nothing deleted, no failure) writes no audit row');
    }
}
