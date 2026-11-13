<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Admin;
use App\EventListener\AdminRememberMeListener;
use App\Session\SessionTtlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-051: "Remember me" on the admin login form. Implemented by EXTENDING THE SESSION (long TTL),
 * not by issuing a Symfony remember_me bearer cookie — ADR-049 records that a bearer cookie would
 * reopen review finding C3 in the admin realm. These tests pin the observable contract: the opt-in
 * flag, the opt-in email prefill, and that revocation still wins.
 */
final class AdminRememberMeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
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
            $this->conn->executeStatement('DELETE FROM admin_sessions');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'adminrm%@example.com'");
            $this->conn->executeStatement('DELETE FROM audit_log');
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createAdmin(string $email): int
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Remember Me Admin');
        $admin->setPassword(password_hash('adminpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles([]);
        $this->em->persist($admin);
        $this->em->flush();
        $id = (int) $admin->getId();
        $this->em->clear();

        return $id;
    }

    /** @param array<string,mixed> $extra */
    private function login(string $email, array $extra = []): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => 'adminpassword'] + $extra);
    }

    private function sessionFlag(): mixed
    {
        return $this->client->getRequest()->getSession()->get(SessionTtlResolver::LONG_SESSION_KEY);
    }

    private function emailCookie(): ?string
    {
        return $this->client->getCookieJar()->get(AdminRememberMeListener::EMAIL_COOKIE)?->getValue();
    }

    public function testLoginFormOffersRememberMe(): void
    {
        $crawler = $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_remember_me"]'));
    }

    // Ticked: the session is marked long-lived (drives the extended TTL) and the email is stored.
    public function testTickingRememberMeMarksSessionAndStoresEmail(): void
    {
        $this->createAdmin('adminrm_yes@example.com');
        $this->login('adminrm_yes@example.com', ['_remember_me' => true]);

        self::assertTrue($this->sessionFlag(), 'session must be flagged long-lived');
        self::assertSame('adminrm_yes@example.com', $this->emailCookie());
    }

    // Not ticked: baseline idle window and nothing persisted about the admin.
    public function testWithoutRememberMeNoFlagAndNoEmailStored(): void
    {
        $this->createAdmin('adminrm_no@example.com');
        $this->login('adminrm_no@example.com');

        self::assertNotTrue($this->sessionFlag(), 'session must NOT be flagged long-lived');
        self::assertNull($this->emailCookie(), 'no email may be stored without opt-in');
    }

    // The point of the email cookie: prefill on a LATER visit, after the session is gone.
    public function testEmailPrefillsLoginFormInAFreshSession(): void
    {
        $this->createAdmin('adminrm_prefill@example.com');
        $this->login('adminrm_prefill@example.com', ['_remember_me' => true]);

        // "Come back later": the session is gone, only the long-lived email cookie survives.
        // Clearing the jar and restoring that one cookie avoids depending on the session cookie's
        // name, which differs per storage (MOCKSESSID under the test env's mock storage).
        $emailCookie = $this->client->getCookieJar()->get(AdminRememberMeListener::EMAIL_COOKIE);
        self::assertNotNull($emailCookie);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($emailCookie);

        $crawler = $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'adminrm_prefill@example.com',
            $crawler->filter('input[name="email"]')->attr('value'),
            'the login form must prefill the remembered email'
        );
        self::assertNotNull(
            $crawler->filter('input[name="_remember_me"]')->attr('checked'),
            'the remember-me choice must be sticky'
        );
    }

    // Explicit logout is an explicit "forget me on this device".
    public function testLogoutClearsTheRememberedEmail(): void
    {
        $this->createAdmin('adminrm_logout@example.com');
        $this->login('adminrm_logout@example.com', ['_remember_me' => true]);
        self::assertSame('adminrm_logout@example.com', $this->emailCookie());

        $this->client->request('GET', '/admin/logout');

        self::assertNull($this->emailCookie(), 'logout must drop the prefill cookie');
    }

    /**
     * The core reason this is a long SESSION and not a remember_me bearer cookie (ADR-049 / C3):
     * terminating the admin's sessions must cut access on the very next request, with nothing left
     * that can re-authenticate them.
     */
    public function testTerminatedSessionStillWinsOverRememberMe(): void
    {
        $adminId = $this->createAdmin('adminrm_revoke@example.com');
        $this->login('adminrm_revoke@example.com', ['_remember_me' => true]);

        $this->client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();

        // Simulate "terminate all sessions" for this admin.
        $this->conn->executeStatement('DELETE FROM admin_sessions WHERE admin_id = ?', [$adminId]);

        $this->client->request('GET', '/admin/dashboard');
        self::assertResponseRedirects(
            '/admin/login',
            null,
            'a remembered admin must still be cut off the moment their sessions are terminated'
        );
    }
}
