<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RememberMeTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private const EMAIL = 'rememberme@example.com';
    private const PASSWORD = 'testpassword';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTestData();
        $this->createTestUser(self::EMAIL, 'Remember Me Test', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->removeTestData();
        parent::tearDown();
    }

    private function removeTestData(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM \"user\" WHERE email = ?", [self::EMAIL]);
            $conn->executeStatement("DELETE FROM config WHERE config_key = 'remember_me.lifetime_days'");
        } catch (\Throwable) {
        }
    }

    public function testLoginFormHasRememberMeCheckbox(): void
    {
        $this->client->request('GET', '/staff/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_remember_me"]');
    }

    public function testCheckingRememberMeIssuesPersistentCookie(): void
    {
        $this->client->request('POST', '/staff/login', [
            'email'        => self::EMAIL,
            'password'     => self::PASSWORD,
            '_remember_me' => '1',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($cookie, 'REMEMBERME cookie should be set after login with remember_me=1');
        $this->assertGreaterThan(time(), $cookie->getExpiresTime(), 'Cookie should be persistent (future expiry)');
    }

    public function testRememberMeCookieReauthenticatesAfterSessionExpiry(): void
    {
        // Login with remember_me
        $this->client->request('POST', '/staff/login', [
            'email'        => self::EMAIL,
            'password'     => self::PASSWORD,
            '_remember_me' => '1',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $rememberMeCookie = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($rememberMeCookie, 'REMEMBERME cookie must be set before testing re-auth');

        // Simulate session expiry: clear all cookies then restore only the REMEMBERME cookie
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($rememberMeCookie);

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful(
            'Dashboard should return 200 when re-authenticated via REMEMBERME cookie (no session)'
        );
    }

    public function testRememberMeTokenLifetimeIsReadFromAdminConfig(): void
    {
        // Store 7-day lifetime in config
        $this->em->getConnection()->executeStatement(
            "INSERT INTO config (config_key, config_value) VALUES ('remember_me.lifetime_days', '7')"
        );

        $this->client->request('POST', '/staff/login', [
            'email'        => self::EMAIL,
            'password'     => self::PASSWORD,
            '_remember_me' => '1',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($cookie, 'REMEMBERME cookie should be set');

        $expectedExpiry = time() + 7 * 86400;
        $this->assertEqualsWithDelta(
            $expectedExpiry,
            $cookie->getExpiresTime(),
            120,
            'Cookie expiry should be approximately 7 days from now'
        );
    }
}
