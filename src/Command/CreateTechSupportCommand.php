<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Provisions a maintainer (ROLE_TECH_SUPPORT) admin — the console is the bootstrap path,
 * mirroring app:create-superadmin (ADR-004). Kept as a SEPARATE command rather than a `--role` flag
 * on app:create-superadmin so the two roles' options never drift into a shared, easy-to-misuse
 * surface — NOT to hide this command's existence, which is discoverable via `bin/console list` and
 * pointed to from app:create-superadmin's own --help (ADR-050 amendment, Ken 2026-09-28: obscuring
 * a deployment command bought no real security and only slowed down bootstrapping a new instance).
 * Existing tech-support admins can create more via the admin UI.
 *
 * This is the command to run FIRST on a brand-new instance: ROLE_TECH_SUPPORT has full control
 * (superadmin powers plus more, via role_hierarchy) and can create every other admin — including
 * superadmins — from the UI afterward.
 */
#[AsCommand(
    name: 'app:create-tech-support',
    description: 'Provision the first admin on a new instance (full control; run this before app:create-superadmin)',
    help: <<<'HELP'
        Creates an admin with ROLE_TECH_SUPPORT — full control of the instance (superadmin powers
        plus more, via role_hierarchy). This is the command to run FIRST when bootstrapping a
        brand-new instance; use the admin UI (or <info>app:create-superadmin</info>) to create
        client-facing superadmins afterward.

        Tech-support accounts are hidden from other admins' staff-list views (clients should not
        see maintainer accounts among their own staff), but this command itself is not secret —
        see `bin/console list`.
        HELP,
)]
class CreateTechSupportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminRepository $adminRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email address')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Admin password (auto-generated if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $output->writeln('<error>--email is required.</error>');
            return Command::FAILURE;
        }

        if ($this->adminRepository->findByEmail($email) !== null) {
            $output->writeln('<error>An admin with this email already exists.</error>');
            return Command::FAILURE;
        }

        $plaintextPassword = $input->getOption('password');
        $generated = false;
        if (!$plaintextPassword) {
            $plaintextPassword = bin2hex(random_bytes(16));
            $generated = true;
        }

        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Tech Support');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $plaintextPassword));

        $this->em->persist($admin);
        $this->em->flush();

        if ($generated) {
            $output->writeln('<info>Tech-support admin created.</info>');
            $output->writeln("Auto-generated password: {$plaintextPassword}");
        } else {
            $output->writeln('<info>Tech-support admin created successfully.</info>');
        }

        return Command::SUCCESS;
    }
}
