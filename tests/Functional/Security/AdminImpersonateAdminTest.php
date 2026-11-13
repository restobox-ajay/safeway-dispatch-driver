<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminImpersonateAdminTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
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
            $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'impersonate_admin_test_%'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email, array $roles = []): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Test Admin ' . $email);
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }


    // AC1: Superadmin sees an 'Impersonate' option on admin account detail pages
    public function testSuperadminSeesImpersonateOptionOnAdminPage(): void
    {
        $superadmin = $this->createAdmin('impersonate_admin_test_super@example.com', ['ROLE_SUPER_ADMIN']);
        $target     = $this->createAdmin('impersonate_admin_test_target@example.com', []);

        $this->loginAsAdmin($superadmin->getEmail());

        $this->client->request('GET', '/admin/superadmin/admins');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/admin/superadmin/admins/' . $target->getId() . '/impersonate"]');
    }

    // AC2: Superadmin can switch to act as an admin
    public function testSuperadminCanSwitchToActAsAdmin(): void
    {
        $superadmin = $this->createAdmin('impersonate_admin_test_super2@example.com', ['ROLE_SUPER_ADMIN']);
        $target     = $this->createAdmin('impersonate_admin_test_target2@example.com', []);

        $this->loginAsAdmin($superadmin->getEmail());

        // Submit impersonate form
        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $form    = $crawler->filter('form[action="/admin/superadmin/admins/' . $target->getId() . '/impersonate"]')->form();
        $this->client->submit($form);

        // Follow redirect to admin dashboard
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('admin-impersonation-banner', $content);
        $this->assertStringContainsString($target->getEmail(), $content);
    }

    // AC3: A regular admin does not see the impersonate option for other admin accounts
    public function testRegularAdminCannotSeeImpersonateOption(): void
    {
        $regularAdmin = $this->createAdmin('impersonate_admin_test_regular@example.com', []);

        $this->loginAsAdmin($regularAdmin->getEmail());

        $this->client->request('GET', '/admin/superadmin/admins');

        $this->assertResponseStatusCodeSame(403);
    }
}
