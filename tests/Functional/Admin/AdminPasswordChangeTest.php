<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two password-change forms added on top of the emailed reset link (ADR-073): "Change My Password" for any
 * logged-in admin, and "Set Password" for tech support on another admin. Both are thin adapters over
 * AdminPasswordGate (rules proven in AdminPasswordGateTest); this proves the HTTP edges: who can reach them,
 * CSRF/forms, and what the person sees.
 */
final class AdminPasswordChangeTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private const NEW = 'a-Brand-new-passphrase-9';

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
            $db = $this->em->getConnection();
            $db->executeStatement("DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE 'apc-%@example.com')");
            $db->executeStatement("DELETE FROM admin WHERE email LIKE 'apc-%@example.com'");
            $db->executeStatement('DELETE FROM endpoint_rate_limits');
            $db->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function passwordIs(string $email, string $plain): bool
    {
        $this->em->clear();

        return password_verify($plain, $this->em->getRepository(Admin::class)->findOneBy(['email' => $email])->getPassword());
    }

    // ------------------------------------------------------------------ change my password

    public function testAnonymousIsSentToLogin(): void
    {
        $this->client->request('GET', '/admin/password');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAnyAdminCanChangeTheirOwnPasswordAndMustLogInAgain(): void
    {
        $this->createTestAdmin('apc-me@example.com');
        $this->loginAsAdmin('apc-me@example.com');

        $this->client->request('GET', '/admin/password');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Change Password', [
            'current_password' => 'adminpass',
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        self::assertResponseRedirects('/admin/login');
        self::assertTrue($this->passwordIs('apc-me@example.com', self::NEW));
        // Every session was closed: the panel now sends them to the login page.
        $this->client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
        // ...and the new password works.
        $this->loginAsAdmin('apc-me@example.com', self::NEW, followRedirect: false);
        self::assertResponseRedirects();
    }

    public function testAWrongCurrentPasswordIsRefusedAndNothingChanges(): void
    {
        $this->createTestAdmin('apc-me@example.com');
        $this->loginAsAdmin('apc-me@example.com');

        $this->client->request('GET', '/admin/password');
        $this->client->submitForm('Change Password', [
            'current_password' => 'wrong',
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.error', 'current password is incorrect');
        self::assertTrue($this->passwordIs('apc-me@example.com', 'adminpass'));
    }

    public function testChangeMyPasswordRefusesABadCsrfToken(): void
    {
        $this->createTestAdmin('apc-me@example.com');
        $this->loginAsAdmin('apc-me@example.com');

        $this->client->request('POST', '/admin/password', [
            '_token' => 'bogus',
            'current_password' => 'adminpass',
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        self::assertSelectorTextContains('.error', 'Invalid CSRF token');
        self::assertTrue($this->passwordIs('apc-me@example.com', 'adminpass'));
    }

    public function testChangeMyPasswordIsThrottled(): void
    {
        $this->createTestAdmin('apc-me@example.com');
        $this->loginAsAdmin('apc-me@example.com');

        // The default limit is a handful of attempts per window; hammer past it.
        for ($i = 0; $i < 12; $i++) {
            $this->client->request('GET', '/admin/password');
            $this->client->submitForm('Change Password', ['current_password' => 'wrong', 'new_password' => self::NEW, 'confirm_password' => self::NEW]);
        }

        self::assertSelectorTextContains('.error', 'Too many attempts');
        self::assertTrue($this->passwordIs('apc-me@example.com', 'adminpass'));
    }

    public function testTheChangeMyPasswordLinkIsInEveryAdminsNav(): void
    {
        $this->createTestAdmin('apc-me@example.com');
        $this->loginAsAdmin('apc-me@example.com');

        $crawler = $this->client->request('GET', '/admin/dashboard');

        self::assertGreaterThan(0, $crawler->filter('nav a[href="/admin/password"]')->count());
    }

    // ------------------------------------------------------------------ tech support sets another's

    public function testTechSupportSetsAnotherAdminsPasswordFromTheAdminsList(): void
    {
        $target = $this->createTestAdmin('apc-target@example.com');
        $this->loginAsEnrolledTechSupport('apc-ts@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        $link = $crawler->filter('a[href="/admin/superadmin/admins/' . $target->getId() . '/password"]');
        self::assertSame(1, $link->count(), 'tech support sees Set Password on the row');
        $ownId = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'apc-ts@example.com'])->getId();
        self::assertSame(0, $crawler->filter('a[href="/admin/superadmin/admins/' . $ownId . '/password"]')->count(), 'no Set Password on your own row');

        $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Set Password', ['new_password' => self::NEW, 'confirm_password' => self::NEW]);

        self::assertResponseRedirects('/admin/superadmin/admins');
        self::assertTrue($this->passwordIs('apc-target@example.com', self::NEW));
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAssociative("SELECT actor, context FROM audit_log WHERE action = 'admin.password_set'");
        self::assertSame(['actor' => 'apc-ts@example.com', 'context' => 'apc-target@example.com'], $row);
    }

    public function testNoSetPasswordLinkOnAnInactiveAdminsRow(): void
    {
        $active = $this->createTestAdmin('apc-active@example.com');
        $inactive = $this->createTestAdmin('apc-gone@example.com', status: 'inactive');
        $this->loginAsEnrolledTechSupport('apc-ts@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');

        self::assertSame(1, $crawler->filter('a[href="/admin/superadmin/admins/' . $active->getId() . '/password"]')->count());
        self::assertSame(0, $crawler->filter('a[href="/admin/superadmin/admins/' . $inactive->getId() . '/password"]')->count(), 'the gate refuses inactive admins, so no link');
    }

    public function testTechSupportSeesAMismatchAndNothingChanges(): void
    {
        $target = $this->createTestAdmin('apc-target@example.com');
        $this->loginAsEnrolledTechSupport('apc-ts@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/password');
        $this->client->submitForm('Set Password', ['new_password' => self::NEW, 'confirm_password' => 'different']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.error', 'do not match');
        self::assertTrue($this->passwordIs('apc-target@example.com', 'adminpass'));
    }

    public function testSetPasswordRefusesABadCsrfToken(): void
    {
        $target = $this->createTestAdmin('apc-target@example.com');
        $this->loginAsEnrolledTechSupport('apc-ts@example.com');

        $this->client->request('POST', '/admin/superadmin/admins/' . $target->getId() . '/password', [
            '_token' => 'bogus',
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->passwordIs('apc-target@example.com', 'adminpass'));
    }

    public function testASuperadminCannotSetAPasswordAndSeesNoLinkForIt(): void
    {
        $target = $this->createTestAdmin('apc-target@example.com');
        $this->createTestAdmin('apc-super@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('apc-super@example.com');

        $crawler = $this->client->request('GET', '/admin/superadmin/admins');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href*="/admins/"][href$="/password"]')->count(), 'no Set Password link for a superadmin');
        // The emailed-link reset stays available to them.
        self::assertGreaterThan(0, $crawler->filter('.admin-reset-password-btn')->count());

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/password');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/superadmin/admins/' . $target->getId() . '/password', [
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->passwordIs('apc-target@example.com', 'adminpass'));
    }

    public function testAPlainAdminCannotReachTheSetPasswordPage(): void
    {
        $target = $this->createTestAdmin('apc-target@example.com');
        $this->createTestAdmin('apc-plain@example.com');
        $this->loginAsAdmin('apc-plain@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/password');

        self::assertResponseStatusCodeSame(403);
    }
}
