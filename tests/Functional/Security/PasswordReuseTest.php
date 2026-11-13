<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordReuseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        try {
            $this->conn->executeStatement("DELETE FROM password_history WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'reusetest%@example.com')");
            $this->conn->executeStatement("DELETE FROM password_reset_tokens WHERE email LIKE 'reusetest%@example.com'");
            $this->conn->executeStatement("DELETE FROM config WHERE config_key = 'password_policy.reuse_count'");
            $this->em->createQuery("DELETE FROM App\\Entity\\User u WHERE u.email LIKE 'reusetest%@example.com'")->execute();
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, string $password): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Reuse Test');
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function createResetToken(string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new PasswordResetToken($email, hash('sha256', $plaintext), new \DateTimeImmutable('+1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        return $plaintext;
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)",
            [$key, $value]
        );
    }

    /** AC1: password_history table exists with required columns */
    public function testPasswordHistoryTableHasRequiredColumns(): void
    {
        $columns = $this->conn->fetchAllAssociative("PRAGMA table_info('password_history')");
        $names = array_column($columns, 'name');

        $this->assertContains('user_id', $names, 'user_id column must exist');
        $this->assertContains('password_hash', $names, 'password_hash column must exist');
        $this->assertContains('created_at', $names, 'created_at column must exist');
    }

    /** AC2: password matching a recent stored hash is rejected with an error */
    public function testPasswordReuseIsBlockedWhenEnabled(): void
    {
        $this->setConfig('password_policy.reuse_count', '3');

        $user = $this->createUser('reusetest1@example.com', 'OldPass1!');

        // Store a history entry for this user's current password
        $this->conn->executeStatement(
            "INSERT INTO password_history (user_id, password_hash, created_at) VALUES (?, ?, ?)",
            [(int) $user->getId(), password_hash('OldPass1!', PASSWORD_BCRYPT, ['cost' => 4]), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );

        $token = $this->createResetToken('reusetest1@example.com');

        $this->client->request('POST', '/reset-password/' . $token, ['password' => 'OldPass1!']);

        // Should stay on form (200) with an error about password reuse
        $this->assertResponseStatusCodeSame(200);
        $content = $this->client->getResponse()->getContent();
        $this->assertTrue(
            str_contains(strtolower($content), 'reuse') || str_contains(strtolower($content), 'recent'),
            'Response must indicate the password was recently used'
        );
    }

    /** AC3: only the most recent X entries per user are retained after a write */
    public function testOnlyMostRecentEntriesAreRetained(): void
    {
        $this->setConfig('password_policy.reuse_count', '2');

        $user = $this->createUser('reusetest2@example.com', 'Initial1!');
        $userId = (int) $user->getId();

        // Insert 2 old history entries via DBAL
        $this->conn->executeStatement(
            "INSERT INTO password_history (user_id, password_hash, created_at) VALUES (?, ?, ?)",
            [$userId, password_hash('History1!', PASSWORD_BCRYPT, ['cost' => 4]), '2020-01-01 00:00:00']
        );
        $this->conn->executeStatement(
            "INSERT INTO password_history (user_id, password_hash, created_at) VALUES (?, ?, ?)",
            [$userId, password_hash('History2!', PASSWORD_BCRYPT, ['cost' => 4]), '2020-01-02 00:00:00']
        );

        // Reset password — triggers store + prune (should keep only 2 most recent)
        $token = $this->createResetToken('reusetest2@example.com');
        $this->client->request('POST', '/reset-password/' . $token, ['password' => 'BrandNew1!']);

        // Verify the reset completed (ensures storeHash was actually called)
        $this->assertResponseRedirects('/staff/login', null, 'Reset with a new password must succeed');
        $this->client->followRedirect();

        // After adding 3rd entry and pruning to 2, count should be 2
        $count = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM password_history WHERE user_id = ?",
            [$userId]
        );
        $this->assertSame(2, $count, 'Only the most recent 2 history entries should be retained');
    }

    /** AC4: reuse_count=0 disables the check — same password can be reused */
    public function testReuseCountZeroDisablesCheck(): void
    {
        $this->setConfig('password_policy.reuse_count', '0');

        $user = $this->createUser('reusetest3@example.com', 'SamePass1!');
        $userId = (int) $user->getId();

        // Store a history entry that would match
        $this->conn->executeStatement(
            "INSERT INTO password_history (user_id, password_hash, created_at) VALUES (?, ?, ?)",
            [$userId, password_hash('SamePass1!', PASSWORD_BCRYPT, ['cost' => 4]), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );

        $token = $this->createResetToken('reusetest3@example.com');
        $this->client->request('POST', '/reset-password/' . $token, ['password' => 'SamePass1!']);

        // With reuse_count=0, check is disabled — should redirect (success)
        $this->assertResponseRedirects('/staff/login', null, 'Password reuse check must be disabled when reuse_count=0');
    }
}
