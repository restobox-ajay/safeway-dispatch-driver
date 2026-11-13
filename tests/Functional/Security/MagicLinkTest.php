<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Bundle\AuthMagicLink\Security\MagicLinkAuthenticator;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class MagicLinkTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement(
                "DELETE FROM \"user\" WHERE email IN ('mltest@example.com', 'mltest2@example.com')"
            );
            $this->conn->executeStatement(
                "DELETE FROM magic_link_tokens WHERE email IN ('mltest@example.com', 'mltest2@example.com')"
            );
            $this->conn->executeStatement(
                "DELETE FROM config WHERE config_key = 'magic_link.expiry_minutes'"
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createActiveUser(string $email = 'mltest@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Magic Link Test');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function createValidToken(string $email, string $plaintext, int $minutesFromNow = 15): MagicLinkToken
    {
        $token = new MagicLinkToken(
            $email,
            hash('sha256', $plaintext),
            new \DateTimeImmutable(sprintf('+%d minutes', $minutesFromNow))
        );
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();
        return $token;
    }

    // AC1: GET /magic-link renders an email input form
    public function testGetMagicLinkRendersEmailForm(): void
    {
        $this->client->request('GET', '/magic-link');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    // AC2: POST /magic-link sends a login link to a registered and active email address
    public function testPostMagicLinkSendsEmailToRegisteredUser(): void
    {
        $this->createActiveUser('mltest@example.com');

        $this->client->request('GET', '/magic-link');
        $this->client->submitForm('Send Magic Link', [
            'email' => 'mltest@example.com',
        ]);

        $this->assertResponseRedirects('/magic-link/check');
        $this->assertEmailCount(1);
        $messages = $this->getMailerMessages();
        $this->assertEmailAddressContains($messages[0], 'To', 'mltest@example.com');
    }

    // AC2 no-enumeration: unregistered email still redirects to check page (no email sent)
    public function testPostMagicLinkWithUnregisteredEmailShowsSameConfirmation(): void
    {
        $this->client->request('GET', '/magic-link');
        $this->client->submitForm('Send Magic Link', [
            'email' => 'nobody@example.com',
        ]);

        $this->assertResponseRedirects('/magic-link/check');
        $this->assertEmailCount(0);
    }

    // AC3: Clicking a valid link logs the user in and redirects to the dashboard
    public function testClickingValidLinkLogsInUserAndRedirects(): void
    {
        $this->createActiveUser('mltest@example.com');
        $plaintext = bin2hex(random_bytes(16));
        $this->createValidToken('mltest@example.com', $plaintext);

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    // AC4: Expired links are rejected with a clear error
    public function testExpiredLinkIsRejectedWithClearError(): void
    {
        $this->createActiveUser('mltest@example.com');
        $plaintext = bin2hex(random_bytes(16));

        // Create expired token (expires in the past)
        $token = new MagicLinkToken(
            'mltest@example.com',
            hash('sha256', $plaintext),
            new \DateTimeImmutable('-1 minute')
        );
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/magic-link');
        $this->client->followRedirect();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'expired',
            strtolower($this->client->getResponse()->getContent())
        );
    }

    // AC4: Already-used links are rejected with a clear error
    public function testAlreadyUsedLinkIsRejectedWithClearError(): void
    {
        $this->createActiveUser('mltest@example.com');
        $plaintext = bin2hex(random_bytes(16));

        $token = new MagicLinkToken(
            'mltest@example.com',
            hash('sha256', $plaintext),
            new \DateTimeImmutable('+15 minutes')
        );
        $token->markUsed();
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('GET', '/magic-link/verify?token=' . $plaintext);

        $this->assertResponseRedirects('/magic-link');
        $this->client->followRedirect();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'already been used',
            strtolower($this->client->getResponse()->getContent())
        );
    }

    // Issue #13: single use must hold under concurrency. Reproduce the race deterministically: this worker has
    // already read the token as unused (it sits unused in the identity map) when a concurrent worker consumes it.
    // The consume must be decided by the database, not by the stale in-memory read.
    public function testALinkConsumedConcurrentlyIsNotAcceptedASecondTime(): void
    {
        $this->createActiveUser('mltest@example.com');
        $plaintext = bin2hex(random_bytes(16));
        $this->createValidToken('mltest@example.com', $plaintext);
        $hash = hash('sha256', $plaintext);

        $seen = $this->em->getRepository(MagicLinkToken::class)->findOneBy(['tokenHash' => $hash]);
        self::assertNotNull($seen);
        self::assertFalse($seen->isUsed());

        $this->conn->executeStatement(
            "UPDATE magic_link_tokens SET used_at = datetime('now') WHERE token_hash = ?",
            [$hash]
        );

        $authenticator = self::getContainer()->get(MagicLinkAuthenticator::class);
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('already been used');
        $authenticator->authenticate(Request::create('/magic-link/verify', 'GET', ['token' => $plaintext]));
    }

    // AC5: Link expiry duration is configurable via admin config
    public function testLinkExpiryIsConfigurableViaAdminConfig(): void
    {
        $this->createActiveUser('mltest@example.com');

        // Set custom expiry of 30 minutes
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('magic_link.expiry_minutes', '30')"
        );

        $this->client->request('GET', '/magic-link');
        $this->client->submitForm('Send Magic Link', [
            'email' => 'mltest@example.com',
        ]);
        $this->assertResponseRedirects('/magic-link/check');
        $this->assertEmailCount(1);

        // Find the token in DB and verify expiry is approximately now + 30 minutes
        $row = $this->conn->fetchAssociative(
            "SELECT expires_at FROM magic_link_tokens WHERE email = ?",
            ['mltest@example.com']
        );
        $this->assertNotFalse($row, 'Token row must exist in DB');

        $expiresAt = new \DateTimeImmutable($row['expires_at']);
        $expectedExpiry = new \DateTimeImmutable('+30 minutes');

        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $expiresAt->getTimestamp(),
            120,
            'Token expiry should be approximately 30 minutes from now'
        );
    }
}
