<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AdminAccessTokenRepository;
use App\Repository\AdminRepository;
use App\Security\AdminApiTokenManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Issue #39: revoke a leaked or unused admin API token from the shell — one by id, or all of an admin's. */
#[AsCommand(
    name: 'app:admin:revoke-api-token',
    description: 'Revoke an admin API token (--id=N), or every token of one admin (--email=… --all)',
    help: <<<'HELP'
        Revoke one token:            php bin/console app:admin:revoke-api-token --id=12
        Revoke all of an admin's:    php bin/console app:admin:revoke-api-token --email=ops@example.com --all

        Find ids with app:admin:list-api-tokens. A revoked token is answered 401 immediately and stays revoked
        even if the admin is later reactivated.
        HELP,
)]
class RevokeAdminApiTokenCommand extends Command
{
    public function __construct(
        private readonly AdminAccessTokenRepository $tokens,
        private readonly AdminRepository $adminRepository,
        private readonly AdminApiTokenManager $tokenManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Token id (see app:admin:list-api-tokens)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email (with --all)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Revoke every token of --email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('id');
        $email = $input->getOption('email');
        $all = (bool) $input->getOption('all');

        $byId = $id !== null && $email === null && !$all;
        $byEmail = $id === null && $email !== null && $all;
        if (!$byId && !$byEmail) {
            $output->writeln('<error>Pass either --id=N, or --email=… --all.</error>');
            return Command::FAILURE;
        }

        if ($id !== null) {
            $token = ctype_digit((string) $id) ? $this->tokens->find((int) $id) : null;
            if ($token === null) {
                $output->writeln(sprintf('<error>No admin API token with id %s.</error>', (string) $id));
                return Command::FAILURE;
            }
            $ownerEmail = $this->adminRepository->find($token->getAdminId())?->getEmail() ?? '(deleted admin)';
            if (!$this->tokenManager->revoke($token, $ownerEmail, 'console', 'cli')) {
                $output->writeln(sprintf('Token %d (%s) was already revoked.', (int) $token->getId(), $token->getName()));
                return Command::SUCCESS;
            }
            $output->writeln(sprintf('<info>Revoked token %d "%s" of %s.</info>', (int) $token->getId(), $token->getName(), $ownerEmail));

            return Command::SUCCESS;
        }

        $admin = $this->adminRepository->findByEmail((string) $email);
        if ($admin === null) {
            $output->writeln('<error>No admin account found for this email.</error>');
            return Command::FAILURE;
        }
        $count = $this->tokenManager->revokeAllFor($admin, 'console', 'cli', 'cli');
        $output->writeln(sprintf('<info>Revoked %d token(s) of %s.</info>', $count, $admin->getEmail()));

        return Command::SUCCESS;
    }
}
