<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLoginTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestAdmin();

        $admin = new Admin();
        $admin->setEmail('adminlogintest@example.com');
        $admin->setName('Admin Login Test');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestAdmin();
        parent::tearDown();
    }

    private function removeTestAdmin(): void
    {
        try {
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => 'adminlogintest@example.com']);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors — next setUp will re-try
        }
    }

    public function testAdminLoginFormRenders(): void
    {
        $this->client->request('GET', '/admin/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function testValidAdminLoginAuthenticatesAndRedirectsToDashboard(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email' => 'adminlogintest@example.com',
            'password' => 'adminpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/dashboard', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAlreadyAuthenticatedAdminVisitingLoginIsRedirectedToDashboard(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email' => 'adminlogintest@example.com',
            'password' => 'adminpassword',
        ]);
        $this->client->followRedirect(); // land on /admin/dashboard

        // Visiting /admin/login again while signed in bounces to the dashboard, not the form.
        $this->client->request('GET', '/admin/login');
        $this->assertResponseRedirects('/admin/dashboard');
    }

    public function testInvalidAdminLoginShowsError(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email' => 'adminlogintest@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');
    }

    public function testUnauthenticatedAccessToAdminRouteRedirectsToAdminLogin(): void
    {
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAdminSessionIsIndependentFromUserSession(): void
    {
        $user = new User();
        $user->setEmail('separationtest@example.com');
        $user->setName('Separation Test');
        $user->setPassword(password_hash('userpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        try {
            // Log in as regular user (user firewall)
            $this->client->request('GET', '/staff/login');
            $this->client->submitForm('Sign in', [
                'email' => 'separationtest@example.com',
                'password' => 'userpassword',
            ]);
            $this->assertResponseStatusCodeSame(302);
            $this->client->followRedirect(); // Land on /dashboard as user

            // User session must NOT grant access to admin routes
            $this->client->request('GET', '/admin/dashboard');
            $this->assertResponseStatusCodeSame(302);
            $this->assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
        } finally {
            $u = $this->em->getRepository(User::class)->findOneBy(['email' => 'separationtest@example.com']);
            if ($u) {
                $this->em->remove($u);
                $this->em->flush();
                $this->em->clear();
            }
        }
    }
}
