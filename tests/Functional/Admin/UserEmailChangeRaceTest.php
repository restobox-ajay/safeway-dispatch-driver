<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #59: the email-uniqueness race on an EDIT (ADR-030 covered only the create paths). Two admins move two
 * users to the same address at once; both pass the friendly findByEmail() pre-check and the second flush hits
 * the user.email UNIQUE index. Reproduced deterministically like RegistrationRaceTest: UserRepository is
 * stubbed so the pre-check "sees nothing" while the address is already taken in the DB. The edit must answer
 * with the same clean validation error as the pre-check (422 on the API, a form error on the web), never a 500.
 */
final class UserEmailChangeRaceTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const TAKEN = 'emailrace-taken@example.com';
    private const MOVER = 'emailrace-mover@example.com';
    private const ADMIN = 'emailrace-admin@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private int $moverId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One kernel across requests so the UserRepository override survives.
        $this->client->disableReboot();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();

        // Seed with raw SQL so the autowired UserRepository stays uninitialised (it is replaced below).
        foreach ([self::TAKEN, self::MOVER] as $email) {
            $this->conn->executeStatement(
                'INSERT INTO "user" (email, name, password, roles, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$email, 'Race User', password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]), '[]', 'active', date('Y-m-d H:i:s')]
            );
        }
        $this->moverId = (int) $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::MOVER]);

        // The losing request's stale pre-check: the address looks free; the mover itself still loads (as a
        // managed entity at call time, as the real repository would return it).
        $stubRepo = $this->createStub(UserRepository::class);
        $stubRepo->method('findByEmail')->willReturn(null);
        $stubRepo->method('find')->willReturnCallback(fn (): ?User => $this->em->find(User::class, $this->moverId));
        self::getContainer()->set(UserRepository::class, $stubRepo);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement("DELETE FROM admin_access_tokens WHERE admin_id IN (SELECT id FROM admin WHERE email = ?)", [self::ADMIN]);
            $this->conn->executeStatement('DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email = ?)', [self::ADMIN]);
            $this->conn->executeStatement('DELETE FROM admin WHERE email = ?', [self::ADMIN]);
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'emailrace-%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log WHERE actor = ?', [self::ADMIN]);
        } catch (\Throwable) {
        }
    }

    private function seedAdmin(): int
    {
        $this->conn->executeStatement(
            'INSERT INTO admin (email, name, password, roles, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ADMIN, 'Race Admin', self::hashTestPassword('adminpass'), '[]', 'active', date('Y-m-d H:i:s')]
        );

        return (int) $this->conn->fetchOne('SELECT id FROM admin WHERE email = ?', [self::ADMIN]);
    }

    public function testApiPatchToATakenEmailReturns422NotA500(): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id'   => $this->seedAdmin(),
            'name'       => 'Race Token',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->client->request('PATCH', '/admin-api/users/' . $this->moverId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext,
            'CONTENT_TYPE'       => 'application/json',
        ], (string) json_encode(['email' => self::TAKEN]));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('This email address is already registered.', $body['errors']['email'] ?? null);
        self::assertSame(self::MOVER, $this->conn->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->moverId]));
    }

    public function testWebEditToATakenEmailShowsTheFormErrorNotA500(): void
    {
        $this->seedAdmin();
        $this->loginAsAdmin(self::ADMIN, 'adminpass');

        $this->client->request('GET', '/admin/users/' . $this->moverId . '/edit');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save Changes', ['email' => self::TAKEN]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This email address is already registered.', (string) $this->client->getResponse()->getContent());
        self::assertSame(self::MOVER, $this->conn->fetchOne('SELECT email FROM "user" WHERE id = ?', [$this->moverId]));
    }
}
