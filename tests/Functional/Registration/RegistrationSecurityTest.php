<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-114 (review C21): the registration POST validates a CSRF token and is
 * throttled via EndpointRateLimiter (keyed on client IP, throttle ABOVE CSRF per
 * ADR-016), consistent with every other auth POST.
 */
final class RegistrationSecurityTest extends WebTestCase
{
    private const EMAILS = [
        'regsec1@example.com',
        'regsec2@example.com',
        'regsec3@example.com',
    ];

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
            foreach (self::EMAILS as $email) {
                $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [$email]);
            }
            $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
            $this->conn->executeStatement(
                "DELETE FROM config WHERE config_key IN ('rate_limit.max_attempts', 'rate_limit.window_seconds', 'email_verification.mode', 'registration.mode')"
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [$key, $value]
        );
    }

    private function userExists(string $email): bool
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null;
    }

    /** AC1/AC4: a registration POST without a valid CSRF token is rejected and creates no user. */
    public function testRegistrationRejectsMissingCsrfToken(): void
    {
        // POST directly, bypassing the rendered form, so no _token field is sent.
        $this->client->request('POST', '/register', [
            'email'    => 'regsec1@example.com',
            'name'     => 'Reg Sec User',
            'password' => 'validpassword123',
        ]);

        // Stays on the form with an error rather than redirecting to /login.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $this->assertFalse(
            $this->userExists('regsec1@example.com'),
            'No user should be created when CSRF validation fails'
        );
    }

    /**
     * AC2/AC3/AC5: exceeding the rate limit blocks further registrations and verification
     * emails from that IP. Throttle is keyed on IP above the CSRF check.
     */
    public function testRegistrationIsRateLimited(): void
    {
        $this->setConfig('rate_limit.max_attempts', '2');
        $this->setConfig('rate_limit.window_seconds', '300');
        // Enable verification emails so we can prove the 3rd (blocked) attempt sends none.
        $this->setConfig('email_verification.mode', 'optional');

        // Two allowed registrations, each redirects to /login and sends one verification email.
        // The mailer collector is per-request, so assert one email on each allowed POST.
        foreach (['regsec1@example.com', 'regsec2@example.com'] as $email) {
            $this->client->request('GET', '/register');
            $this->client->submitForm('Register', [
                'email'    => $email,
                'name'     => 'Reg Sec User',
                'password' => 'validpassword123',
            ]);
            $this->assertResponseRedirects('/staff/login');
            $this->assertEmailCount(1);
        }

        $this->assertTrue($this->userExists('regsec1@example.com'));
        $this->assertTrue($this->userExists('regsec2@example.com'));

        // Third attempt — count 2 >= 2 — blocked before user creation and before any email.
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'regsec3@example.com',
            'name'     => 'Reg Sec User',
            'password' => 'validpassword123',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString('Too many requests', (string) $this->client->getResponse()->getContent());

        $this->assertFalse(
            $this->userExists('regsec3@example.com'),
            'The rate-limited registration must not create a user'
        );
        // No verification email was sent for the blocked attempt.
        $this->assertEmailCount(0);
    }
}
