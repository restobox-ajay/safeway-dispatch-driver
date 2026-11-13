<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:create-tech-support — console bootstrap for the hidden maintainer tier
 * (ADR-050 / FEATURE-149), mirroring app:create-superadmin (ADR-004).
 */
final class CreateTechSupportCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AdminRepository $adminRepo;

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
        $admin = $this->adminRepo->findByEmail('cmd-techsupport@example.com');
        if ($admin) {
            $this->em->remove($admin);
            $this->em->flush();
        }
        $this->em->clear();
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:create-tech-support'));
    }

    public function testCreatesActiveTechSupportAdminWithGeneratedPassword(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--email' => 'cmd-techsupport@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Tech-support admin created.', $tester->getDisplay());
        self::assertStringContainsString('Auto-generated password:', $tester->getDisplay());

        $admin = $this->adminRepo->findByEmail('cmd-techsupport@example.com');
        self::assertNotNull($admin);
        self::assertContains('ROLE_TECH_SUPPORT', $admin->getRoles());
        self::assertTrue($admin->isActive());
    }

    public function testRefusesDuplicateEmail(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--email' => 'cmd-techsupport@example.com']));

        $exit = $tester->execute(['--email' => 'cmd-techsupport@example.com']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testRequiresEmail(): void
    {
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('--email is required.', $tester->getDisplay());
    }
}
