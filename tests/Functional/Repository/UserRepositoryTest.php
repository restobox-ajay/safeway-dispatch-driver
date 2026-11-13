<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
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

    private function makeUser(string $email = 'alice@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Alice');
        $user->setPassword('$2y$04$hashed');
        return $user;
    }

    public function testFindByEmailReturnsUserWhenExists(): void
    {
        $user = $this->makeUser('find@example.com');
        $this->em->persist($user);
        $this->em->flush();

        /** @var UserRepository $repo */
        $repo = self::getContainer()->get(UserRepository::class);
        $found = $repo->findByEmail('find@example.com');

        $this->assertNotNull($found);
        $this->assertSame('find@example.com', $found->getEmail());
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        /** @var UserRepository $repo */
        $repo = self::getContainer()->get(UserRepository::class);
        $found = $repo->findByEmail('nobody@example.com');

        $this->assertNull($found);
    }

    public function testUserIsPersisted(): void
    {
        $user = $this->makeUser('persist@example.com');
        $user->setStatus('inactive');
        // ROLE_EDITOR is not on User::ALLOWED_ROLES, so the allowlist drops it on write.
        // (Downstream apps extend User::ALLOWED_ROLES to permit additional user-tier roles.)
        $user->setRoles(['ROLE_EDITOR']);
        $this->em->persist($user);
        $this->em->flush();

        $this->em->clear();

        /** @var UserRepository $repo */
        $repo = self::getContainer()->get(UserRepository::class);
        $found = $repo->findByEmail('persist@example.com');

        $this->assertNotNull($found);
        $this->assertSame('inactive', $found->getStatus());
        $this->assertNotContains('ROLE_EDITOR', $found->getRoles(), 'Roles outside the allowlist are dropped.');
        $this->assertContains('ROLE_USER', $found->getRoles());
        $this->assertNotNull($found->getCreatedAt());
    }

    public function testUserTableExistsViaSuccessfulPersist(): void
    {
        $user = $this->makeUser('tablecheck@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->assertNotNull($user->getId());
    }
}
