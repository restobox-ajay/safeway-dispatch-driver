<?php

declare(strict_types=1);

namespace App\Command;

use App\Prune\PrunerInterface;
use App\Service\AuditLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The unified prune harness (FEATURE-147 / ADR-048). Collects every PrunerInterface contributor tagged
 * `auth.pruner` (core pruners + bundle pruners, the latter present only when their bundle is registered)
 * and runs them under one command, superseding the two bare commands app:audit-log:prune (ADR-008) and
 * app:maintenance:prune (ADR-020).
 *
 * Each pruner owns its domain predicate + retention config; this harness owns the infrastructure:
 * deterministic ordering, --dry-run, --only filtering, per-pruner error isolation, and a single audit row.
 */
#[AsCommand(
    name: 'app:prune',
    description: 'Prune expired/retention-exceeded rows across all registered pruners (audit log, ephemeral tokens, sessions, invitations, and bundle-owned tables)',
)]
final class PruneCommand extends Command
{
    /**
     * @param iterable<PrunerInterface> $pruners
     */
    public function __construct(
        #[AutowireIterator('auth.pruner')] private readonly iterable $pruners,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what WOULD be pruned without deleting anything or writing an audit row.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only the named pruner(s); repeatable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        // Materialize + sort by name for deterministic output; reject duplicate names.
        $pruners = [];
        foreach ($this->pruners as $pruner) {
            $name = $pruner->name();
            if (isset($pruners[$name])) {
                throw new \LogicException(sprintf('Duplicate pruner name "%s".', $name));
            }
            $pruners[$name] = $pruner;
        }
        ksort($pruners);

        $only = $input->getOption('only');
        if ($only !== []) {
            $unknown = array_diff($only, array_keys($pruners));
            if ($unknown !== []) {
                $output->writeln(sprintf('<error>Unknown pruner(s): %s</error>', implode(', ', $unknown)));
                $output->writeln('Available: ' . implode(', ', array_keys($pruners)));

                return Command::FAILURE;
            }
            $pruners = array_intersect_key($pruners, array_flip($only));
        }

        $now = new \DateTimeImmutable();
        $output->writeln($dryRun ? 'Prune (dry-run) — rows that WOULD be deleted:' : 'Pruned:');

        $total = 0;
        $failed = [];
        $detail = [];
        foreach ($pruners as $name => $pruner) {
            try {
                $rows = $dryRun ? $pruner->count($now) : $pruner->prune($now);
                $total += $rows;
                $detail[] = "{$name}={$rows}";
                $output->writeln("  {$name}: {$rows}");
            } catch (\Throwable $e) {
                $failed[] = $name;
                $detail[] = "{$name}=FAILED";
                $output->writeln(sprintf('<error>  %s: FAILED (%s)</error>', $name, $e->getMessage()));
            }
        }

        $output->writeln(sprintf('%s %d rows across %d pruners.', $dryRun ? 'Would prune' : 'Pruned', $total, count($pruners)));
        if ($failed !== []) {
            $output->writeln(sprintf('<error>%d pruner(s) failed: %s</error>', count($failed), implode(', ', $failed)));
        }

        // Audit only a real run that actually did something (deleted rows) or hit a failure. An idle
        // run and every dry-run write no row (dry-run never mutates and never audit-logs — AC3/AC8).
        if (!$dryRun && ($total > 0 || $failed !== [])) {
            $this->auditLogger->log(
                'app:prune',
                'admin',
                'cli',
                'maintenance.prune',
                $failed === [] ? 'success' : 'failure',
                implode(', ', $detail),
            );
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
