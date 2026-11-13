<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordPolicyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    private const POLICY_KEYS = [
        'password_policy.min_length',
        'password_policy.require_uppercase',
        'password_policy.require_number',
        'password_policy.require_symbol',
    ];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = self::getContainer()->get(Connection::class);
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
            foreach (self::POLICY_KEYS as $key) {
                $this->conn->executeStatement('DELETE FROM config WHERE config_key = ?', [$key]);
            }
            foreach (['policytest@example.com', 'policytest2@example.com'] as $email) {
                $this->conn->executeStatement('DELETE FROM password_reset_tokens WHERE email = ?', [$email]);
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($user) {
                    $this->em->remove($user);
                    $this->em->flush();
                }
            }
            // Registration is now throttled (FEATURE-114); keep the shared limiter table clean
            // so these repeated /register POSTs aren't blocked by cross-test accumulation.
            $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
            $this->em->clear();
        } catch (\Throwable) {}
    }

    private function setConfig(string $key, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [$key, $value]
        );
    }

    /**
     * Registration POST now requires a valid CSRF token (FEATURE-114). Fetch a real token
     * from the rendered form so these tests still exercise the password policy, not CSRF.
     */
    private function registerPost(array $data): void
    {
        $crawler = $this->client->request('GET', '/register');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/register', array_merge(['_token' => $token], $data));
    }

    /** AC1: Registration rejects passwords shorter than the configured minimum length */
    public function testRegistrationRejectsShortPassword(): void
    {
        $this->setConfig('password_policy.min_length', '12');

        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'short',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNull($user, 'User should not be created when password is too short');
    }

    /**
     * FEATURE-088 AC1/AC3: with NO policy config set, the hard minimum length
     * floor is still enforced — registration rejects a too-short password.
     */
    public function testHardMinimumLengthEnforcedWithEmptyConfig(): void
    {
        // cleanUp() in setUp already removed every policy config key, so the
        // admin minimum is effectively unset here.
        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'abc123!', // 7 characters — below the hard floor
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNull($user, 'User must not be created: password is below the hard minimum length even with no configured policy');
    }

    /**
     * FEATURE-088 AC2: registration rejects a password longer than the maximum
     * (bcrypt's 72-byte truncation boundary), with no policy config set.
     */
    public function testMaximumLengthEnforcedWithEmptyConfig(): void
    {
        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => str_repeat('a', 73), // 73 bytes — over the maximum
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNull($user, 'User must not be created: password exceeds the maximum length');
    }

    /** AC2: Registration rejects passwords missing a required character class */
    public function testRegistrationRejectsMissingRequiredCharacterClass(): void
    {
        $this->setConfig('password_policy.require_uppercase', '1');
        $this->setConfig('password_policy.require_number', '1');
        $this->setConfig('password_policy.require_symbol', '1');

        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'alllowercase',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNull($user, 'User should not be created when password fails character class requirements');
    }

    /** AC3: Password reset form enforces the same policy */
    public function testPasswordResetEnforcesPolicy(): void
    {
        $this->setConfig('password_policy.min_length', '12');

        $user = new User();
        $user->setEmail('policytest@example.com');
        $user->setName('Policy Test');
        $user->setPassword(password_hash('oldpassword123!', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash      = hash('sha256', $plaintextToken);
        $token          = new PasswordResetToken('policytest@example.com', $tokenHash, new \DateTimeImmutable('+1 hour'));
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('POST', '/reset-password/' . $plaintextToken, [
            'password' => 'short',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNotNull($user);
        $this->assertTrue(
            password_verify('oldpassword123!', $user->getPassword()),
            'Password should be unchanged when reset fails policy'
        );
    }

    /** AC4: Validation error messages identify which specific rule was violated */
    public function testValidationErrorMessagesIdentifySpecificRule(): void
    {
        $this->setConfig('password_policy.require_uppercase', '1');

        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'alllower1!',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('uppercase', strtolower($content));
    }

    /**
     * AC5: All policy rules are independently togglable — enabling only one rule blocks
     * passwords that violate that rule while passwords compliant with that rule pass.
     */
    public function testAllPolicyRulesAreIndependentlyTogglable(): void
    {
        // Only require_number is enabled; password with digits but no uppercase/symbol passes
        $this->setConfig('password_policy.require_number', '1');

        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'nodigitshere!',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNull($user, 'User blocked because require_number=1 and password has no digit');

        // Same rule, but password now satisfies it (has a digit); since other rules are NOT enabled, it passes
        $this->registerPost([
            'email'    => 'policytest@example.com',
            'name'     => 'Policy Test',
            'password' => 'hasadigit1',
        ]);
        $this->assertResponseRedirects();
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'policytest@example.com']);
        $this->assertNotNull($user, 'User should be created: require_number satisfied; require_uppercase/symbol disabled');
    }
}
