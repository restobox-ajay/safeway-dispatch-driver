<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\UserSession;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionManagementTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
            $conn = self::getContainer()->get('doctrine.dbal.default_connection');
            $conn->executeStatement('DELETE FROM user_sessions');
            $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'sesstest%\'');
            $conn->executeStatement('DELETE FROM audit_log');
            $conn->executeStatement('DELETE FROM login_history');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    // AC1: GET /account/sessions lists all active sessions for the current user
    public function testSessionListShowsActiveSessions(): void
    {
        $this->createTestUser('sesstest@example.com', 'Session Test User');
        $this->loginUser('sesstest@example.com', 'testpassword', true);

        $this->client->request('GET', '/account/sessions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    // AC2: Each session entry shows IP address, user-agent, and last active timestamp
    public function testEachSessionEntryShowsIpUserAgentAndLastActive(): void
    {
        $this->createTestUser('sesstest@example.com', 'Session Test User');
        $this->loginUser('sesstest@example.com', 'testpassword', true);

        $this->client->request('GET', '/account/sessions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.session-ip');
        $this->assertSelectorExists('.session-ua');
        $this->assertSelectorExists('.session-last-active');
    }

    // AC3: User can terminate a specific other session
    public function testUserCanTerminateSpecificOtherSession(): void
    {
        $user = $this->createTestUser('sesstest@example.com', 'Session Test User');
        $this->loginUser('sesstest@example.com', 'testpassword', true);

        // Manually insert a second UserSession row (simulating another device)
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'INSERT INTO user_sessions (session_id, user_id, ip, user_agent, created_at, last_active_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                'other-device-session-id-12345',
                (int) $user->getId(),
                '10.0.0.2',
                'OtherDevice/Browser 1.0',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        // Get the ID of the other session
        $otherId = (int) $conn->fetchOne(
            'SELECT id FROM user_sessions WHERE session_id = ?',
            ['other-device-session-id-12345']
        );
        $this->assertGreaterThan(0, $otherId);

        // Crawl sessions page to get the terminate form with CSRF token
        $crawler = $this->client->request('GET', '/account/sessions');
        $this->assertResponseIsSuccessful();

        // Submit the Terminate form for the other session
        $form = $crawler->filter('form[action*="' . $otherId . '/terminate"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/account/sessions');

        // Verify the other session row is gone
        $count = $conn->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE session_id = ?',
            ['other-device-session-id-12345']
        );
        $this->assertSame(0, (int) $count);
    }

    // AC4: User can terminate all sessions ('logout everywhere')
    public function testUserCanTerminateAllSessions(): void
    {
        $user = $this->createTestUser('sesstest@example.com', 'Session Test User');
        $this->loginUser('sesstest@example.com', 'testpassword', true);

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        // Verify there is at least one session row
        $countBefore = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = ?',
            [(int) $user->getId()]
        );
        $this->assertGreaterThan(0, $countBefore);

        // Crawl sessions page to get the Logout Everywhere form with CSRF token
        $crawler = $this->client->request('GET', '/account/sessions');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Logout Everywhere')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/staff/login');

        // Verify all session rows for this user are gone
        $countAfter = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = ?',
            [(int) $user->getId()]
        );
        $this->assertSame(0, $countAfter);
    }

    // AC5: After 'logout everywhere', subsequent requests with old session IDs are unauthenticated
    public function testAfterLogoutEverywhereOldSessionIsUnauthenticated(): void
    {
        $this->createTestUser('sesstest@example.com', 'Session Test User');
        $this->loginUser('sesstest@example.com', 'testpassword', true);

        // Terminate all sessions
        $crawler = $this->client->request('GET', '/account/sessions');
        $form = $crawler->selectButton('Logout Everywhere')->form();
        $this->client->submit($form);

        // Follow the redirect to /login — the UserSessionRequestListener will detect
        // no UserSession row and invalidate the session
        $this->client->followRedirect();

        // Now attempt to access a protected route
        $this->client->request('GET', '/dashboard');

        // Should be redirected to /login (unauthenticated)
        $this->assertResponseRedirects('/staff/login');
    }
}
