<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The /admin-api firewall authenticates Admin entities ONLY (admin_api firewall ->
 * app_admins provider -> AdminTokenAuthenticator -> admin_access_tokens / admin table).
 *
 * This proves the boundary holds even in the worst state the user table could be in:
 * a User whose roles column has been force-set to ROLE_ADMIN/ROLE_SUPER_ADMIN directly
 * at the database level (simulating a SQL injection or any bypass of User::ALLOWED_ROLES),
 * holding a *valid* user personal access token, STILL cannot reach the admin API — because
 * the admin API never reads the user table or user PATs.
 */
final class AdminApiBoundaryTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement('DELETE FROM personal_access_tokens');
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email = 'boundary@example.com'");
        } catch (\Throwable) {
        }
    }

    public function testUserWithInjectedAdminRoleAndValidPatCannotReachAdminApi(): void
    {
        // A normal user...
        $user = new User();
        $user->setEmail('boundary@example.com');
        $user->setName('Boundary User');
        $user->setPassword(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $userId = (int) $user->getId();
        $this->em->clear();

        // ...whose roles column is force-injected with the admin namespace at the DB level,
        // bypassing User::ALLOWED_ROLES entirely (worst case / simulated SQL injection).
        $this->conn->executeStatement(
            'UPDATE "user" SET roles = ? WHERE id = ?',
            ['["ROLE_ADMIN","ROLE_SUPER_ADMIN"]', $userId]
        );

        // ...and a genuinely valid user personal access token.
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id'    => $userId,
            'name'       => 'Boundary PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // The admin API rejects it outright: the admin_api firewall authenticates Admin
        // entities via admin_access_tokens and never consults the user table or user PATs.
        $this->client->request('GET', '/admin-api/users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
        ]);
        $this->assertResponseStatusCodeSame(401);

        // Sanity: the same token DOES authenticate on the user-side `api` firewall — a
        // missing /api route yields 404 (authenticated, no route), not 401. This proves the
        // 401 above is the admin boundary rejecting the identity, not a malformed token.
        $this->client->request('GET', '/api/__boundary_probe__', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }
}
