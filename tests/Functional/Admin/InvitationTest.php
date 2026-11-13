<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvitationTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestAdmin('invite-admin@example.com', 'Invite Admin');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                "DELETE FROM invitations WHERE email LIKE '%@example.com'"
            );
            $this->em->getConnection()->executeStatement(
                "DELETE FROM \"user\" WHERE email LIKE 'invite-%@example.com' OR email LIKE 'invited%@example.com'"
            );
            $this->em->getConnection()->executeStatement(
                "DELETE FROM config WHERE config_key = 'registration.mode'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'invite-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    private function setRegistrationMode(string $mode): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('registration.mode', ?)",
            [$mode]
        );
    }

    private function insertInvitation(string $email, string $plaintextToken, string $expiresOffset = '+7 days'): void
    {
        $tokenHash = hash('sha256', $plaintextToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresOffset));
        $createdAt = date('Y-m-d H:i:s');

        $this->em->getConnection()->executeStatement(
            "INSERT INTO invitations (email, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)",
            [$email, $tokenHash, $expiresAt, $createdAt]
        );
    }

    // AC1: Admin can send an invitation from /admin/users/invite (email field)
    public function testAdminCanSendInvitationFromInvitePage(): void
    {
        $this->loginAsAdmin('invite-admin@example.com');
        $this->client->request('GET', '/admin/users/invite');
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('name="email"', $content);

        $this->client->submitForm('Send Invitation', [
            'email' => 'invite-recipient@example.com',
        ]);

        $this->assertEmailCount(1);

        $count = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM invitations WHERE email = ?",
            ['invite-recipient@example.com']
        );
        $this->assertSame(1, $count);
    }

    // AC2: Invitation email contains a unique expiring token link to /register?token=X
    public function testInvitationEmailContainsUniqueTokenLink(): void
    {
        $this->loginAsAdmin('invite-admin@example.com');
        $this->client->request('GET', '/admin/users/invite');
        $this->client->submitForm('Send Invitation', [
            'email' => 'invite-tokencheck@example.com',
        ]);

        $this->assertEmailCount(1);

        $email = $this->getMailerMessage();
        $body  = $email->getHtmlBody();

        $this->assertStringContainsString('/register?token=', $body);
    }

    // AC3: Clicking a valid invite link shows a registration form with email pre-filled
    public function testValidInviteTokenShowsRegistrationFormWithEmailPrefilled(): void
    {
        $this->setRegistrationMode('invitation-only');

        $plaintextToken = 'validtoken_ac3_abcdef';
        $this->insertInvitation('invite-prefill@example.com', $plaintextToken);

        $this->client->request('GET', '/register?token=' . $plaintextToken);
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('invite-prefill@example.com', $content);
    }

    // AC4: Completing the form creates the user account
    public function testCompletingInvitationFormCreatesUserAccount(): void
    {
        $this->setRegistrationMode('invitation-only');

        $plaintextToken = 'validtoken_ac4_xyz789';
        $this->insertInvitation('invited-ac4@example.com', $plaintextToken);

        $this->client->request('GET', '/register?token=' . $plaintextToken);
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Register', [
            'email'    => 'invited-ac4@example.com',
            'name'     => 'Invited Person',
            'password' => 'securepass123',
        ]);

        $userCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM \"user\" WHERE email = ?",
            ['invited-ac4@example.com']
        );
        $this->assertSame(1, $userCount);

        $usedAt = $this->em->getConnection()->fetchOne(
            "SELECT used_at FROM invitations WHERE token_hash = ?",
            [hash('sha256', $plaintextToken)]
        );
        $this->assertNotNull($usedAt, 'Invitation should be marked used after registration.');
    }

    // AC5: Expired invite tokens are rejected with a clear error message
    public function testExpiredTokenIsRejectedWithClearError(): void
    {
        $this->setRegistrationMode('invitation-only');

        $plaintextToken = 'expiredtoken_ac5_abc';
        $this->insertInvitation('invite-expired@example.com', $plaintextToken, '-1 hour');

        $this->client->request('GET', '/register?token=' . $plaintextToken);
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('expired', strtolower($content));
    }

    // C6 / AC1+AC2: resending an already-used invitation is rejected (web); token unchanged, no email
    public function testResendUsedInvitationIsRejected(): void
    {
        $oldPlaintext = 'usedtoken_web_donotresurrect';
        $oldTokenHash = hash('sha256', $oldPlaintext);
        // Used AND expired — the resurrection hole: expiry gate would otherwise pass.
        $this->em->getConnection()->executeStatement(
            "INSERT INTO invitations (email, token_hash, expires_at, used_at, created_at) VALUES (?, ?, ?, ?, ?)",
            [
                'invite-usedweb@example.com',
                $oldTokenHash,
                date('Y-m-d H:i:s', strtotime('-1 hour')),
                date('Y-m-d H:i:s', strtotime('-30 minutes')),
                date('Y-m-d H:i:s', strtotime('-2 days')),
            ]
        );

        $id = (int) $this->em->getConnection()->fetchOne(
            "SELECT id FROM invitations WHERE email = ?",
            ['invite-usedweb@example.com']
        );

        $this->loginAsAdmin('invite-admin@example.com');
        $this->client->request('GET', '/admin/users/invitations');
        $this->client->submitForm('Resend');

        $newTokenHash = $this->em->getConnection()->fetchOne(
            "SELECT token_hash FROM invitations WHERE id = ?",
            [$id]
        );

        $this->assertSame($oldTokenHash, $newTokenHash, 'A used invitation must not be regenerated.');
        $this->assertEmailCount(0, 'No renewal email may be sent for a used invitation.');
    }

    // C6 / AC3: registering a mismatched email under a valid invite is rejected; no account created
    public function testMismatchedEmailUnderInviteIsRejected(): void
    {
        $this->setRegistrationMode('invitation-only');

        $plaintextToken = 'bindtoken_mismatch_abc';
        $this->insertInvitation('invited-bound@example.com', $plaintextToken);

        $this->client->request('GET', '/register?token=' . $plaintextToken);
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Register', [
            'email'    => 'attacker-chosen@example.com',
            'name'     => 'Attacker',
            'password' => 'securepass123',
        ]);

        $attackerCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM \"user\" WHERE email = ?",
            ['attacker-chosen@example.com']
        );
        $this->assertSame(0, $attackerCount, 'A mismatched email must not create an account.');

        $usedAt = $this->em->getConnection()->fetchOne(
            "SELECT used_at FROM invitations WHERE token_hash = ?",
            [hash('sha256', $plaintextToken)]
        );
        $this->assertNull($usedAt, 'Invitation must not be consumed by a rejected mismatched registration.');
    }

    // AC6: Admin can resend an invitation, generating a new token and invalidating the old one
    public function testAdminCanResendInvitationGeneratingNewToken(): void
    {
        $oldPlaintext = 'oldtoken_ac6_tobereplaced';
        $oldTokenHash = hash('sha256', $oldPlaintext);
        $this->insertInvitation('invite-resend@example.com', $oldPlaintext, '-1 hour');

        $id = (int) $this->em->getConnection()->fetchOne(
            "SELECT id FROM invitations WHERE email = ?",
            ['invite-resend@example.com']
        );

        $this->loginAsAdmin('invite-admin@example.com');
        $this->client->request('GET', '/admin/users/invitations');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Resend');

        $newTokenHash = $this->em->getConnection()->fetchOne(
            "SELECT token_hash FROM invitations WHERE id = ?",
            [$id]
        );

        $this->assertNotSame($oldTokenHash, $newTokenHash, 'Token hash should change after resend.');
        $this->assertEmailCount(1);
    }
}
