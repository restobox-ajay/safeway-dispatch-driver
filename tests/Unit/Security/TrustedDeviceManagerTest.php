<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Bundle\Auth2fa\Security\TrustedDeviceManager;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * FEATURE-132 (review C16): the TRUSTED_DEVICE 2FA-bypass cookie is bound to a per-user
 * binding token (current totpSecret + status), so any 2FA reset / disable / re-enroll (secret
 * rotates or clears) or deactivation (status -> inactive) invalidates outstanding cookies.
 *
 * As of FEATURE-143 / ADR-043 the totp secret no longer lives on the User entity (it moved to the
 * two_factor_settings satellite), so TrustedDeviceManager takes the secret explicitly and these unit
 * tests pass it in — exactly what the controller/guard now do after reading it from the repository.
 */
final class TrustedDeviceManagerTest extends TestCase
{
    private const SECRET = 'kernel-test-secret';

    private function manager(): TrustedDeviceManager
    {
        return new TrustedDeviceManager(self::SECRET);
    }

    /**
     * Builds a User with a fixed id (Doctrine assigns it on flush; here we set it via
     * reflection since these are pure-unit fixtures never persisted).
     */
    private function user(int $id, string $status = 'active'): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);
        $user->setStatus($status);

        return $user;
    }

    private function requestWithCookie(string $value): Request
    {
        $request = Request::create('/2fa/challenge');
        $request->cookies->set(TrustedDeviceManager::COOKIE_NAME, $value);

        return $request;
    }

    public function testCookieIsTrustedForTheSameUserState(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);

        $cookie = $manager->generateCookie($user, 'SEEDONE', 30, false);
        $request = $this->requestWithCookie($cookie->getValue());

        $this->assertTrue($manager->isDeviceTrusted($request, $user, 'SEEDONE'));
    }

    // AC1: re-enrolling 2FA rotates the totpSecret -> old cookie no longer trusted.
    public function testCookieNotTrustedAfterTotpSecretRotates(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);
        $cookie = $manager->generateCookie($user, 'SEEDONE', 30, false);
        $request = $this->requestWithCookie($cookie->getValue());

        // Admin resets 2FA and the user re-enrols with a fresh secret.
        $this->assertFalse($manager->isDeviceTrusted($request, $user, 'SEEDTWO'));
    }

    // AC2: disabling 2FA clears the totpSecret -> old cookie no longer trusted.
    public function testCookieNotTrustedAfterTotpDisabled(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);
        $cookie = $manager->generateCookie($user, 'SEEDONE', 30, false);
        $request = $this->requestWithCookie($cookie->getValue());

        $this->assertFalse($manager->isDeviceTrusted($request, $user, null));
    }

    // AC3: deactivation/soft-delete flips status to inactive -> old cookie no longer trusted.
    public function testCookieNotTrustedAfterDeactivation(): void
    {
        $manager = $this->manager();
        $user = $this->user(42, 'active');
        $cookie = $manager->generateCookie($user, 'SEEDONE', 30, false);
        $request = $this->requestWithCookie($cookie->getValue());

        $deactivated = $this->user(42, 'inactive');

        $this->assertFalse($manager->isDeviceTrusted($request, $deactivated, 'SEEDONE'));
    }

    public function testCookieNotTrustedForADifferentUser(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);
        $cookie = $manager->generateCookie($user, 'SEEDONE', 30, false);
        $request = $this->requestWithCookie($cookie->getValue());

        $other = $this->user(99);

        $this->assertFalse($manager->isDeviceTrusted($request, $other, 'SEEDONE'));
    }

    public function testExpiredCookieIsNotTrusted(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);

        // A negative lifetime yields an already-past expiry.
        $cookie = $manager->generateCookie($user, 'SEEDONE', -1, false);
        $request = $this->requestWithCookie($cookie->getValue());

        $this->assertFalse($manager->isDeviceTrusted($request, $user, 'SEEDONE'));
    }

    public function testMalformedCookieIsNotTrusted(): void
    {
        $manager = $this->manager();
        $user = $this->user(42);
        $request = $this->requestWithCookie('not-valid-base64-@@@');

        $this->assertFalse($manager->isDeviceTrusted($request, $user, 'SEEDONE'));
    }
}
