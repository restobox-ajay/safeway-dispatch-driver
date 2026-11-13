<?php

declare(strict_types=1);

namespace App\Tests\Functional\Navigation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The root path '/' must always redirect to a useful destination — never the
 * default 'Welcome to Symfony!' framework page or a bare 404.
 *   - anonymous  -> the driver login page (/login)
 *   - authenticated -> the post-login landing page (/dashboard)
 */
final class RootRedirectTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $user = new User();
        $user->setEmail('root-redirect@example.com');
        $user->setName('Root Redirect');
        $user->setPassword(password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
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
        $this->em->getConnection()->executeStatement(
            "DELETE FROM \"user\" WHERE email = 'root-redirect@example.com'"
        );
    }

    public function testAnonymousRootRedirectsToTheDriverLogin(): void
    {
        $this->client->request('GET', '/');

        // The app's front door is the driver login (/login); staff sign in at /staff/login.
        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousRootIsNotTheWelcomePage(): void
    {
        $crawler = $this->client->request('GET', '/');

        // A 3xx redirect, never a 200 welcome/404 page.
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringNotContainsStringIgnoringCase(
            'Welcome to Symfony',
            $this->client->getResponse()->getContent() ?: ''
        );
    }

    public function testAuthenticatedRootRedirectsToDashboard(): void
    {
        // Authenticate on the user firewall via the real login form (the
        // convention used across the functional suite).
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'root-redirect@example.com',
            'password' => 'userpass',
        ]);

        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/dashboard');
    }
}
