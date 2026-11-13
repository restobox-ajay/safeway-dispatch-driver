<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SuperadminRoleTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
        $this->createTestAdmins();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (['superadmin-f13@example.com', 'regularadmin-f13@example.com'] as $email) {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
            }
        }
    }

    private function createTestAdmins(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $superAdmin = new Admin();
        $superAdmin->setEmail('superadmin-f13@example.com');
        $superAdmin->setName('Super Admin F13');
        $superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $superAdmin->setPassword($hasher->hashPassword($superAdmin, 'superpassword'));
        $this->em->persist($superAdmin);

        $regularAdmin = new Admin();
        $regularAdmin->setEmail('regularadmin-f13@example.com');
        $regularAdmin->setName('Regular Admin F13');
        $regularAdmin->setRoles([]);
        $regularAdmin->setPassword($hasher->hashPassword($regularAdmin, 'adminpassword'));
        $this->em->persist($regularAdmin);

        $this->em->flush();
        $this->em->clear();
    }


    public function testSuperAdminCanAccessAdminDashboard(): void
    {
        $this->loginAsAdmin('superadmin-f13@example.com', 'superpassword', false);
        $this->assertResponseStatusCodeSame(302);

        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
    }

    public function testSuperAdminCanAccessSuperAdminOnlyRoute(): void
    {
        $this->loginAsAdmin('superadmin-f13@example.com', 'superpassword', false);
        $this->client->request('GET', '/admin/superadmin');
        $this->assertResponseIsSuccessful();
    }

    public function testRegularAdminCannotAccessSuperAdminOnlyRouteAndGets403(): void
    {
        $this->loginAsAdmin('regularadmin-f13@example.com', 'adminpassword', false);
        $this->assertResponseStatusCodeSame(302);

        $this->client->request('GET', '/admin/superadmin');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testRoleHierarchySuperAdminInheritsAdminPrivileges(): void
    {
        // Superadmin entity has ROLE_SUPER_ADMIN in roles
        $superAdmin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'superadmin-f13@example.com']);
        $this->assertNotNull($superAdmin);
        $this->assertContains('ROLE_SUPER_ADMIN', $superAdmin->getRoles());

        // Via Symfony role hierarchy, superadmin also inherits ROLE_ADMIN → can access /admin/* routes
        $this->loginAsAdmin('superadmin-f13@example.com', 'superpassword', false);
        $this->client->request('GET', '/admin/dashboard');
        $this->assertResponseIsSuccessful();
    }
}
