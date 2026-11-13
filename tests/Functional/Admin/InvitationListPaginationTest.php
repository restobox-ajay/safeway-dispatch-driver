<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\Invitation;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvitationListPaginationTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const PAGE_SIZE = 10;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $this->createTestAdmin('invitepage-admin@example.com', 'Invite Page Admin');
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
                "DELETE FROM invitations WHERE email LIKE 'invitepage-%@example.com'"
            );
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'invitepage-admin@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {}
    }

    private function seedInvitations(int $count): void
    {
        $expiresAt = new \DateTimeImmutable('+7 days');
        for ($i = 1; $i <= $count; $i++) {
            $invitation = new Invitation(
                sprintf('invitepage-%02d@example.com', $i),
                hash('sha256', "token-{$i}"),
                $expiresAt
            );
            $this->em->persist($invitation);
        }
        $this->em->flush();
        $this->em->clear();
    }

    // AC1/AC2: first page is bounded to PAGE_SIZE rows and shows pagination controls
    public function testFirstPageIsBoundedAndShowsPaginationControls(): void
    {
        $this->seedInvitations(12);
        $this->loginAsAdmin('invitepage-admin@example.com');

        $crawler = $this->client->request('GET', '/admin/users/invitations?page=1');
        $this->assertResponseIsSuccessful();

        $this->assertCount(
            self::PAGE_SIZE,
            $crawler->filter('td.invitation-email'),
            'Page 1 must show exactly PAGE_SIZE invitation rows'
        );

        $this->assertCount(
            1,
            $crawler->filter('nav[aria-label="Pagination"]'),
            'Pagination controls must be rendered when there is more than one page'
        );
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=2', $content);
    }

    // AC1/AC2: the second page shows the remainder
    public function testSecondPageShowsRemainingInvitations(): void
    {
        $this->seedInvitations(12);
        $this->loginAsAdmin('invitepage-admin@example.com');

        $crawler = $this->client->request('GET', '/admin/users/invitations?page=2');
        $this->assertResponseIsSuccessful();

        $this->assertCount(
            2,
            $crawler->filter('td.invitation-email'),
            'Page 2 must show the remaining 2 invitations'
        );
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('page=1', $content);
    }
}
