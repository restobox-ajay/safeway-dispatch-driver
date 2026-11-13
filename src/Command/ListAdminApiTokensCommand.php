<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminAccessToken;
use App\Repository\AdminAccessTokenRepository;
use App\Repository\AdminRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Issue #39: see which admin API tokens exist (and their ids) before revoking one. Never prints a secret. */
#[AsCommand(
    name: 'app:admin:list-api-tokens',
    description: 'List admin API tokens (id, owner, name, last 6 characters, status) — for revoking one',
)]
class ListAdminApiTokensCommand extends Command
{
    public function __construct(
        private readonly AdminAccessTokenRepository $tokens,
        private readonly AdminRepository $adminRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Only this admin\'s tokens')
            ->addOption('active', null, InputOption::VALUE_NONE, 'Only tokens that still work (not revoked, not expired)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if ($email !== null) {
            $admin = $this->adminRepository->findByEmail((string) $email);
            if ($admin === null) {
                $output->writeln('<error>No admin account found for this email.</error>');
                return Command::FAILURE;
            }
            $tokens = $this->tokens->findAllByAdminId((int) $admin->getId());
        } else {
            $tokens = $this->tokens->findAllNewestFirst();
        }
        if ($input->getOption('active')) {
            $tokens = array_values(array_filter($tokens, static fn (AdminAccessToken $t): bool => $t->isActive()));
        }

        if ($tokens === []) {
            $output->writeln('No admin API tokens.');
            return Command::SUCCESS;
        }

        $emails = [];
        $rows = [];
        foreach ($tokens as $token) {
            $emails[$token->getAdminId()] ??= $this->adminRepository->find($token->getAdminId())?->getEmail() ?? '(deleted admin #' . $token->getAdminId() . ')';
            $rows[] = [
                $token->getId(),
                $emails[$token->getAdminId()],
                $token->getName(),
                $token->getTokenHint() !== null ? '…' . $token->getTokenHint() : '(not recorded)',
                $token->getCreatedAt()->format('Y-m-d H:i'),
                $token->getExpiresAt()?->format('Y-m-d H:i') ?? 'never',
                $token->getLastUsedAt()?->format('Y-m-d H:i') ?? 'never',
                self::status($token),
            ];
        }

        (new Table($output))
            ->setHeaders(['Id', 'Admin', 'Name', 'Ends in', 'Created', 'Expires', 'Last used', 'Status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    public static function status(AdminAccessToken $token): string
    {
        return match (true) {
            $token->isRevoked() => 'revoked',
            $token->isExpired() => 'expired',
            default => 'active',
        };
    }
}
