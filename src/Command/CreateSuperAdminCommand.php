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

#[AsCommand(
    name: 'app:create-superadmin',
    description: 'Provision a superadmin account',
    help: <<<'HELP'
        Creates an admin with ROLE_SUPER_ADMIN.

        For a brand-new instance, provision the FIRST admin with
        <info>app:create-tech-support</info> instead: ROLE_TECH_SUPPORT has full control of the
        instance (superadmin powers plus more, via role_hierarchy) and can create every other
        admin — including superadmins — from the UI afterward. Use this command for additional
        client-facing superadmins once that maintainer account exists.
        HELP,
)]
class CreateSuperAdminCommand extends Command
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
        $admin->setName('Superadmin');
        $admin->setRoles(['ROLE_SUPER_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $plaintextPassword));

        $this->em->persist($admin);
        $this->em->flush();

        if ($generated) {
            $output->writeln('<info>Superadmin created.</info>');
            $output->writeln("Auto-generated password: {$plaintextPassword}");
        } else {
            $output->writeln('<info>Superadmin created successfully.</info>');
        }

        return Command::SUCCESS;
    }
}
