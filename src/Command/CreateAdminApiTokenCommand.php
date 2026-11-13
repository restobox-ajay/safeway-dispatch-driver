<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AdminRepository;
use App\Security\AdminApiTokenManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:admin:create-api-token',
    description: 'Issue a bearer token for the admin REST API (/admin-api), bound to an Admin account',
    help: <<<'HELP'
        Issues a token for the admin REST API. The plaintext is printed ONCE; only its SHA-256 hash and its
        last 6 characters (to tell tokens apart when revoking) are stored.

        Tokens expire after 365 days unless you pass --expires-in-days=N or, deliberately, --no-expiry.
        List tokens with app:admin:list-api-tokens and revoke one with app:admin:revoke-api-token.
        HELP,
)]
class CreateAdminApiTokenCommand extends Command
{
    public function __construct(
        private readonly AdminRepository $adminRepository,
        private readonly AdminApiTokenManager $tokenManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email the token is issued to')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Label for the token', 'Admin API Token')
            ->addOption('expires-in-days', null, InputOption::VALUE_REQUIRED, 'Lifetime in days', (string) AdminApiTokenManager::DEFAULT_LIFETIME_DAYS)
            ->addOption('no-expiry', null, InputOption::VALUE_NONE, 'Issue a token that never expires (not recommended)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $output->writeln('<error>--email is required.</error>');
            return Command::FAILURE;
        }

        $admin = $this->adminRepository->findByEmail($email);
        if ($admin === null) {
            $output->writeln('<error>No admin account found for this email.</error>');
            return Command::FAILURE;
        }

        $expiresAt = null;
        if (!$input->getOption('no-expiry')) {
            $days = (string) $input->getOption('expires-in-days');
            if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 3650) {
                $output->writeln('<error>--expires-in-days must be a whole number from 1 to 3650 (or use --no-expiry).</error>');
                return Command::FAILURE;
            }
            $expiresAt = (new \DateTimeImmutable())->modify('+' . (int) $days . ' days');
        }

        $issued = $this->tokenManager->issue($admin, (string) $input->getOption('name'), $expiresAt, 'console', 'cli');
        $token = $issued['token'];

        $output->writeln('<info>Admin API token created.</info>');
        $output->writeln('Token (shown once, store it now): ' . $issued['plaintext']);
        $output->writeln(sprintf(
            'Id: %d · ends in …%s · expires: %s',
            (int) $token->getId(),
            (string) $token->getTokenHint(),
            $expiresAt?->format('Y-m-d H:i') ?? 'never',
        ));

        return Command::SUCCESS;
    }
}
