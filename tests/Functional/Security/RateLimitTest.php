<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RateLimitTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);

        $this->conn->executeStatement('DELETE FROM login_attempts');
        $this->conn->executeStatement("DELETE FROM config WHERE config_key IN ('rate_limit.max_attempts', 'rate_limit.window_seconds')");

        $this->removeTestUser();
        $user = new User();
        $user->setEmail('ratetest@example.com');
        $user->setName('Rate Test');
        $user->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        $this->conn->executeStatement('DELETE FROM login_attempts');
        $this->conn->executeStatement("DELETE FROM config WHERE config_key IN ('rate_limit.max_attempts', 'rate_limit.window_seconds')");
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'ratetest@example.com']);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)",
            [$key, $value]
        );
    }

    private function failedLogin(string $email = 'ratetest@example.com'): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => $email,
            'password' => 'wrongpassword',
        ]);
    }

    /**
     * Audit #13: the try is recorded before the password is checked, so parallel posts cannot all read
     * "under the limit" and each get a guess. Seen from inside the password check, this try already counts.
     */
    public function testATryIsCountedBeforeItsPasswordIsChecked(): void
    {
        $this->client->disableReboot();
        $seen = null;
        self::getContainer()->get('security.event_dispatcher.user')->addListener(
            \Symfony\Component\Security\Http\Event\CheckPassportEvent::class,
            function () use (&$seen): void {
                $seen = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE email = 'ratetest@example.com'");
            },
            1, // after the throttle (2048), just before the password check (CheckCredentialsListener, 0)
        );

        $this->failedLogin();

        self::assertSame(1, $seen, 'an in-flight try already counts against the limit');
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE email = 'ratetest@example.com'"), 'and is recorded once, as the failure');
    }

    public function testASuccessfulLoginLeavesNoAttemptBehind(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => 'ratetest@example.com', 'password' => 'correctpassword']);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM login_attempts'));
    }

    /**
     * Issue #26: Symfony's form login trim()s the username before loading the user, so a padded
     * email checks the REAL account's password. Every padding must count against that one account.
     *
     * @return iterable<string,array{string}>
     */
    public static function paddedEmails(): iterable
    {
        yield 'trailing space' => ['ratetest@example.com '];
        yield 'leading space' => [' ratetest@example.com'];
        yield 'tab' => ["ratetest@example.com\t"];
        yield 'newline' => ["ratetest@example.com\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paddedEmails')]
    public function testWhitespacePaddedEmailFailuresCountAgainstTheRealAccount(string $padded): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        // Each failure arrives from a different IP, so only the per-account limit can stop the guessing.
        foreach (['10.1.0.1', '10.1.0.2', '10.1.0.3'] as $ip) {
            $this->client->setServerParameter('REMOTE_ADDR', $ip);
            $this->failedLogin($padded);
        }

        self::assertSame(
            3,
            (int) $this->conn->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE email = 'ratetest@example.com'"),
            'failures must be recorded under the identifier the firewall actually authenticated',
        );
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE email <> 'ratetest@example.com'"));

        // A 4th try from yet another fresh IP, even with the CORRECT password, is refused for the account.
        $this->client->setServerParameter('REMOTE_ADDR', '10.1.0.4');
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', ['email' => $padded, 'password' => 'correctpassword']);
        $this->client->followRedirect();

        self::assertStringContainsString('Too many login attempts', (string) $this->client->getResponse()->getContent());
    }

    public function testWhitespacePaddedAdminEmailIsThrottledPerAccountToo(): void
    {
        $this->setConfig('rate_limit.max_attempts', '2');
        $this->setConfig('rate_limit.window_seconds', '300');

        $this->removeAdmin();
        $admin = new Admin();
        $admin->setEmail('rateadmin@example.com');
        $admin->setName('Rate Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        try {
            foreach (['10.2.0.1', '10.2.0.2'] as $ip) {
                $this->client->setServerParameter('REMOTE_ADDR', $ip);
                $this->client->request('GET', '/admin/login');
                $this->client->submitForm('Sign in', ['email' => "  rateadmin@example.com\t", 'password' => 'wrongpassword']);
            }

            self::assertSame(2, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE realm = 'admin' AND email = 'rateadmin@example.com'"));

            $this->client->setServerParameter('REMOTE_ADDR', '10.2.0.3');
            $this->client->request('GET', '/admin/login');
            $this->client->submitForm('Sign in', ['email' => 'rateadmin@example.com ', 'password' => 'correctpassword']);
            $this->client->followRedirect();

            self::assertStringContainsString('Too many login attempts', (string) $this->client->getResponse()->getContent());
        } finally {
            $this->removeAdmin();
        }
    }

    public function testExceedingIpThresholdReturnsLockoutResponse(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        // 3 failed attempts — each allowed (count goes from 0→1→2→3)
        $this->failedLogin();
        $this->failedLogin();
        $this->failedLogin();

        // 4th attempt — count is 3 >= 3, should be blocked
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertStringContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );
    }

    public function testRateLimitWindowAndMaxAttemptsAreConfigurable(): void
    {
        // Set a low threshold of 2 to prove it reads from config (not a hardcoded default)
        $this->setConfig('rate_limit.max_attempts', '2');
        $this->setConfig('rate_limit.window_seconds', '300');

        // 2 failures
        $this->failedLogin();
        $this->failedLogin();

        // 3rd attempt — count is 2 >= 2, blocked
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertStringContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );

        // Change threshold to 10 and verify 3 more failures do NOT trigger rate limit
        $this->setConfig('rate_limit.max_attempts', '10');
        $this->conn->executeStatement('DELETE FROM login_attempts');

        // 3 failures — below new threshold of 10
        $this->failedLogin();
        $this->failedLogin();
        $this->failedLogin();

        // 4th attempt — count is 3 < 10, should NOT be blocked
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );
    }

    public function testExceedingAccountThresholdBlocksFromAnyIp(): void
    {
        $this->setConfig('rate_limit.max_attempts', '2');
        $this->setConfig('rate_limit.window_seconds', '300');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Insert 2 failed attempts for the target email from different IPs.
        // The test client always uses 127.0.0.1 which has zero attempts here,
        // so only the per-account check can trigger the block.
        $this->conn->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)',
            ['10.0.0.1', 'ratetest@example.com', $now]
        );
        $this->conn->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)',
            ['10.0.0.2', 'ratetest@example.com', $now]
        );

        // Attempt from the test client's IP (0 per-IP entries) — per-account count is 2 >= 2
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );
    }

    // H4 / AC3: with NO rate_limit config rows present (setUp deletes them), the code
    // default must be a non-zero fail-closed value — repeated failed logins are blocked.
    public function testDefaultRateLimitIsFailClosedWhenConfigAbsent(): void
    {
        // Intentionally do NOT set rate_limit.max_attempts; rely on the code default (10).
        // 10 failed attempts are allowed (count 0→10), the 11th is blocked.
        for ($i = 0; $i < 10; $i++) {
            $this->failedLogin();
            $this->client->followRedirect();
            $this->assertStringNotContainsString(
                'Too many login attempts',
                $this->client->getResponse()->getContent(),
                "Attempt #" . ($i + 1) . " should not yet be rate limited"
            );
        }

        // 11th attempt — count is 10 >= 10 (default), should be blocked.
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );
    }

    // AC1: throttling applies to the admin firewall too (both firewalls authenticate via
    // FormLoginAuthenticator, which the rate-limit listener hooks).
    public function testAdminLoginIsThrottled(): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');

        $this->removeAdmin();
        $admin = new Admin();
        $admin->setEmail('rateadmin@example.com');
        $admin->setName('Rate Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        try {
            for ($i = 0; $i < 3; $i++) {
                $this->failedAdminLogin();
            }

            // 4th attempt — count is 3 >= 3, blocked.
            $this->failedAdminLogin();
            $this->client->followRedirect();

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString(
                'Too many login attempts',
                $this->client->getResponse()->getContent()
            );
        } finally {
            $this->removeAdmin();
        }
    }

    private function failedAdminLogin(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email' => 'rateadmin@example.com',
            'password' => 'wrongpassword',
        ]);
    }

    private function removeAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'rateadmin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    public function testSuccessfulLoginDoesNotCountTowardFailureThreshold(): void
    {
        $this->setConfig('rate_limit.max_attempts', '2');
        $this->setConfig('rate_limit.window_seconds', '300');

        // 1 failed attempt (count = 1)
        $this->failedLogin();

        // 1 successful login — should not increment failure count
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'ratetest@example.com',
            'password' => 'correctpassword',
        ]);
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/dashboard', (string) $this->client->getResponse()->headers->get('Location'));

        // Log out so the next failed-login GET /login isn't bounced by the
        // already-authenticated redirect.
        $this->client->request('GET', '/logout');

        // 2nd failed attempt (check: count=1 < 2 → OK; count becomes 2)
        // This proves success did not count — otherwise count=2 already and this would be blocked
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        // Should show invalid-credentials error, NOT rate-limit error
        $this->assertStringNotContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );

        // Next attempt (check: count=2 >= 2) → BLOCKED
        $this->failedLogin();
        $this->client->followRedirect();

        $this->assertStringContainsString(
            'Too many login attempts',
            $this->client->getResponse()->getContent()
        );
    }

    /**
     * Issue #25: once an IP is throttled, the answer must not depend on whether the email belongs to an account.
     * The throttle used to run after the user checker, so an unknown email still got "Invalid credentials" while a
     * real account got "Too many login attempts" — a free, unlimited account-existence oracle.
     *
     * @return iterable<string,array{string,string}>
     */
    public static function realms(): iterable
    {
        yield 'user login' => ['/staff/login', 'ratetest@example.com'];
        yield 'admin login' => ['/admin/login', 'rateadmin@example.com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realms')]
    public function testAThrottledIpGetsTheSameAnswerForRealAndUnknownAccounts(string $loginPath, string $realEmail): void
    {
        $this->setConfig('rate_limit.max_attempts', '3');
        $this->setConfig('rate_limit.window_seconds', '300');
        $this->removeAdmin();
        $admin = new Admin();
        $admin->setEmail('rateadmin@example.com');
        $admin->setName('Rate Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        try {
            $answer = function (string $email) use ($loginPath): string {
                $this->client->request('GET', $loginPath);
                $this->client->submitForm('Sign in', ['email' => $email, 'password' => 'wrongpassword']);
                $this->client->followRedirect();

                return $this->client->getCrawler()->filter('.error')->count() > 0 ? trim($this->client->getCrawler()->filter('.error')->first()->text()) : '';
            };

            // Push this IP over the limit with junk failures for addresses that do not exist.
            for ($i = 0; $i < 3; ++$i) {
                $answer("nobody-$i@example.com");
            }

            $forUnknown = $answer('still-nobody@example.com');
            $forReal = $answer($realEmail);

            self::assertStringContainsString('Too many login attempts', $forUnknown, 'an unknown address is throttled too');
            self::assertSame($forUnknown, $forReal, 'a throttled IP must not learn which addresses have accounts');
        } finally {
            $this->removeAdmin();
        }
    }
}

