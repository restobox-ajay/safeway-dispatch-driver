<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-128 (review C39): adversarial tests for the impersonation authorization seams.
 *
 *  - The admin->admin impersonate route is class-level #[IsGranted('ROLE_SUPER_ADMIN')]; a plain
 *    admin must be forbidden (fails if the guard is downgraded to ROLE_ADMIN / removed).
 *  - The user firewall's ImpersonationAuthenticator only authenticates when a valid
 *    _impersonation_request handoff exists in the session; hitting /impersonate/start without it
 *    must authenticate nobody (fails if the payload check is removed).
 */
final class ImpersonationAuthzAdversarialTest extends WebTestCase
{
    use AuthenticationTestTrait;

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
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'impadv_%@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email, array $roles): Admin
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('ImpAdv ' . $email);
        $admin->setPassword(password_hash('adminpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles($roles);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }


    // AC2: a plain (non-superadmin) admin cannot start admin->admin impersonation.
    public function testNonSuperadminAdminCannotStartAdminImpersonation(): void
    {
        $actor  = $this->createAdmin('impadv_plain@example.com', []); // ROLE_ADMIN only
        $target = $this->createAdmin('impadv_target@example.com', []);

        $this->loginAsAdmin('impadv_plain@example.com');

        $this->client->request('POST', '/admin/superadmin/admins/' . $target->getId() . '/impersonate', [
            '_token' => 'bad', // no valid token; the ROLE_SUPER_ADMIN gate must reject first anyway
        ]);

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // The rejection must come from the ROLE_SUPER_ADMIN authorization gate, which runs BEFORE
        // the controller's CSRF check. If the class-level guard is downgraded/removed, the plain
        // admin falls through to that CSRF check ("Invalid CSRF token") — so asserting the denial is
        // NOT the CSRF one keeps the test sensitive to weakening the gate (AC5).
        $this->assertStringNotContainsString(
            'Invalid CSRF token',
            (string) $this->client->getResponse()->getContent(),
            'A non-superadmin must be stopped by the ROLE_SUPER_ADMIN gate before the CSRF check'
        );

        // No impersonation marker was written into the session.
        $this->assertNull(
            $this->client->getRequest()->getSession()->get('_impersonating_admin_as'),
            'A forbidden impersonation attempt must not set the impersonation marker'
        );
    }

    // AC2: /impersonate/start with no (forged/absent) handoff payload authenticates nobody.
    public function testImpersonateStartWithoutHandoffAuthenticatesNobody(): void
    {
        // Anonymous request straight to the user-firewall impersonation entry point.
        $this->client->request('GET', '/impersonate/start');

        $this->assertResponseRedirects('/admin/login');

        // Nobody is logged in: a protected user page still bounces to the user login.
        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
