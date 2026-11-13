<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-119 / review C27, AC3: an invitation token must not be consumable by two
 * concurrent registrations.
 *
 * Two parts:
 * - The atomic single-use primitive: InvitationRepository::claim() consumes an unused
 *   invite exactly once — true for the winner, false for the racing loser.
 * - The controller wiring: when claim() reports the invite already consumed, registration
 *   is rejected and no account is created. We reproduce the race window by stubbing
 *   findByTokenHash to return an unused-looking invite (as a stale concurrent read would)
 *   while claim() reports it already taken.
 */
final class InvitationRaceTest extends WebTestCase
{
    private const EMAIL = 'invrace@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
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
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM invitations WHERE email = ?', [self::EMAIL]);
            $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
            $conn->executeStatement("DELETE FROM config WHERE config_key = 'registration.mode'");
            $conn->executeStatement('DELETE FROM endpoint_rate_limits');
        } catch (\Throwable) {
        }
    }

    public function testClaimConsumesAnInviteExactlyOnce(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO invitations (email, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [self::EMAIL, hash('sha256', 'claim-token'), date('Y-m-d H:i:s', strtotime('+7 days')), date('Y-m-d H:i:s')]
        );
        $id = (int) $conn->fetchOne('SELECT id FROM invitations WHERE email = ?', [self::EMAIL]);

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);

        $this->assertTrue($repo->claim($id), 'The first claim wins.');
        $this->assertFalse($repo->claim($id), 'A second concurrent claim of the same invite loses.');

        $usedAt = $conn->fetchOne('SELECT used_at FROM invitations WHERE id = ?', [$id]);
        $this->assertNotNull($usedAt, 'The invite is marked used after the winning claim.');
    }

    public function testConcurrentRegistrationCannotConsumeInviteTwice(): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('registration.mode', 'invitation-only')"
        );

        // A stale concurrent read: the invite still looks unused to this request.
        $invite = new Invitation(self::EMAIL, hash('sha256', 'race-token'), new \DateTimeImmutable('+7 days'));
        $ref = new \ReflectionProperty(Invitation::class, 'id');
        $ref->setValue($invite, 999001);

        $stubRepo = $this->createStub(InvitationRepository::class);
        $stubRepo->method('findByTokenHash')->willReturn($invite);
        // The other concurrent registration already consumed it: the atomic claim loses.
        $stubRepo->method('claim')->willReturn(false);
        self::getContainer()->set(InvitationRepository::class, $stubRepo);

        $this->client->request('GET', '/register?token=race-token');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Register', [
            'email'    => self::EMAIL,
            'name'     => 'Racing Invitee',
            'password' => 'validpassword123',
        ]);

        // Rejected — no account created from a doubly-claimed invite.
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('.error');

        $userCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM "user" WHERE email = ?',
            [self::EMAIL]
        );
        $this->assertSame(0, $userCount, 'A losing claim must not create an account.');
    }
}
