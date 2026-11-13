<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * app:admin:set-role — changes an EXISTING admin's role from the shell, the console counterpart
 * to the admin-edit role change in AdminAdminManagementController (FEATURE-149 / ADR-050 guard parity).
 */
final class SetAdminRoleCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AdminRepository $adminRepo;

    private const EMAIL_A = 'cmd-setrole-a@example.com';
    private const EMAIL_B = 'cmd-setrole-b@example.com';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->adminRepo = self::getContainer()->get(AdminRepository::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach ([self::EMAIL_A, self::EMAIL_B] as $email) {
            $admin = $this->adminRepo->findByEmail($email);
            if ($admin) {
                $this->em->remove($admin);
            }
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function createAdmin(string $email, string $role, bool $active = true): Admin
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Test Admin');
        $admin->setRoles([$role]);
        $admin->setStatus($active ? 'active' : 'inactive');
        $admin->setPassword($hasher->hashPassword($admin, 'irrelevant-password'));
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:admin:set-role'));
    }

    public function testPromotesAdminToTechSupport(): void
    {
        $this->createAdmin(self::EMAIL_A, 'ROLE_ADMIN');

        $tester = $this->tester();
        $exit = $tester->execute(['--email' => self::EMAIL_A, '--role' => 'ROLE_TECH_SUPPORT']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('now has role ROLE_TECH_SUPPORT', $tester->getDisplay());

        $this->em->clear();
        $admin = $this->adminRepo->findByEmail(self::EMAIL_A);
        self::assertContains('ROLE_TECH_SUPPORT', $admin->getRoles());
    }

    public function testRejectsUnknownRole(): void
    {
        $this->createAdmin(self::EMAIL_A, 'ROLE_ADMIN');

        $tester = $this->tester();
        $exit = $tester->execute(['--email' => self::EMAIL_A, '--role' => 'ROLE_NOT_A_REAL_ROLE']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown role', $tester->getDisplay());

        $this->em->clear();
        $admin = $this->adminRepo->findByEmail(self::EMAIL_A);
        self::assertNotContains('ROLE_NOT_A_REAL_ROLE', $admin->getRoles());
    }

    public function testFailsForUnknownEmail(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--email' => 'nobody@example.com', '--role' => 'ROLE_ADMIN']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No admin account found', $tester->getDisplay());
    }

    public function testRequiresEmailAndRole(): void
    {
        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['--role' => 'ROLE_ADMIN']));
        self::assertStringContainsString('--email is required.', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--email' => self::EMAIL_A]));
        self::assertStringContainsString('--role is required.', $tester->getDisplay());
    }

    public function testRefusesToDemoteLastActiveSuperAdmin(): void
    {
        $this->createAdmin(self::EMAIL_A, 'ROLE_SUPER_ADMIN');

        $tester = $this->tester();
        $exit = $tester->execute(['--email' => self::EMAIL_A, '--role' => 'ROLE_ADMIN']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('last active superadmin', $tester->getDisplay());

        $this->em->clear();
        $admin = $this->adminRepo->findByEmail(self::EMAIL_A);
        self::assertContains('ROLE_SUPER_ADMIN', $admin->getRoles());
    }

    public function testAllowsDemotingASuperAdminWhenAnotherOneExists(): void
    {
        $this->createAdmin(self::EMAIL_A, 'ROLE_SUPER_ADMIN');
        $this->createAdmin(self::EMAIL_B, 'ROLE_SUPER_ADMIN');

        $tester = $this->tester();
        $exit = $tester->execute(['--email' => self::EMAIL_A, '--role' => 'ROLE_ADMIN']);

        self::assertSame(0, $exit);

        $this->em->clear();
        $admin = $this->adminRepo->findByEmail(self::EMAIL_A);
        self::assertNotContains('ROLE_SUPER_ADMIN', $admin->getRoles());
    }

    public function testAnInactiveSuperAdminDoesNotBlockTheGuard(): void
    {
        // Inactive superadmins are not "active", so they do not count toward the guard, and
        // demoting them is never the lockout case (mirrors AdminAdminManagementController::edit()).
        $this->createAdmin(self::EMAIL_A, 'ROLE_SUPER_ADMIN', active: false);

        $tester = $this->tester();
        $exit = $tester->execute(['--email' => self::EMAIL_A, '--role' => 'ROLE_ADMIN']);

        self::assertSame(0, $exit);
    }
}
