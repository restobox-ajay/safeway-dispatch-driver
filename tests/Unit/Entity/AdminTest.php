<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Admin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdminTest extends TestCase
{
    public function testAdminImplementsUserInterface(): void
    {
        $admin = new Admin();
        $this->assertInstanceOf(UserInterface::class, $admin);
    }

    public function testAdminImplementsPasswordAuthenticatedUserInterface(): void
    {
        $admin = new Admin();
        $this->assertInstanceOf(PasswordAuthenticatedUserInterface::class, $admin);
    }

    public function testGetRolesAlwaysIncludesRoleAdmin(): void
    {
        $admin = new Admin();
        $this->assertContains('ROLE_ADMIN', $admin->getRoles());
    }

    public function testGetRolesDeduplicatesRoleAdmin(): void
    {
        $admin = new Admin();
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);

        $roles = $admin->getRoles();
        $this->assertCount(1, array_keys($roles, 'ROLE_ADMIN'));
    }

    public function testGetRolesCanIncludeRoleSuperAdmin(): void
    {
        $admin = new Admin();
        $admin->setRoles(['ROLE_SUPER_ADMIN']);

        $this->assertContains('ROLE_SUPER_ADMIN', $admin->getRoles());
        $this->assertContains('ROLE_ADMIN', $admin->getRoles());
    }

    public function testEmailGetterSetter(): void
    {
        $admin = new Admin();
        $admin->setEmail('admin@example.com');
        $this->assertSame('admin@example.com', $admin->getEmail());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $admin = new Admin();
        $admin->setEmail('id@example.com');
        $this->assertSame('id@example.com', $admin->getUserIdentifier());
    }

    public function testPasswordGetterSetter(): void
    {
        $admin = new Admin();
        $admin->setPassword('$2y$hashed');
        $this->assertSame('$2y$hashed', $admin->getPassword());
    }

    public function testNameGetterSetter(): void
    {
        $admin = new Admin();
        $admin->setName('Bob');
        $this->assertSame('Bob', $admin->getName());
    }

    public function testCreatedAtIsSetInConstructor(): void
    {
        $before = new \DateTimeImmutable();
        $admin = new Admin();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $admin->getCreatedAt());
        $this->assertLessThanOrEqual($after, $admin->getCreatedAt());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $admin = new Admin();
        $admin->setPassword('secret');
        $admin->eraseCredentials();
        $this->assertSame('secret', $admin->getPassword());
    }

    public function testAdminIsNotInstanceOfUser(): void
    {
        $admin = new Admin();
        $this->assertNotInstanceOf(\App\Entity\User::class, $admin);
    }

    public function testDefaultStatusIsActive(): void
    {
        $admin = new Admin();
        $this->assertSame('active', $admin->getStatus());
        $this->assertTrue($admin->isActive());
    }

    public function testSetStatusAcceptsAllowedValues(): void
    {
        $admin = new Admin();
        $admin->setStatus('inactive');
        $this->assertSame('inactive', $admin->getStatus());
        $this->assertFalse($admin->isActive());

        $admin->setStatus('active');
        $this->assertSame('active', $admin->getStatus());
    }

    public function testSetStatusRejectsUnknownValue(): void
    {
        $admin = new Admin();
        $this->expectException(\InvalidArgumentException::class);
        $admin->setStatus('superuser');
    }
}
