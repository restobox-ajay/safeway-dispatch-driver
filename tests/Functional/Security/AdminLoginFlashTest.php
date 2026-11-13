<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\Entity\AdminPasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * FEATURE-125 (review C35): the admin login page must render flash messages.
 *
 * AdminPasswordResetController::reset (self-service admin reset) sets a
 * 'success' flash and redirects to /admin/login, but the admin login template
 * only rendered the security `error`, so the confirmation silently vanished.
 * These tests fail on the pre-fix template (no flash loop → nothing rendered).
 */
final class AdminLoginFlashTest extends WebTestCase
{
    private const EMAIL = 'adminloginflash@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanUp();

        $admin = new Admin();
        $admin->setEmail(self::EMAIL);
        $admin->setName('Admin Login Flash');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        try {
            $this->em->createQuery('DELETE FROM App\Entity\AdminPasswordResetToken t WHERE t.email = :email')
                ->setParameter('email', self::EMAIL)
                ->execute();
            $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => self::EMAIL]);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
            }
            $this->em->clear();
        } catch (\Throwable) {
            // Ignore cleanup errors — next setUp re-tries.
        }
    }

    /**
     * AC1/AC2/AC3: a real self-service admin password reset sets a success flash
     * then redirects to /admin/login; the flash must be visible there.
     */
    public function testSelfServiceResetSuccessFlashShownOnAdminLogin(): void
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $token = new AdminPasswordResetToken(
            self::EMAIL,
            hash('sha256', $plaintextToken),
            new \DateTimeImmutable('+1 hour'),
        );
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('POST', '/admin/reset-password/' . $plaintextToken, [
            'password' => 'BrandNewAdminPassword123',
        ]);

        $this->assertResponseRedirects('/admin/login');
        $crawler = $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.success', 'Admin login page must render the reset-success flash');
        $this->assertStringContainsString(
            'Your password has been reset',
            $crawler->filter('.success')->text(),
        );
    }

    /**
     * AC1/AC3: an 'error' flash set before landing on /admin/login is displayed.
     */
    public function testErrorFlashShownOnAdminLogin(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->getFlashBag()->add('error', 'Admin flash error rendered.');
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        $crawler = $this->client->request('GET', '/admin/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error', 'Admin login page must render error flashes');
        $this->assertStringContainsString(
            'Admin flash error rendered.',
            $crawler->filter('.error')->text(),
        );
    }
}
