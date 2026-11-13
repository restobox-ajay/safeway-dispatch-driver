<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Tests\Support\AuthenticationTestTrait;
use App\Tests\Support\TwoFactorTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminReset2faTest extends WebTestCase
{
    use AuthenticationTestTrait;
    use TwoFactorTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $admin = new Admin();
        $admin->setEmail('reset2fa-admin@example.com');
        $admin->setName('Reset2fa Admin');
        $admin->setPassword(self::hashTestPassword('adminpass'));
        $admin->setRoles([]);
        $this->em->persist($admin);

        $superAdmin = new Admin();
        $superAdmin->setEmail('reset2fa-superadmin@example.com');
        $superAdmin->setName('Reset2fa SuperAdmin');
        $superAdmin->setPassword(self::hashTestPassword('superpass'));
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
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement("DELETE FROM two_factor_settings WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'reset2fa-%@example.com')");
            $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'reset2fa-%@example.com'");
            $conn->executeStatement("DELETE FROM config WHERE config_key = '2fa.enforcement'");
            foreach (['reset2fa-admin@example.com', 'reset2fa-superadmin@example.com'] as $email) {
                $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
                if ($admin) {
                    $this->em->remove($admin);
                    $this->em->flush();
                }
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(string $email, array $roles = [], bool $totpEnabled = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Test User');
        $user->setPassword(self::hashTestPassword('userpass'));
        $user->setStatus('active');
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();
        if ($totpEnabled) {
            $this->enableTwoFactor($user, 'TESTSECRETAAAAAAAAA');
        }
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function loginAsSuperAdmin(): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'reset2fa-superadmin@example.com',
            'password' => 'superpass',
        ]);
        $this->client->followRedirect();
    }

    private function submitReset2faForm(int $userId): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter('form[action="/admin/users/' . $userId . '/reset-2fa"]')->form();
        $this->client->submit($form);
    }

    // AC1: 'Reset 2FA' button is shown in the admin user list when the user has 2FA enabled
    public function testResetTwoFactorButtonShownWhenEnabled(): void
    {
        $user = $this->createUser('reset2fa-button@example.com', [], true);

        $this->loginAsAdmin('reset2fa-admin@example.com');
        $this->client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/admin/users/' . $user->getId() . '/reset-2fa"]');
    }

    // AC2: Clicking Reset 2FA clears the user's TOTP secret and marks 2FA disabled
    public function testResetTwoFactorClearsTotpFields(): void
    {
        $user = $this->createUser('reset2fa-clear@example.com', [], true);
        $id   = $user->getId();

        $this->loginAsAdmin('reset2fa-admin@example.com');
        $this->submitReset2faForm($id);

        $this->assertResponseRedirects('/admin/users');

        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->getRepository(User::class)->find($id);
        $this->assertNotNull($reloaded);
        $this->assertFalse($this->isTwoFactorEnabled($reloaded), 'isTotpEnabled should be false after reset');
        $this->assertNull($this->twoFactorSecret($reloaded), 'totpSecret should be null after reset');
    }

    // AC3: After reset, user is redirected to /account/2fa/setup if enforcement=required
    public function testAfterResetEnforcementRedirectsToSetup(): void
    {
        $user = $this->createUser('reset2fa-enforce@example.com', [], true);
        $id   = $user->getId();

        // Set enforcement to required
        $this->em->getConnection()->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('2fa.enforcement', 'required')"
        );

        $this->loginAsAdmin('reset2fa-admin@example.com');
        $this->submitReset2faForm($id);
        $this->assertResponseRedirects('/admin/users');

        // Log in as the user on the user firewall (same client; firewalls use separate session keys)
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => 'reset2fa-enforce@example.com',
            'password' => 'userpass',
        ]);
        // Follow login redirect → lands on /dashboard → intercepted → 302 to /account/2fa/setup
        $this->client->followRedirect();

        $response = $this->client->getResponse();
        $this->assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/account/2fa/setup', $location,
            'User without 2FA should be redirected to 2FA setup when enforcement=required'
        );
    }
}
