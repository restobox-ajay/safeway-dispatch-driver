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

/**
 * Changes an EXISTING admin's role from the shell (app:create-superadmin / app:create-tech-support
 * only provision new accounts). Mirrors the admin-edit role change in AdminAdminManagementController,
 * including its anti-lockout guard (FEATURE-149 / ADR-050): the last active superadmin cannot be
 * demoted this way either.
 */
#[AsCommand(
    name: 'app:admin:set-role',
    description: "Change an existing admin's role",
    help: <<<'HELP'
        php bin/console app:admin:set-role --email=admin@example.com --role=ROLE_TECH_SUPPORT

        Valid --role values: ROLE_ADMIN, ROLE_SUPER_ADMIN, ROLE_TECH_SUPPORT.

        Refuses to strip ROLE_SUPER_ADMIN from the last active superadmin, the same guard the admin
        panel's edit screen enforces.
        HELP,
)]
class SetAdminRoleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminRepository $adminRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email address')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'New role (see --help for valid values)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $output->writeln('<error>--email is required.</error>');
            return Command::FAILURE;
        }

        $role = $input->getOption('role');
        if (!$role) {
            $output->writeln('<error>--role is required.</error>');
            return Command::FAILURE;
        }

        if (!in_array($role, Admin::ALLOWED_ROLES, true)) {
            $output->writeln(sprintf(
                '<error>Unknown role "%s". Valid roles: %s.</error>',
                $role,
                implode(', ', Admin::ALLOWED_ROLES)
            ));
            return Command::FAILURE;
        }

        $admin = $this->adminRepository->findByEmail($email);
        if ($admin === null) {
            $output->writeln('<error>No admin account found for this email.</error>');
            return Command::FAILURE;
        }

        $losesSuperAdmin = $admin->isActive()
            && in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true)
            && $role !== 'ROLE_SUPER_ADMIN';

        if ($losesSuperAdmin && $this->adminRepository->countActiveSuperAdmins() <= 1) {
            $output->writeln('<error>This is the last active superadmin — its superadmin role cannot be removed.</error>');
            return Command::FAILURE;
        }

        $admin->setRoles([$role]);
        $this->em->flush();

        $output->writeln(sprintf('<info>%s now has role %s.</info>', $admin->getEmail(), $role));

        return Command::SUCCESS;
    }
}
