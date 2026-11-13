<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Admin;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #53: malformed numeric input must get a documented answer, never a 500. A non-integer (or int-overflowing)
 * {id} reached the `int $id` controller argument as a raw string and raised a TypeError; a huge ?page= saturated
 * to PHP_INT_MAX and turned the offset into a float, which the int-typed setFirstResult() rejected.
 */
final class AdminApiMalformedInputTest extends WebTestCase
{
    private const ADMIN = 'apimalformed-admin@example.com';

    private KernelBrowser $client;
    private Connection $conn;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn   = self::getContainer()->get(Connection::class);
        $this->cleanup();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new Admin();
        $admin->setEmail(self::ADMIN);
        $admin->setName('Malformed Input Admin');
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();
        $em->clear();

        $this->token = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => (int) $this->conn->fetchOne('SELECT id FROM admin WHERE email = ?', [self::ADMIN]),
            'name'       => 'Malformed Input Token',
            'token_hash' => hash('sha256', $this->token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement('DELETE FROM admin_access_tokens WHERE admin_id IN (SELECT id FROM admin WHERE email = ?)', [self::ADMIN]);
        $this->conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [self::ADMIN]);
        $this->conn->executeStatement('DELETE FROM admin WHERE email = ?', [self::ADMIN]);
    }

    private function call(string $method, string $uri): int
    {
        $this->client->request($method, $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedIdRoutes(): iterable
    {
        foreach (['abc', '7abc', '99999999999999999999'] as $bad) {
            yield "GET user $bad"            => ['GET', "/admin-api/users/$bad"];
            yield "PATCH user $bad"          => ['PATCH', "/admin-api/users/$bad"];
            yield "DELETE user $bad"         => ['DELETE', "/admin-api/users/$bad"];
            yield "activate $bad"            => ['POST', "/admin-api/users/$bad/activate"];
            yield "deactivate $bad"          => ['POST', "/admin-api/users/$bad/deactivate"];
            yield "force-logout $bad"        => ['POST', "/admin-api/users/$bad/force-logout"];
            yield "password-reset $bad"      => ['POST', "/admin-api/users/$bad/password-reset"];
            yield "revoke tokens $bad"       => ['DELETE', "/admin-api/users/$bad/tokens"];
            yield "unlock $bad"              => ['POST', "/admin-api/users/$bad/unlock"];
            yield "reset 2fa $bad"           => ['DELETE', "/admin-api/users/$bad/2fa"];
            yield "resend invitation $bad"   => ['POST', "/admin-api/invitations/$bad/resend"];
        }
    }

    /** @dataProvider malformedIdRoutes */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIdRoutes')]
    public function testAMalformedIdIsA404NotA500(string $method, string $uri): void
    {
        self::assertSame(404, $this->call($method, $uri));
    }

    public function testAHugePageOnTheUserListIsAnEmptyPageNotA500(): void
    {
        self::assertSame(200, $this->call('GET', '/admin-api/users?page=99999999999999999999'));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $body['data'] ?? null, 'no user lives that far out');
    }

    public function testAHugePageOnTheAuditLogIsAnEmptyPageNotA500(): void
    {
        self::assertSame(200, $this->call('GET', '/admin-api/audit-log?page=99999999999999999999'));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $body['data'] ?? null);
    }
}
