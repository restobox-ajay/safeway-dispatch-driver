<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserListTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestAdmin('userlist-admin@example.com', 'User List Admin');
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
                "DELETE FROM \"user\" WHERE email LIKE 'userlist-%@example.com'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'userlist-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    private function createUser(string $suffix): User
    {
        return $this->createTestUser("userlist-{$suffix}@example.com", "User {$suffix}", 'password', 'active');
    }

    // AC1: GET /admin/users returns a paginated list of users
    public function testUserListRendersSuccessfully(): void
    {
        $this->loginAsAdmin('userlist-admin@example.com');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
    }

    // AC2: Each row shows name, email, status, and role
    public function testEachRowShowsNameEmailStatusAndRole(): void
    {
        $this->createUser('ac2');
        $this->loginAsAdmin('userlist-admin@example.com');
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('User ac2', $content);
        $this->assertStringContainsString('userlist-ac2@example.com', $content);
        $this->assertStringContainsString('active', $content);
        $this->assertStringContainsString('ROLE_USER', $content);
    }

    // AC3: Unauthenticated or non-admin access is redirected to /admin/login
    public function testUnauthenticatedAccessRedirectsToAdminLogin(): void
    {
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString(
            '/admin/login',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    // AC4: Pagination controls navigate between pages correctly
    public function testPaginationNavigatesBetweenPages(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->createUser(sprintf('page%02d', $i));
        }

        $this->loginAsAdmin('userlist-admin@example.com');

        $this->client->request('GET', '/admin/users?page=1');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=2', $content);

        $this->client->request('GET', '/admin/users?page=2');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=1', $content);
    }
}
