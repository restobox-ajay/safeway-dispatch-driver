<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Admin;
use App\Entity\User;

/**
 * Shared authentication + account-creation helpers for functional WebTestCase tests.
 *
 * FEATURE-130 (review C41): replaces the per-file `loginUser`/`loginAsAdmin` copies and the
 * duplicated bcrypt cost=4 creation blocks with a single source of truth.
 *
 * The using test case must expose the two properties the functional suite conventionally uses:
 *   - KernelBrowser $client            (from static::createClient())
 *   - EntityManagerInterface $em
 * They may be declared private on the using class — trait methods compile into that class, so
 * private access resolves.
 */
trait AuthenticationTestTrait
{
    /** bcrypt at cost 4 — deliberately weak, fast hashing for tests only (never production). */
    protected static function hashTestPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * Create + persist a User with a cost-4 password, then clear the identity map and return the
     * freshly-reloaded managed entity (the pattern the functional suite already used).
     *
     * @param list<string> $roles
     */
    protected function createTestUser(
        string $email,
        string $name = 'Test User',
        string $password = 'testpassword',
        string $status = 'active',
        array $roles = [],
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword(self::hashTestPassword($password));
        $user->setStatus($status);
        if ($roles !== []) {
            $user->setRoles($roles);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * Create + persist an Admin with a cost-4 password, then clear the identity map and return the
     * freshly-reloaded managed entity.
     *
     * @param list<string> $roles
     */
    protected function createTestAdmin(
        string $email,
        string $name = 'Test Admin',
        string $password = 'adminpass',
        string $status = 'active',
        array $roles = [],
    ): Admin {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName($name);
        $admin->setPassword(self::hashTestPassword($password));
        $admin->setStatus($status);
        if ($roles !== []) {
            $admin->setRoles($roles);
        }
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);
    }

    /** Submit the user firewall login form. Never follows the redirect by default. */
    protected function loginUser(string $email, string $password = 'testpassword', bool $followRedirect = false): void
    {
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => $password,
        ]);
        if ($followRedirect) {
            $this->client->followRedirect();
        }
    }

    /**
     * Create a tech-support admin already enrolled in TOTP and log in through the mandatory 2FA challenge
     * (ADR-050 forces 2FA for this role), leaving a fully verified session on the admin panel.
     */
    protected function loginAsEnrolledTechSupport(string $email, string $totpSecret = 'JBSWY3DPEHPK3PXP', string $password = 'adminpass'): void
    {
        $admin = $this->createTestAdmin($email, 'Enrolled Tech Support', $password, roles: ['ROLE_TECH_SUPPORT']);
        $admin->setTotpSecret($totpSecret);
        $admin->setIsTotpEnabled(true);
        $this->em->flush();
        $this->em->clear();

        $this->loginAsAdmin($email, $password, followRedirect: false);
        $this->client->request('GET', '/admin/dashboard');
        $this->client->followRedirect(); // GET /admin/2fa/challenge (the form)
        $code = self::getContainer()->get(\App\Service\TotpService::class)->generateCode($totpSecret);
        $this->client->submitForm('Verify', ['_code' => $code]);
        $this->client->followRedirect();
    }

    /** Submit the admin firewall login form. Follows the post-login redirect by default. */
    protected function loginAsAdmin(string $email, string $password = 'adminpass', bool $followRedirect = true): void
    {
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Sign in', [
            'email'    => $email,
            'password' => $password,
        ]);
        if ($followRedirect) {
            $this->client->followRedirect();
        }
    }
}
