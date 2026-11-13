<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-117 (review C25): the admin API user list must be filterable (at least by
 * email, ideally status) with a configurable, bounded per_page.
 */
final class AdminApiUserListFilterTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $adminToken = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
        $this->cleanup();
        $this->adminToken = $this->createAdminToken();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'listadmin%@example.com'");
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'flt-%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdminToken(): string
    {
        $admin = new Admin();
        $admin->setEmail('listadmin@example.com');
        $admin->setName('List Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $adminId = (int) $this->conn->fetchOne("SELECT id FROM admin WHERE email = 'listadmin@example.com'");

        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $adminId,
            'name'       => 'Admin API Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function seedUser(string $email, string $status = 'active'): void
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('User ' . $email);
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    private function get(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
        ]);
        $this->assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testFilterByEmailReturnsOnlyMatching(): void
    {
        $this->seedUser('flt-alpha@example.com');
        $this->seedUser('flt-beta@example.com');
        $this->seedUser('flt-gamma@example.com');

        $data   = $this->get('/admin-api/users?email=beta');
        $emails = array_column($data['data'], 'email');

        $this->assertSame(['flt-beta@example.com'], $emails);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testPerPageChangesPageSize(): void
    {
        $this->seedUser('flt-p1@example.com');
        $this->seedUser('flt-p2@example.com');
        $this->seedUser('flt-p3@example.com');

        $data = $this->get('/admin-api/users?email=flt-p&per_page=2');

        $this->assertCount(2, $data['data']);
        $this->assertSame(2, $data['meta']['per_page']);
        $this->assertSame(3, $data['meta']['total']);
        $this->assertSame(2, $data['meta']['total_pages']);
    }

    public function testPerPageIsBoundedToMax(): void
    {
        $data = $this->get('/admin-api/users?per_page=100000');

        $this->assertSame(100, $data['meta']['per_page']);
    }

    public function testFilterByStatusReturnsOnlyMatching(): void
    {
        $this->seedUser('flt-active@example.com', 'active');
        $this->seedUser('flt-inactive@example.com', 'inactive');

        $data   = $this->get('/admin-api/users?email=flt-&status=inactive');
        $emails = array_column($data['data'], 'email');

        $this->assertSame(['flt-inactive@example.com'], $emails);
        $this->assertSame(1, $data['meta']['total']);
    }
}
