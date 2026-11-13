<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserStatusTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeTestUser();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();
        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'statustest@example.com']);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
        }
    }

    private function createUser(string $status): User
    {
        $user = new User();
        $user->setEmail('statustest@example.com');
        $user->setName('Status Test');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $user;
    }

    public function testInactiveUserCannotLoginAndSeesError(): void
    {
        $this->createUser('inactive');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'statustest@example.com',
            'password' => 'testpassword',
        ]);

        // Redirected back to login with error
        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        // Not authenticated — protected route redirects to login
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testActiveUserCanLoginNormally(): void
    {
        $this->createUser('active');

        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'statustest@example.com',
            'password' => 'testpassword',
        ]);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/dashboard', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testDeactivatingLoggedInUserTerminatesSession(): void
    {
        $this->createUser('active');

        // Log in
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'statustest@example.com',
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect(); // Lands on /dashboard

        // Confirm authenticated
        $this->assertResponseIsSuccessful();

        // Simulate admin deactivating the user (bypass UI — tests the mechanism directly)
        $this->em->getConnection()->executeStatement(
            "UPDATE `user` SET status = 'inactive' WHERE email = :email",
            ['email' => 'statustest@example.com']
        );
        $this->em->clear();

        // Next request: ContextListener refreshes user, checkPostAuth throws, session cleared
        $this->client->request('GET', '/dashboard');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/staff/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
