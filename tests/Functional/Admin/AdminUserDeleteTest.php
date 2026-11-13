<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserDeleteTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $admin = new Admin();
        $admin->setEmail('userdelete-admin@example.com');
        $admin->setName('Delete Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $admin->setRoles([]);
        $this->em->persist($admin);

        $superAdmin = new Admin();
        $superAdmin->setEmail('userdelete-superadmin@example.com');
        $superAdmin->setName('Delete SuperAdmin');
        $superAdmin->setPassword(self::hashTestPassword('superpass'));
        $superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->persist($superAdmin);

        $this->em->flush();
        $this->em->clear();
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
                "DELETE FROM \"user\" WHERE email LIKE 'userdelete-%@example.com'"
            );
            foreach (['userdelete-admin@example.com', 'userdelete-superadmin@example.com'] as $email) {
                $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
                if ($admin) {
                    $this->em->remove($admin);
                    $this->em->flush();
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, array $roles = []): User
    {
        return $this->createTestUser($email, 'Test User', 'userpass', 'active', $roles);
    }

    private function loginAsUser(string $email): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => 'userpass',
        ]);
        $this->client->followRedirect();
    }

    private function submitDeleteForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/delete"]')->form();
        $this->client->submit($form);
    }

    // AC1 (ADR-020 / FEATURE-110): POST /admin/users/{id}/delete SOFT-deletes — the row stays,
    // status flips to inactive. It is never physically removed.
    public function testDeleteSoftDeletesUser(): void
    {
        $user = $this->createUser('userdelete-remove@example.com');
        $id   = $user->getId();

        $this->loginAsAdmin('userdelete-admin@example.com');
        $this->submitDeleteForm($id);

        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        $deleted = $this->em->getRepository(User::class)->find($id);
        $this->assertNotNull($deleted, 'Soft delete must keep the users row');
        $this->assertSame('inactive', $deleted->getStatus());
    }

    // AC2: a soft-deleted (inactive) user's session is terminated on the next request.
    public function testDeletedUserSessionIsTerminatedOnNextRequest(): void
    {
        $this->createUser('userdelete-session@example.com');

        $this->loginAsUser('userdelete-session@example.com');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // Soft delete: flip status to inactive (same result as the delete endpoint), the row stays.
        $this->em->getConnection()->executeStatement(
            "UPDATE \"user\" SET status = 'inactive' WHERE email = 'userdelete-session@example.com'"
        );
        $this->em->clear();

        // Next request: UserChecker::checkPostAuth throws DisabledException → redirect to /login.
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // AC3 (ADR-020): a soft-deleted user still appears in the admin management list — but marked
    // inactive. The admin management view intentionally shows all statuses (it is how an admin
    // reactivates the account); only authentication is blocked.
    public function testSoftDeletedUserStillAppearsInListAsInactive(): void
    {
        $user = $this->createUser('userdelete-list@example.com');
        $id   = $user->getId();

        $this->loginAsAdmin('userdelete-admin@example.com');

        // Confirm user appears in list before deletion
        $this->client->request('GET', '/admin/users');
        $this->assertStringContainsString('userdelete-list@example.com', (string) $this->client->getResponse()->getContent());

        $this->submitDeleteForm($id);
        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        // Row is retained: still listed, now flagged inactive.
        $this->assertStringContainsString('userdelete-list@example.com', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $this->assertSame('inactive', $this->em->getRepository(User::class)->find($id)->getStatus());
    }
}
