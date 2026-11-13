<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Service\TotpService;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ROLE_TECH_SUPPORT (ADR-050 / FEATURE-149): a maintainer tier with superadmin powers via
 * role_hierarchy that is invisible to every non-tech-support admin — including superadmins —
 * on all admin-management surfaces. Tech-support admins see each other. A hidden target must
 * be indistinguishable from a nonexistent id (404), and the role must be unassignable (and
 * unadvertised) for non-tech-support viewers.
 */
final class TechSupportVisibilityTest extends WebTestCase
{
    use AuthenticationTestTrait;

    /** A fixed enrolment secret so tech-support logins can clear the mandatory-2FA gate. */
    private const TS_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

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
            $this->em->getConnection()->executeStatement(
                "DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE 'techsup-%@example.com')"
            );
            $this->em->getConnection()->executeStatement("DELETE FROM endpoint_rate_limits");
            $this->em->getConnection()->executeStatement("DELETE FROM admin WHERE email LIKE 'techsup-%@example.com'");
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function reload(int $id): Admin
    {
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->find($id);
    }

    /**
     * Create a tech-support admin already enrolled in TOTP, so a subsequent login can clear the
     * mandatory-2FA gate (ADR-050 / FEATURE-149 forces required 2FA for this role).
     */
    private function createTechSupport(string $email, string $name): Admin
    {
        $admin = $this->createTestAdmin($email, $name, roles: ['ROLE_TECH_SUPPORT']);
        $admin->setTotpSecret(self::TS_TOTP_SECRET);
        $admin->setIsTotpEnabled(true);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->find($admin->getId());
    }

    /**
     * Log in an enrolled tech-support admin and complete the mandatory 2FA challenge, leaving the
     * session verified so the viewer can navigate the admin panel.
     */
    private function loginAsTechSupport(string $email): void
    {
        $this->loginAsAdmin($email, followRedirect: false);
        // Enrolled admin: the first admin-panel request bounces to the 2FA challenge.
        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect(); // GET /admin/2fa/challenge (the form)
        $code = self::getContainer()->get(TotpService::class)->generateCode(self::TS_TOTP_SECRET);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    // ---------------------------------------------------------------- hierarchy

    public function testTechSupportReachesSuperadminSurfaces(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $this->client->request('GET', '/admin/superadmin/admins');
        self::assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- list visibility

    public function testTechSupportHiddenFromSuperadminList(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsAdmin('techsup-super@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('techsup-ts1@example.com', $crawler->html());
        self::assertStringContainsString('techsup-super@example.com', $crawler->html());
    }

    public function testTechSupportAdminsSeeEachOtherInList(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->createTestAdmin('techsup-ts2@example.com', 'TS Two', roles: ['ROLE_TECH_SUPPORT']);
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('techsup-ts1@example.com', $crawler->html());
        self::assertStringContainsString('techsup-ts2@example.com', $crawler->html());
        self::assertStringContainsString('techsup-super@example.com', $crawler->html());
    }

    // ---------------------------------------------------------------- per-id 404 gating

    public function testEveryPerIdRouteIs404ForHiddenTargetIncludingControlProof(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $visible = $this->createTestAdmin('techsup-plain@example.com', 'Plain', roles: ['ROLE_ADMIN']);
        $tech    = $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsAdmin('techsup-super@example.com');

        // Control: the same routes resolve for a visible admin, so the 404s below are the
        // visibility gate, not a routing artifact.
        $this->client->request('GET', sprintf('/admin/superadmin/admins/%d/edit', $visible->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/superadmin/admins/%d/edit', $tech->getId()));
        self::assertResponseStatusCodeSame(404);

        foreach (['delete', 'reset-password', 'reset-2fa', 'impersonate'] as $action) {
            $this->client->request('POST', sprintf('/admin/superadmin/admins/%d/%s', $tech->getId(), $action));
            self::assertResponseStatusCodeSame(
                404,
                sprintf('POST %s for a hidden admin must 404 before any CSRF/guard logic.', $action)
            );
        }
    }

    public function testTechSupportCanEditFellowTechSupport(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $other = $this->createTestAdmin('techsup-ts2@example.com', 'TS Two', roles: ['ROLE_TECH_SUPPORT']);
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $this->client->request('GET', sprintf('/admin/superadmin/admins/%d/edit', $other->getId()));
        self::assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- role assignment

    public function testSuperadminSubmittingTechSupportRoleGetsPlainAdminAndNoHint(): void
    {
        $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('techsup-super@example.com');

        // The role is not advertised in the form...
        $crawler = $this->client->request('GET', '/admin/superadmin/admins/new');
        self::assertStringNotContainsString('ROLE_TECH_SUPPORT', $crawler->html());

        // ...and a forged submission coerces to ROLE_ADMIN exactly like any unknown string.
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/superadmin/admins/new', [
            '_token'   => $token,
            'email'    => 'techsup-forged@example.com',
            'name'     => 'Forged',
            'password' => 'ValidPassw0rd!',
            'role'     => 'ROLE_TECH_SUPPORT',
        ]);
        self::assertResponseRedirects('/admin/superadmin/admins');

        $created = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'techsup-forged@example.com']);
        self::assertNotNull($created);
        self::assertNotContains('ROLE_TECH_SUPPORT', $created->getRoles());
        self::assertContains('ROLE_ADMIN', $created->getRoles());

        // Belt and braces: clean the forged row (outside the techsup-% LIKE if renamed later).
        $this->em->getConnection()->executeStatement(
            "DELETE FROM admin WHERE email = 'techsup-forged@example.com'"
        );
    }

    public function testTechSupportCanCreateTechSupportViaUi(): void
    {
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsTechSupport('techsup-ts1@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins/new');
        self::assertStringContainsString('ROLE_TECH_SUPPORT', $crawler->html());

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/superadmin/admins/new', [
            '_token'   => $token,
            'email'    => 'techsup-ts3@example.com',
            'name'     => 'TS Three',
            'password' => 'ValidPassw0rd!',
            'role'     => 'ROLE_TECH_SUPPORT',
        ]);
        self::assertResponseRedirects('/admin/superadmin/admins');

        $created = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'techsup-ts3@example.com']);
        self::assertNotNull($created);
        self::assertContains('ROLE_TECH_SUPPORT', $created->getRoles());
    }

    // ---------------------------------------------------------------- lockout guard

    public function testLastVisibleSuperadminGuardIgnoresTechSupport(): void
    {
        $super = $this->createTestAdmin('techsup-super@example.com', 'Client Super', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTechSupport('techsup-ts1@example.com', 'TS One');
        $this->loginAsTechSupport('techsup-ts1@example.com');

        // The tech-support admin outranks a superadmin, but must NOT count as one for the
        // anti-lockout guard: the client's last visible superadmin stays protected even
        // though a (hidden) maintainer could technically recover access.
        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $deleteAction = sprintf('/admin/superadmin/admins/%d/delete', $super->getId());
        $token = $crawler->filter(sprintf('form[action$="%s"] input[name="_token"]', $deleteAction))->attr('value');
        $this->client->request('POST', $deleteAction, ['_token' => $token]);
        self::assertResponseRedirects('/admin/superadmin/admins');

        self::assertTrue($this->reload($super->getId())->isActive(), 'Last visible superadmin must survive the delete attempt.');
    }
}
