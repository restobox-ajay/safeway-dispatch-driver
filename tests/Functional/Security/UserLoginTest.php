<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserLoginTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestUser();

        $user = new User();
        $user->setEmail('logintest@example.com');
        $user->setName('Login Test');
        $user->setPassword(password_hash('correctpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'logintest@example.com']);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors — next setUp will re-try
        }
    }

    public function testLoginFormRenders(): void
    {
        $this->client->request('GET', '/staff/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function testLoginPageOffersBothPasswordResetAndResendVerificationLinks(): void
    {
        $crawler = $this->client->request('GET', '/staff/login');

        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/forgot-password"]')->count(),
            'Login page should link to the password reset page.'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/resend-verification"]')->count(),
            'Login page should keep the resend-verification link.'
        );
    }

    public function testValidLoginAuthenticatesAndRedirects(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'logintest@example.com',
            'password' => 'correctpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/dashboard', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAlreadyAuthenticatedUserVisitingLoginIsRedirectedToDashboard(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'logintest@example.com',
            'password' => 'correctpassword',
        ]);
        $this->client->followRedirect(); // land on /dashboard

        // Visiting /login again while signed in bounces to the dashboard, not the form.
        $this->client->request('GET', '/staff/login');
        $this->assertResponseRedirects('/dashboard');
    }

    public function testInvalidLoginShowsErrorAndDoesNotAuthenticate(): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'logintest@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // Confirm still unauthenticated — dashboard redirects to login
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUnauthenticatedAccessToProtectedRouteRedirectsToLogin(): void
    {
        $this->client->request('GET', '/dashboard');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLogoutInvalidatesSessionAndRedirectsToLogin(): void
    {
        // Log in first
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email' => 'logintest@example.com',
            'password' => 'correctpassword',
        ]);
        $this->client->followRedirect(); // Lands on /dashboard

        // Logout
        $this->client->request('GET', '/logout');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));

        // After logout, protected routes redirect to login again
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
