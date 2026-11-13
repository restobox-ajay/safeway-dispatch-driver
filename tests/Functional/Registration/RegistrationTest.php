<?php

declare(strict_types=1);

namespace App\Tests\Functional\Registration;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeTestUser('regtest@example.com');
        $this->removeTestUser('duplicate@example.com');

        // Pre-create a user for duplicate-email test
        $existing = new User();
        $existing->setEmail('duplicate@example.com');
        $existing->setName('Existing User');
        $existing->setPassword(password_hash('somepassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($existing);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser('regtest@example.com');
        $this->removeTestUser('duplicate@example.com');
        parent::tearDown();
    }

    private function removeTestUser(string $email): void
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $this->em->remove($user);
                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }

    public function testRegistrationFormRenders(): void
    {
        $this->client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('input[name="name"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function testValidRegistrationCreatesUser(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'regtest@example.com',
            'name'     => 'Reg Test User',
            'password' => 'validpassword123',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'regtest@example.com']);
        $this->assertNotNull($user);
    }

    public function testDuplicateEmailShowsValidationError(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'duplicate@example.com',
            'name'     => 'Another User',
            'password' => 'validpassword123',
        ]);

        // Should stay on the registration page (not redirect)
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('.error');
    }

    public function testEmptyPasswordShowsValidationError(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'regtest@example.com',
            'name'     => 'Reg Test User',
            'password' => '',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorExists('.error');
    }

    public function testNewlyRegisteredUserHasActiveStatusAndHashedPassword(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'email'    => 'regtest@example.com',
            'name'     => 'Reg Test User',
            'password' => 'validpassword123',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'regtest@example.com']);

        $this->assertNotNull($user);
        $this->assertSame('active', $user->getStatus());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'validpassword123'));
    }
}
