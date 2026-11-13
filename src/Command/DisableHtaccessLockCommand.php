<?php

declare(strict_types=1);

namespace App\Command;

use App\Htaccess\HtaccessLockActor;
use App\Htaccess\HtaccessLockGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console recovery for the Htaccess Lock (ADR-059): the admin UI that controls the lock is itself behind
 * it, so if an admin's IP changes the way back in is the shell, not the panel. Removes the managed block
 * from `.htaccess` and records the lock as disabled; the saved whitelist is kept. Goes through the same
 * gate as the admin page and the API (ADR-062), so it is audited and fires the change event like they do.
 */
#[AsCommand(
    name: 'app:htaccess-lock:disable',
    description: 'Switch the Htaccess Lock off (removes its block from .htaccess) — lockout recovery',
    help: <<<'HELP'
        Removes the Htaccess Lock's managed block from the site's .htaccess and marks the lock disabled.
        Everything else in .htaccess is left untouched, and the saved whitelist is kept for next time.

        Use this from the server shell if the lock has locked you out of the admin panel.
        HELP,
)]
class DisableHtaccessLockCommand extends Command
{
    public function __construct(private readonly HtaccessLockGate $gate)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = HtaccessLockActor::console();
        $path = $this->gate->view($actor)->filePath;

        $result = $this->gate->disable($actor);
        if (!$result->isApplied()) {
            $output->writeln('<error>' . implode(' ', $result->errors) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Htaccess Lock disabled; its block was removed from %s.</info>', $path));

        return Command::SUCCESS;
    }
}
