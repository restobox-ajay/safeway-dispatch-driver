<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-091: Optional separate-domain mode (ADMIN_DOMAIN / APP_DOMAIN).
 *
 * The firewalls are host-scoped from env vars: the admin / admin-api firewalls
 * match ADMIN_DOMAIN, the user firewall matches APP_DOMAIN. When the vars are
 * empty (the single-domain default in .env) every firewall matches every host,
 * so there is no behavior change. When they are set to distinct hosts the admin
 * surface is only served on the admin host and the user surface on the app host.
 *
 * The observable HTTP signal is *which firewall's entry point* handles an
 * unauthenticated protected request: the admin firewall redirects to
 * /admin/login, the user firewall to /login.
 *
 * Firewall `host` is resolved from env at runtime, so overriding $_SERVER before
 * booting a fresh kernel exercises separate-domain mode without a second
 * env file.
 */
final class SeparateDomainModeTest extends WebTestCase
{
    private const ADMIN_HOST = 'admin.example.test';
    private const APP_HOST = 'app.example.test';
    private const EMAIL = 'separate-domain@example.com';
    private const PASSWORD = 'testpassword';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        // Restore any env vars this test overrode so it does not leak into the
        // single-domain default used by the rest of the suite.
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_SERVER[$key], $_ENV[$key]);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
            }
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = $_SERVER[$key] ?? false;
        }
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    private function redirectLocation(KernelBrowser $client): string
    {
        $location = $client->getResponse()->headers->get('Location') ?? '';

        // Normalise to just the path so host prefixes do not confuse substring
        // assertions (e.g. http://app.example.test/login -> /login).
        return parse_url($location, PHP_URL_PATH) ?: $location;
    }

    /**
     * AC1 (no regression): with the default test env (ADMIN_DOMAIN=APP_DOMAIN=
     * localhost) the admin firewall serves /admin/* on the default host and an
     * unauthenticated request is redirected to /admin/login.
     */
    public function testSingleDomainAdminProtectedRouteRedirectsToAdminLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/dashboard');

        self::assertResponseRedirects();
        self::assertSame('/admin/login', $this->redirectLocation($client));
    }

    /**
     * AC2: in separate-domain mode, the admin firewall matches the admin host —
     * /admin/dashboard there is served by the admin firewall (redirect to
     * /admin/login).
     */
    public function testSeparateDomainAdminHostServesAdminFirewall(): void
    {
        $this->setEnv('ADMIN_DOMAIN', self::ADMIN_HOST);
        $this->setEnv('APP_DOMAIN', self::APP_HOST);

        $client = static::createClient();
        $client->request('GET', 'http://' . self::ADMIN_HOST . '/admin/dashboard');

        self::assertResponseRedirects();
        self::assertSame('/admin/login', $this->redirectLocation($client));
    }

    /**
     * AC2: the admin firewall does NOT match the app host. /admin/dashboard
     * requested on the app host falls through to the user firewall, whose entry
     * point redirects to /login (not /admin/login).
     */
    public function testSeparateDomainAppHostDoesNotServeAdminFirewall(): void
    {
        $this->setEnv('ADMIN_DOMAIN', self::ADMIN_HOST);
        $this->setEnv('APP_DOMAIN', self::APP_HOST);

        $client = static::createClient();
        $client->request('GET', 'http://' . self::APP_HOST . '/admin/dashboard');

        self::assertResponseRedirects();
        $location = $this->redirectLocation($client);
        self::assertSame('/staff/login', $location);
        self::assertStringNotContainsString('/admin/login', $location);
    }

    /**
     * AC2: the user firewall matches the app host — a protected user route there
     * is served by the user firewall (redirect to /login).
     */
    public function testSeparateDomainAppHostServesUserFirewall(): void
    {
        $this->setEnv('ADMIN_DOMAIN', self::ADMIN_HOST);
        $this->setEnv('APP_DOMAIN', self::APP_HOST);

        $client = static::createClient();
        $client->request('GET', 'http://' . self::APP_HOST . '/dashboard');

        self::assertResponseRedirects();
        self::assertSame('/staff/login', $this->redirectLocation($client));
    }

    /**
     * AC3 (session cookie): with SESSION_COOKIE_DOMAIN empty (the default) the
     * compiled session cookie has no Domain attribute, so it is scoped to the
     * exact host that set it — admin-host and app-host sessions cannot bleed
     * across.
     */
    public function testSessionCookieDomainIsHostScopedByDefault(): void
    {
        static::createClient();

        /** @var array<string, mixed> $options */
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertArrayHasKey('cookie_domain', $options);
        self::assertSame('', $options['cookie_domain'], 'session cookie must be host-scoped by default');
    }

    /**
     * AC3 (remember-me cookie): with SESSION_COOKIE_DOMAIN empty the REMEMBERME
     * cookie is issued with no Domain attribute (host-scoped).
     */
    public function testRememberMeCookieDomainIsHostScopedByDefault(): void
    {
        $client = static::createClient();
        $this->createUser($client);

        $client->request('POST', '/staff/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            '_remember_me' => '1',
        ]);

        $rememberMe = null;
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'REMEMBERME') {
                $rememberMe = $cookie;
                break;
            }
        }

        self::assertNotNull($rememberMe, 'a REMEMBERME cookie must be issued');
        self::assertNull($rememberMe->getDomain(), 'remember-me cookie must be host-scoped by default');

        $this->removeUser($client);
    }

    private function createUser(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->removeUser($client);
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('Separate Domain Test');
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setIsVerified(true);
        $em->persist($user);
        $em->flush();
        $em->clear();
    }

    private function removeUser(KernelBrowser $client): void
    {
        try {
            $conn = $client->getContainer()->get(EntityManagerInterface::class)->getConnection();
            $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
        } catch (\Throwable) {
        }
    }
}
