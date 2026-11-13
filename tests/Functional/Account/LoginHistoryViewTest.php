<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginHistoryViewTest extends WebTestCase
{
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
            foreach (['lhview1@example.com', 'lhview2@example.com'] as $email) {
                $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
                if ($userId !== false) {
                    $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                    $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $name): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $userId = (int) $user->getId();
        $this->em->clear();
        return $userId;
    }

    private function insertLoginHistoryRow(int $userId, string $ip, string $ua): void
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            'INSERT INTO login_history (user_id, ip, user_agent, fingerprint, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $ip, $ua, hash('sha256', $ip . $ua), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    private function loginAs(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'testpassword',
        ]);
    }

    public function testLoginHistoryPageRendersForAuthenticatedUser(): void
    {
        $this->createUser('lhview1@example.com', 'LH View User1');
        $this->loginAs('lhview1@example.com');

        $this->client->request('GET', '/account/login-history');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testEachEntryShowsIpUserAgentAndTimestamp(): void
    {
        $userId = $this->createUser('lhview1@example.com', 'LH View User1');
        $this->insertLoginHistoryRow($userId, '192.168.1.42', 'TestBrowser/ViewTest/2.0');

        $this->loginAs('lhview1@example.com');
        $this->client->request('GET', '/account/login-history');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        // login also creates a history row, so check content rather than first selector node
        $this->assertStringContainsString('192.168.1.42', $content);
        $this->assertStringContainsString('TestBrowser/ViewTest/2.0', $content);
        $this->assertSelectorExists('.entry-time');
    }

    public function testOnlyCurrentUsersHistoryIsShown(): void
    {
        $userId1 = $this->createUser('lhview1@example.com', 'LH View User1');
        $userId2 = $this->createUser('lhview2@example.com', 'LH View User2');

        $this->insertLoginHistoryRow($userId1, '10.0.0.1', 'AgentForUser1/1.0');
        $this->insertLoginHistoryRow($userId2, '10.0.0.2', 'AgentForUser2/1.0');

        $this->loginAs('lhview1@example.com');
        $this->client->request('GET', '/account/login-history');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('AgentForUser1/1.0', (string) $content);
        $this->assertStringNotContainsString('AgentForUser2/1.0', (string) $content);
    }

    public function testUnauthenticatedAccessRedirectsToLogin(): void
    {
        $this->client->request('GET', '/account/login-history');

        $this->assertResponseRedirects('/staff/login');
    }
}
