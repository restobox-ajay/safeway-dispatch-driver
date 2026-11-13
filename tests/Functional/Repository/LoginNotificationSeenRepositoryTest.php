<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\AdminLoginNotificationSeenRepository;
use App\Repository\LoginNotificationSeenRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #33: markSeen() swallowed the UNIQUE violation of a concurrent duplicate marker AFTER flush(), but
 * Doctrine closes the EntityManager on any flush failure — so the login request carried on with a closed EM and
 * the session / login-history listeners after it threw EntityManagerClosed (500, then logged out). Recording an
 * already-recorded marker must be a no-op that leaves the EntityManager usable, in both realms.
 */
final class LoginNotificationSeenRepositoryTest extends KernelTestCase
{
    private const OWNER_ID = 990033;

    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
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
        $this->conn->executeStatement('DELETE FROM login_notification_seen WHERE user_id = ?', [self::OWNER_ID]);
        $this->conn->executeStatement('DELETE FROM admin_login_notification_seen WHERE admin_id = ?', [self::OWNER_ID]);
    }

    public function testRecordingAUserMarkerAgainLeavesTheEntityManagerOpen(): void
    {
        $repo = self::getContainer()->get(LoginNotificationSeenRepository::class);

        $repo->markSeen(self::OWNER_ID, 'device-a');
        // The concurrent login that lost the race: its hasSeen() ran before the winner committed.
        $repo->markSeen(self::OWNER_ID, 'device-a');

        self::assertTrue($this->em->isOpen(), 'the rest of the login request must still be able to write');
        self::assertTrue($repo->hasSeen(self::OWNER_ID, 'device-a'));
        self::assertSame(1, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_notification_seen WHERE user_id = ? AND marker = ?',
            [self::OWNER_ID, 'device-a']
        ));
    }

    public function testRecordingAnAdminMarkerAgainLeavesTheEntityManagerOpen(): void
    {
        $repo = self::getContainer()->get(AdminLoginNotificationSeenRepository::class);

        $repo->markSeen(self::OWNER_ID, 'device-a');
        $repo->markSeen(self::OWNER_ID, 'device-a');

        self::assertTrue($this->em->isOpen(), 'the rest of the admin login request must still be able to write');
        self::assertTrue($repo->hasSeen(self::OWNER_ID, 'device-a'));
        self::assertSame(1, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM admin_login_notification_seen WHERE admin_id = ? AND marker = ?',
            [self::OWNER_ID, 'device-a']
        ));
    }
}
