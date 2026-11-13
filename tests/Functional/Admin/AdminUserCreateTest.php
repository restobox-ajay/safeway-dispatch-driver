<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserCreateTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestAdmin('usercreate-admin@example.com', 'Create Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                "DELETE FROM \"user\" WHERE email LIKE 'usercreate-%@example.com'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'usercreate-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    // AC1: GET /admin/users/new renders a user creation form
    public function testFormRendersSuccessfully(): void
    {
        $this->loginAsAdmin('usercreate-admin@example.com');
        $this->client->request('GET', '/admin/users/new');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('name="email"', $content);
        $this->assertStringContainsString('name="name"', $content);
        $this->assertStringContainsString('name="password"', $content);
        $this->assertStringContainsString('name="status"', $content);
    }

    // AC2: POST /admin/users/new with valid data creates the user and redirects to the user list
    public function testCreateWithValidDataCreatesUserAndRedirects(): void
    {
        $this->loginAsAdmin('usercreate-admin@example.com');
        $this->client->request('GET', '/admin/users/new');

        $this->client->submitForm('Create User', [
            'email'    => 'usercreate-new@example.com',
            'name'     => 'New User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertResponseRedirects('/admin/users');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'usercreate-new@example.com']);
        $this->assertNotNull($user);
        $this->assertSame('New User', $user->getName());
        $this->assertSame('active', $user->getStatus());
    }

    // Security (M3): admin user-create must enforce the password policy hard floor (>=8
    // even with config unset), not the old inline "< 6" check. A 7-char password is rejected
    // and no user is created.
    public function testCreateRejectsPasswordBelowPolicyFloor(): void
    {
        $this->loginAsAdmin('usercreate-admin@example.com');
        $this->client->request('GET', '/admin/users/new');

        $this->client->submitForm('Create User', [
            'email'    => 'usercreate-short@example.com',
            'name'     => 'Short Pw',
            'password' => '1234567', // 7 chars — below the hard floor of 8
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        // Re-renders the form (no redirect) and does NOT create the user.
        $this->assertResponseIsSuccessful();
        $this->assertNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'usercreate-short@example.com']),
            'A password below the policy floor must not create a user.'
        );
    }

    // AC3: Duplicate email shows a validation error and does not create a duplicate
    public function testDuplicateEmailShowsValidationError(): void
    {
        $this->createTestUser('usercreate-dup@example.com', 'Existing User', 'password');

        $this->loginAsAdmin('usercreate-admin@example.com');
        $this->client->request('GET', '/admin/users/new');

        $this->client->submitForm('Create User', [
            'email'    => 'usercreate-dup@example.com',
            'name'     => 'Duplicate User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('already registered', $content);

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "user" WHERE email = ?',
            ['usercreate-dup@example.com']
        );
        $this->assertSame(1, $count);
    }

    // AC4: Newly created user appears in the user list
    public function testNewlyCreatedUserAppearsInUserList(): void
    {
        $this->loginAsAdmin('usercreate-admin@example.com');
        $this->client->request('GET', '/admin/users/new');

        $this->client->submitForm('Create User', [
            'email'    => 'usercreate-listed@example.com',
            'name'     => 'Listed User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ]);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Listed User', $content);
        $this->assertStringContainsString('usercreate-listed@example.com', $content);
    }
}
