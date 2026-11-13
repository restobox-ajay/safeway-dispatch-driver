<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\Command;

use App\Service\AuditLogger;
use App\Service\ConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lockout recovery for the login IP whitelist (issue #17): if a bad admin list rejects every admin login, the
 * panel that would fix it is unreachable, so the way back in is the shell — like app:htaccess-lock:disable.
 * Clears the GLOBAL list(s) only; per-user overrides are untouched. Exists only while this bundle is registered.
 */
#[AsCommand(
    name: 'app:ip-whitelist:clear',
    description: 'Empty the global login IP whitelist (admin by default) — lockout recovery',
    help: <<<'HELP'
        Empties the admin login IP whitelist (an empty list allows every IP), so admins can sign in again and fix it
        in Admin → Config → IP Whitelist. The previous value is printed so it can be re-entered correctly.

          --scope=admin   the admin list (default)
          --scope=user    the user list
          --scope=all     both
        HELP,
)]
final class ClearIpWhitelistCommand extends Command
{
    private const KEYS = [
        'admin' => ['ip_whitelist.admin_ips'],
        'user' => ['ip_whitelist.user_ips'],
        'all' => ['ip_whitelist.admin_ips', 'ip_whitelist.user_ips'],
    ];

    public function __construct(
        private readonly ConfigService $config,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED, 'admin, user or all', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scope = (string) $input->getOption('scope');
        if (!isset(self::KEYS[$scope])) {
            $output->writeln('<error>--scope must be admin, user or all.</error>');
            return Command::FAILURE;
        }

        foreach (self::KEYS[$scope] as $key) {
            $previous = $this->config->getString($key);
            $this->config->set($key, '');
            $output->writeln(sprintf('<info>Cleared %s</info> (was: %s)', $key, $previous === '' ? 'empty' : $previous));
        }

        $this->auditLogger->log('console', 'admin', 'cli', 'admin.ip_whitelist_clear', 'success', 'scope=' . $scope);

        return Command::SUCCESS;
    }
}
