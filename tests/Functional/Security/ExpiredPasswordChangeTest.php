<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\TotpService;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-096 (review C1): the forced expired-password-change route must verify the current
 * password, must be unusable by a session that has not cleared its 2FA challenge, and must
 * only serve a genuinely expired user.
 */
final class ExpiredPasswordChangeTest extends WebTestCase
{
    use TwoFactorTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    private const EMAIL = 'expchange@example.com';

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
            $this->conn->executeStatement("DELETE FROM config WHERE config_key = 'password_policy.expiry_days'");
            $ids = $this->conn->fetchFirstColumn('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);
            foreach ($ids as $id) {
                $this->conn->executeStatement('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
                // The change date lives in the password_meta satellite now (FEATURE-145).
                $this->conn->executeStatement('DELETE FROM password_meta WHERE user_id = ?', [$id]);
            }
            $this->conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(bool $withTotp): void
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('Expiry Change');
        $user->setPassword(password_hash('CorrectPass1!', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus('active');
        $secret = $withTotp ? self::getContainer()->get(TotpService::class)->generateSecret() : null;
        $this->em->persist($user);
        $this->em->flush();
        // The change date lives in the auth-password-policy-bundle satellite (password_meta) now (FEATURE-145).
        $this->conn->executeStatement(
            'INSERT INTO password_meta (user_id, password_changed_at) VALUES (?, ?)',
            [(int) $user->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
        if ($secret !== null) {
            $this->enableTwoFactor($user, $secret);
        }
        $this->em->clear();
    }

    private function setExpiryDays(int $days): void
    {
        $this->conn->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('password_policy.expiry_days', ?)",
            [(string) $days]
        );
    }

    private function expirePassword(): void
    {
        $date = (new \DateTimeImmutable())->modify('-60 days')->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE password_meta SET password_changed_at = ? WHERE user_id = (SELECT id FROM "user" WHERE email = ?)',
            [$date, self::EMAIL]
        );
    }

    private function currentHash(): string
    {
        return (string) $this->conn->fetchOne('SELECT password FROM "user" WHERE email = ?', [self::EMAIL]);
    }

    private function login(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::EMAIL,
            'password' => 'CorrectPass1!',
        ]);
    }

    // AC1/AC4: the wrong current password is rejected and the stored password is unchanged.
    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->createUser(withTotp: false);
        $this->setExpiryDays(30);
        $this->expirePassword();
        $this->login();

        $hashBefore = $this->currentHash();

        // GET the forced-change form (expired user, no 2FA → reachable).
        $this->client->request('GET', '/account/change-expired-password');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Update Password', [
            'current_password' => 'WrongPass9!',
            'password'         => 'BrandNew2Pass!',
        ]);

        // Stays on the page with an error; the password must not have changed.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $this->assertSame($hashBefore, $this->currentHash(), 'Password must not change without the correct current password');
    }

    // AC1 (positive) / AC3: with the correct current password a genuinely expired user completes the change.
    public function testCorrectCurrentPasswordAppliesChange(): void
    {
        $this->createUser(withTotp: false);
        $this->setExpiryDays(30);
        $this->expirePassword();
        $this->login();

        $hashBefore = $this->currentHash();

        $this->client->request('GET', '/account/change-expired-password');
        $this->assertResponseIsSuccessful();

        $before = $this->auditRows(self::EMAIL, 'password_change', 'expired');
        $this->client->submitForm('Update Password', [
            'current_password' => 'CorrectPass1!',
            'password'         => 'BrandNew2Pass!',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertSame($before + 1, $this->auditRows(self::EMAIL, 'password_change', 'expired'), 'the forced change is audited (issue #18)');
        $this->assertNotSame($hashBefore, $this->currentHash(), 'Password must change once the current password is verified');
    }

    // AC2: a session that has not cleared the 2FA challenge cannot rotate the password via this route.
    public function testPre2faSessionCannotChangePassword(): void
    {
        $this->createUser(withTotp: true);
        $this->setExpiryDays(30);
        $this->expirePassword();
        $this->login(); // authenticated but pre-2FA (challenge not passed)

        $hashBefore = $this->currentHash();

        $this->client->request('POST', '/account/change-expired-password', [
            'current_password' => 'CorrectPass1!',
            'password'         => 'BrandNew2Pass!',
        ]);

        // The 2FA gate short-circuits before the password is ever touched.
        $this->assertResponseRedirects('/2fa/challenge');
        $this->assertSame($hashBefore, $this->currentHash(), 'A pre-2FA session must not change the password');
    }

    // Title "gate on real expiry": a non-expired user has no business on the forced-change page.
    public function testNonExpiredUserIsRedirectedAway(): void
    {
        $this->createUser(withTotp: false);
        $this->setExpiryDays(30); // password_changed_at is "now" → not expired
        $this->login();

        $this->client->request('GET', '/account/change-expired-password');
        $this->assertResponseRedirects('/account/password');
    }

    /** Issue #18: audit rows written for one actor+action (the request-time count, so earlier rows can't fake a pass). */
    private function auditRows(string $actor, string $action, ?string $context = null): int
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');

        return (int) ($context === null
            ? $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success'", [$actor, $action])
            : $conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE actor = ? AND action = ? AND outcome = 'success' AND context = ?", [$actor, $action, $context]));
    }
}
