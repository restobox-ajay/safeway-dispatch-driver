<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserTest extends TestCase
{
    public function testUserImplementsUserInterface(): void
    {
        $user = new User();
        $this->assertInstanceOf(UserInterface::class, $user);
    }

    public function testUserImplementsPasswordAuthenticatedUserInterface(): void
    {
        $user = new User();
        $this->assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testGetRolesDeduplicatesRoleUser(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_EDITOR']);

        $roles = $user->getRoles();
        $this->assertCount(1, array_keys($roles, 'ROLE_USER'));
    }

    public function testEmailGetterSetter(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $this->assertSame('test@example.com', $user->getEmail());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('id@example.com');
        $this->assertSame('id@example.com', $user->getUserIdentifier());
    }

    public function testPasswordGetterSetter(): void
    {
        $user = new User();
        $user->setPassword('$2y$hashed');
        $this->assertSame('$2y$hashed', $user->getPassword());
    }

    public function testNameGetterSetter(): void
    {
        $user = new User();
        $user->setName('Alice');
        $this->assertSame('Alice', $user->getName());
    }

    public function testDefaultStatusIsActive(): void
    {
        $user = new User();
        $this->assertSame('active', $user->getStatus());
    }

    public function testStatusGetterSetter(): void
    {
        $user = new User();
        $user->setStatus('inactive');
        $this->assertSame('inactive', $user->getStatus());
    }

    public function testSetStatusAcceptsActive(): void
    {
        $user = new User();
        $user->setStatus('inactive');
        $user->setStatus('active');
        $this->assertSame('active', $user->getStatus());
    }

    public function testSetStatusRejectsUnknownValue(): void
    {
        $user = new User();
        $this->expectException(\InvalidArgumentException::class);
        $user->setStatus('banned');
    }

    public function testSetStatusRejectionDoesNotMutate(): void
    {
        $user = new User();
        try {
            $user->setStatus('deleted');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // status must be unchanged from its default after a rejected write
            $this->assertSame('active', $user->getStatus());
        }
    }

    public function testCreatedAtIsSetInConstructor(): void
    {
        $before = new \DateTimeImmutable();
        $user = new User();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $user->getCreatedAt());
        $this->assertLessThanOrEqual($after, $user->getCreatedAt());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new User();
        $user->setPassword('secret');
        $user->eraseCredentials();
        // Password must still be present — no plaintext to erase
        $this->assertSame('secret', $user->getPassword());
    }
}
