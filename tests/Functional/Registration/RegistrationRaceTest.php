<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-119 / review C27, AC1 + AC4: the check-then-insert email-uniqueness race.
 *
 * Two concurrent registrations for the same email both pass the friendly
 * `findByEmail()` pre-check; the second `flush()` then violates the `user.email`
 * UNIQUE index. We reproduce that window deterministically in a single process by
 * stubbing `UserRepository::findByEmail` to return null (the pre-check "sees nothing")
 * while the row already exists in the DB — so the code path under test is the DB-level
 * unique violation, which must be surfaced as a clean validation error, never a 500.
 */
final class RegistrationRaceTest extends WebTestCase
{
    private const EMAIL = 'race-dupe@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // A single kernel/EntityManager across the GET + POST so the container service
        // override survives to the POST that exercises the race window.
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        // The email already exists in the DB (the "winner" of the race committed first).
        // Seed via raw SQL so the autowired UserRepository service is NOT initialized here —
        // the test replaces it below, which is only allowed while it is still uninitialized.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO "user" (email, name, password, roles, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::EMAIL,
                'Existing User',
                password_hash('somepassword', PASSWORD_BCRYPT, ['cost' => 4]),
                '[]',
                'active',
                date('Y-m-d H:i:s'),
            ]
        );
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
                'DELETE FROM "user" WHERE email = ?',
                [self::EMAIL]
            );
            $this->em->getConnection()->executeStatement('DELETE FROM endpoint_rate_limits');
        } catch (\Throwable) {
        }
    }

    public function testConcurrentDuplicateEmailReturnsHandledErrorNot500(): void
    {
        // Simulate the losing request's stale pre-check: findByEmail reports "free".
        // Must be installed before the first request initializes the service.
        $stubRepo = $this->createStub(UserRepository::class);
        $stubRepo->method('findByEmail')->willReturn(null);
        self::getContainer()->set(UserRepository::class, $stubRepo);

        // Render the form first (GET does not touch findByEmail).
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Register', [
            'email'    => self::EMAIL,
            'name'     => 'Racing User',
            'password' => 'validpassword123',
        ]);

        // Handled: stays on the form with an error, NOT a 302 success and NOT a 500.
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('.error');

        // The DB still holds exactly one row for the email — the losing insert did not land.
        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "user" WHERE email = ?',
            [self::EMAIL]
        );
        $this->assertSame(1, $count, 'The duplicate insert must not create a second row.');
    }
}
