<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationTest extends WebTestCase
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
            foreach (['evtest1@example.com', 'evtest2@example.com', 'evtest3@example.com', 'evtest4@example.com', 'evtest5@example.com'] as $email) {
                $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [$email]);
            }
            $conn->executeStatement("DELETE FROM config WHERE config_key = 'email_verification.mode'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUnverifiedUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Verify Test User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function setVerificationMode(string $mode): void
    {
        $configService = self::getContainer()->get(ConfigService::class);
        $configService->set('email_verification.mode', $mode);
    }

    // AC1: Verification email is sent after successful registration
    public function testVerificationEmailSentAfterRegistration(): void
    {
        $this->setVerificationMode('optional');

        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'evtest1@example.com',
            'name'     => 'EV Test User',
            'password' => 'password123',
        ]);

        $this->assertEmailCount(1);
        $messages = $this->getMailerMessages();
        $this->assertNotEmpty($messages);
        $this->assertEmailAddressContains($messages[0], 'To', 'evtest1@example.com');
    }

    // AC2: Clicking the verification link marks the user as verified
    public function testVerificationLinkMarksUserAsVerified(): void
    {
        $this->setVerificationMode('optional');

        $user = $this->createUnverifiedUser('evtest2@example.com');
        $userId = $user->getId();

        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $signatureComponents = $helper->generateSignature(
            'app_verify_email',
            (string) $userId,
            'evtest2@example.com',
            ['id' => $userId]
        );

        $signedUrl = $signatureComponents->getSignedUrl();
        $parsedUrl = parse_url($signedUrl);
        $path = $parsedUrl['path'] . '?' . $parsedUrl['query'];

        $this->client->request('GET', $path);
        $this->assertResponseRedirects('/staff/login');

        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $isVerified = $conn->fetchOne('SELECT is_verified FROM "user" WHERE id = ?', [$userId]);
        $this->assertSame(1, (int) $isVerified, 'User should be marked as verified after clicking the link');
    }

    // AC3: When verification mode=required, unverified user cannot log in
    public function testUnverifiedUserBlockedWhenModeRequired(): void
    {
        $this->setVerificationMode('required');
        $this->createUnverifiedUser('evtest3@example.com');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'evtest3@example.com',
            'password' => 'testpassword',
        ]);

        // Should redirect back to login (not to dashboard)
        $this->assertResponseRedirects('/staff/login');
        $this->client->followRedirect();

        // Error message must be visible
        $this->assertSelectorExists('.error');
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('verified', strtolower($content));
    }

    // AC4: Resend verification email link is available; resending works
    public function testResendVerificationEmail(): void
    {
        $this->setVerificationMode('optional');
        $this->createUnverifiedUser('evtest4@example.com');

        // Visit the resend form to establish a session and get the CSRF token
        $crawler = $this->client->request('GET', '/resend-verification');
        $this->assertResponseIsSuccessful();

        // Reset the mailer so we only count the resend
        self::getContainer()->get('mailer.message_logger_listener')->reset();

        // Submit the resend form
        $this->client->submitForm('Resend verification email', [
            'email' => 'evtest4@example.com',
        ]);

        $this->assertEmailCount(1);
        $messages = $this->getMailerMessages();
        $this->assertEmailAddressContains($messages[0], 'To', 'evtest4@example.com');

        // Resend link must also be present on the login page
        $this->client->request('GET', '/staff/login');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('resend-verification', $content);
    }

    // AC5: Tampered verification tokens are rejected with a clear error
    public function testTamperedTokenRejected(): void
    {
        $this->setVerificationMode('optional');
        $user = $this->createUnverifiedUser('evtest5@example.com');

        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $signatureComponents = $helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            'evtest5@example.com',
            ['id' => $user->getId()]
        );

        $signedUrl = $signatureComponents->getSignedUrl();
        $parsedUrl = parse_url($signedUrl);
        $path = $parsedUrl['path'] . '?' . $parsedUrl['query'];

        // Tamper with the signature parameter (VerifyEmailBundle uses 'signature' as hash param)
        $tamperedPath = preg_replace('/signature=([^&]+)/', 'signature=TAMPERED', $path);

        $this->client->request('GET', $tamperedPath);
        $this->assertResponseRedirects('/staff/login');
        $this->client->followRedirect();

        // Error message must be visible
        $this->assertSelectorExists('.error');

        // User should remain unverified
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $isVerified = $conn->fetchOne('SELECT is_verified FROM "user" WHERE id = ?', [$user->getId()]);
        $this->assertSame(0, (int) $isVerified, 'User should remain unverified after a tampered token attempt');
    }

}
