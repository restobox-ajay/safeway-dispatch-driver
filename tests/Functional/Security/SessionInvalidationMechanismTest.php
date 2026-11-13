<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\UserAccountAdminService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Isolates the two independent mechanisms that can kill a live user session, so neither
 * SessionTeardownCest nor DeactivationCutsAccessTest has to infer which one actually fired
 * (review follow-up: the Cest's docblock previously claimed the post-teardown /login redirect
 * "can only be the teardown", which overstated causality):
 *
 *   1. Symfony's ContextListener: User implements EquatableInterface, and isEqualTo() compares
 *      password + status. A bare status flip alone (no session-row change) makes the
 *      freshly-reloaded user unequal to the one carried in the session token, so the token is
 *      dropped and the next request bounces to /login — regardless of user_sessions.
 *   2. UserSessionRequestListener: on every request it looks up the current session id in
 *      user_sessions; a missing row invalidates the session and clears the token — regardless
 *      of whether status/password changed.
 *
 * tearDownLiveAccess() (deactivate()/delete()) triggers BOTH at once, which is why observing the
 * browser end up at /login after either action can't tell you which mechanism did it. These
 * tests trigger each one alone (bypassing tearDownLiveAccess via raw SQL) to prove each is
 * independently sufficient. A third test proves the negative: a plain, non-status edit —
 * including an attempted role change, which is a no-op because role is fixed to ROLE_USER
 * (ADR-024) — triggers neither.
 */
final class SessionInvalidationMechanismTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private const PASSWORD = 'testpassword';

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
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'sessmech%@example.com'");
            $this->conn->executeStatement('DELETE FROM user_sessions');
            $this->conn->executeStatement('DELETE FROM login_attempts');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Session Mechanism Test User');
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return (int) $this->conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    private function login(string $email): void
    {
        $this->client->request('POST', '/staff/login', [
            'email'    => $email,
            'password' => self::PASSWORD,
        ]);
        $this->assertResponseStatusCodeSame(302);

        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(
            200,
            'Login must establish a live session before the mechanism under test runs.'
        );
    }

    private function sessionIdFor(int $userId): string
    {
        $sessionId = $this->conn->fetchOne('SELECT session_id FROM user_sessions WHERE user_id = ?', [$userId]);
        $this->assertNotFalse($sessionId, 'Expected login to write a user_sessions row.');

        return (string) $sessionId;
    }

    // Mechanism 1 in isolation: flip status directly (bypassing tearDownLiveAccess, so the
    // user_sessions row is left untouched) and prove the live session still dies.
    public function testStatusChangeAloneDeauthenticatesLiveSession(): void
    {
        $userId = $this->createUser('sessmech-status@example.com');
        $this->login('sessmech-status@example.com');
        $sessionId = $this->sessionIdFor($userId);

        $this->conn->executeStatement('UPDATE "user" SET status = ? WHERE id = ?', ['inactive', $userId]);

        // The row tearDownLiveAccess would have deleted is still there — isolating the mechanism.
        $remaining = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE session_id = ?',
            [$sessionId]
        );
        $this->assertSame(1, $remaining, 'This test isolates the status-only path: the session row must still exist.');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects(
            '/staff/login',
            null,
            'A bare status flip must deauthenticate via ContextListener/isEqualTo alone.'
        );
    }

    // Mechanism 2 in isolation: delete the session row directly (status/password untouched) and
    // prove the live session still dies.
    public function testSessionRowDeletionAloneDeauthenticatesLiveSession(): void
    {
        $userId = $this->createUser('sessmech-row@example.com');
        $this->login('sessmech-row@example.com');
        $sessionId = $this->sessionIdFor($userId);

        $this->conn->executeStatement('DELETE FROM user_sessions WHERE session_id = ?', [$sessionId]);

        // Status/password are exactly what they were at login — isEqualTo would find no change.
        $status = $this->conn->fetchOne('SELECT status FROM "user" WHERE id = ?', [$userId]);
        $this->assertSame('active', $status, 'This test isolates the row-deletion-only path: status must be untouched.');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects(
            '/staff/login',
            null,
            'A missing user_sessions row must deauthenticate via UserSessionRequestListener alone.'
        );
    }

    // Negative case, answering "does changing something other than status ever bounce the user":
    // role is fixed to ROLE_USER (ADR-024) and update() never calls tearDownLiveAccess for a plain
    // edit, so neither mechanism fires and the live session survives untouched.
    public function testPlainEditIncludingRoleDoesNotDeauthenticateLiveSession(): void
    {
        $userId = $this->createUser('sessmech-edit@example.com');
        $this->login('sessmech-edit@example.com');
        $sessionId = $this->sessionIdFor($userId);

        /** @var UserAccountAdminService $userService */
        $userService = self::getContainer()->get(UserAccountAdminService::class);
        $user = $this->em->getRepository(User::class)->find($userId);
        $result = $userService->update($user, [
            'name' => 'Renamed Session Mechanism Test User',
            'role' => 'ROLE_USER',
        ], 'admin@example.com', '127.0.0.1');

        $this->assertTrue($result->isSuccess(), 'A no-op ROLE_USER role submission must not be rejected.');

        $remaining = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE session_id = ?',
            [$sessionId]
        );
        $this->assertSame(1, $remaining, 'A plain edit must not tear down the session row.');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(
            200,
            'A plain edit (name/role no-op) must not deauthenticate the live session.'
        );
    }
}
