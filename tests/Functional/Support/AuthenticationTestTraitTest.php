<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Proves the shared AuthenticationTestTrait end-to-end (FEATURE-130 / review C41): the create
 * helpers produce authenticatable accounts and the login helpers reach the protected areas.
 */
final class AuthenticationTestTraitTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', ['trait-fixture-%@example.com']);
            $conn->executeStatement('DELETE FROM admin WHERE email LIKE ?', ['trait-fixture-%@example.com']);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    public function testCreateTestUserProducesActiveHashedAccount(): void
    {
        $user = $this->createTestUser('trait-fixture-user@example.com', 'Trait User');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('active', $user->getStatus());
        $this->assertTrue(password_verify('testpassword', $user->getPassword()));
    }

    public function testLoginUserHelperAuthenticatesOnUserFirewall(): void
    {
        $this->createTestUser('trait-fixture-user@example.com', 'Trait User');

        $this->loginUser('trait-fixture-user@example.com');
        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public function testCreateTestAdminProducesActiveHashedAccount(): void
    {
        $admin = $this->createTestAdmin('trait-fixture-admin@example.com', 'Trait Admin');

        $this->assertInstanceOf(Admin::class, $admin);
        $this->assertSame('active', $admin->getStatus());
        $this->assertTrue(password_verify('adminpass', $admin->getPassword()));
    }

    public function testLoginAsAdminHelperAuthenticatesOnAdminFirewall(): void
    {
        $this->createTestAdmin('trait-fixture-admin@example.com', 'Trait Admin');

        $this->loginAsAdmin('trait-fixture-admin@example.com');
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseIsSuccessful();
    }
}
