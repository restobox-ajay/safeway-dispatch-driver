<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    private function makeAdmin(string $email = 'admin@example.com'): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Admin User');
        $admin->setPassword('$2y$04$hashed');
        return $admin;
    }

    public function testFindByEmailReturnsAdminWhenExists(): void
    {
        $admin = $this->makeAdmin('find@example.com');
        $this->em->persist($admin);
        $this->em->flush();

        /** @var AdminRepository $repo */
        $repo = self::getContainer()->get(AdminRepository::class);
        $found = $repo->findByEmail('find@example.com');

        $this->assertNotNull($found);
        $this->assertSame('find@example.com', $found->getEmail());
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        /** @var AdminRepository $repo */
        $repo = self::getContainer()->get(AdminRepository::class);
        $found = $repo->findByEmail('nobody@example.com');

        $this->assertNull($found);
    }

    public function testAdminIsPersisted(): void
    {
        $admin = $this->makeAdmin('persist@example.com');
        $admin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();

        $this->em->clear();

        /** @var AdminRepository $repo */
        $repo = self::getContainer()->get(AdminRepository::class);
        $found = $repo->findByEmail('persist@example.com');

        $this->assertNotNull($found);
        $this->assertContains('ROLE_SUPER_ADMIN', $found->getRoles());
        $this->assertContains('ROLE_ADMIN', $found->getRoles());
        $this->assertNotNull($found->getCreatedAt());
    }

    public function testAdminTableExistsViaSuccessfulPersist(): void
    {
        $admin = $this->makeAdmin('tablecheck@example.com');
        $this->em->persist($admin);
        $this->em->flush();

        $this->assertNotNull($admin->getId());
    }
}
