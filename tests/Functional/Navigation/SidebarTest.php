<?php

declare(strict_types=1);

namespace App\Tests\Functional\Navigation;

use App\Entity\Admin;
use App\Entity\User;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The role-aware left sidebar (templates/base.html.twig + layout/_*_nav.html.twig)
 * must expose every page each role can reach, must distinguish the two separate
 * firewalls, and must not leak across them.
 */
final class SidebarTest extends WebTestCase
{
    use AuthenticationTestTrait;

    /** A fixed enrolment secret so tech-support logins can clear the mandatory-2FA gate. */
    private const TS_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $user = new User();
        $user->setEmail('sidebar-user@example.com');
        $user->setName('Sidebar User');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setIsVerified(true);
        $this->em->persist($user);

        $superAdmin = new Admin();
        $superAdmin->setEmail('sidebar-superadmin@example.com');
        $superAdmin->setName('Sidebar SuperAdmin');
        $superAdmin->setPassword(password_hash('superpass', PASSWORD_BCRYPT, ['cost' => 4]));
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
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM \"user\" WHERE email = 'sidebar-user@example.com'");
        $conn->executeStatement("DELETE FROM admin WHERE email IN ('sidebar-superadmin@example.com', 'sidebar-techsupport@example.com')");
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
    }

    /**
     * Create a tech-support admin already enrolled in TOTP, log in, and complete the mandatory
     * 2FA challenge (ADR-050 / FEATURE-149), leaving the session verified.
     */
    private function loginAsTechSupport(): void
    {
        $admin = new Admin();
        $admin->setEmail('sidebar-techsupport@example.com');
        $admin->setName('Sidebar Tech Support');
        $admin->setPassword(self::hashTestPassword('techsupportpass'));
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setTotpSecret(self::TS_TOTP_SECRET);
        $admin->setIsTotpEnabled(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        $this->loginAsAdmin('sidebar-techsupport@example.com', 'techsupportpass', followRedirect: false);
        // Enrolled admin: the first admin-panel request bounces to the 2FA challenge.
        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect(); // GET /admin/2fa/challenge (the form)
        $code = self::getContainer()->get(TotpService::class)->generateCode(self::TS_TOTP_SECRET);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    public function testUserDashboardShowsUserSidebarWithAllAccountLinks(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'sidebar-user@example.com',
            'password' => 'userpass',
        ]);
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar');

        foreach (['/dashboard', '/account/settings', '/account/password', '/account/sessions', '/account/login-history', '/account/tokens', '/account/2fa/setup', '/logout'] as $href) {
            $this->assertSelectorExists(
                sprintf('aside.sidebar a[href="%s"]', $href),
                sprintf('User sidebar should link to %s', $href)
            );
        }

        // The user sidebar must not expose admin destinations.
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/users"]');
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/config"]');
    }

    public function testAdminDashboardShowsAdminSidebarWithAllManageLinks(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'sidebar-superadmin@example.com',
            'password' => 'superpass',
        ]);
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar');

        foreach (['/admin/dashboard', '/admin/users', '/admin/users/invite', '/admin/users/invitations', '/admin/audit-log', '/admin/config', '/admin/superadmin/admins', '/admin/logout'] as $href) {
            $this->assertSelectorExists(
                sprintf('aside.sidebar a[href="%s"]', $href),
                sprintf('Admin sidebar should link to %s', $href)
            );
        }

        // The admin sidebar must not expose the user firewall's pages.
        $this->assertSelectorNotExists('aside.sidebar a[href="/account/settings"]');

        // ROLE_TECH_SUPPORT is a hidden maintainer tier (ADR-050): a plain superadmin — even
        // though role_hierarchy makes ROLE_SUPER_ADMIN a subset of it — must not see the DB
        // console link, since only an admin actually HOLDING ROLE_TECH_SUPPORT does.
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/db"]');
        $this->assertSelectorNotExists('aside.sidebar a[href="/admin/htaccess-lock"]');

        // The API docs are for every admin class (any admin role can hold an admin API token).
        $this->assertSelectorExists('aside.sidebar a[href="/admin/api-docs"]');
    }

    public function testTechSupportSidebarShowsDbConsoleLink(): void
    {
        $this->loginAsTechSupport();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('aside.sidebar a[href="/admin/db"]', 'Tech-support sidebar should link to the DB console.');
        $this->assertSelectorExists('aside.sidebar a[href="/admin/htaccess-lock"]', 'Tech-support sidebar should link to the Htaccess Lock.');
    }

    public function testUnauthenticatedLoginPageHasNoSidebar(): void
    {
        $this->client->request('GET', '/staff/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('aside.sidebar');
    }
}
