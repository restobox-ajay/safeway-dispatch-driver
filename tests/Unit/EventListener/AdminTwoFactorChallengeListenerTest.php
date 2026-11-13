<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Admin;
use App\EventListener\AdminTwoFactorChallengeListener;
use App\Security\TwoFactorEnforcementResolver;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Hardening regression for the admin 2FA "required but unenrolled -> force setup" gate.
 *
 * The exemption is an EXACT match on the setup route, not a `/admin/2fa` prefix match: a path that
 * merely shares that prefix (a future `/admin/2fa-*` route) must NOT escape the enrolment gate. A
 * prefix match would silently let such a route through — the 2FA-bypass class this test guards.
 */
final class AdminTwoFactorChallengeListenerTest extends TestCase
{
    private function listener(string $enforcement): AdminTwoFactorChallengeListener
    {
        $admin = new Admin();
        $admin->setEmail('a@example.com');
        $admin->setRoles(['ROLE_SUPER_ADMIN']);
        // Unenrolled: isTotpEnabled() stays false by default.

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name): string => $name === 'app_admin_2fa_setup' ? '/admin/2fa/setup' : '/x'
        );

        // Real resolver backed by an in-memory ConfigService stub. ROLE_SUPER_ADMIN's per-role key
        // drives the resolved level, so the listener path decision runs against production logic.
        $config = new class ($enforcement) extends ConfigService {
            public function __construct(private string $level) {}

            public function getString(string $key, string $default = ''): string
            {
                return $key === '2fa.enforcement.role.ROLE_SUPER_ADMIN' ? $this->level : $default;
            }
        };
        $resolver = new TwoFactorEnforcementResolver($config);

        return new AdminTwoFactorChallengeListener($tokenStorage, $router, $resolver);
    }

    private function eventFor(string $path): RequestEvent
    {
        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /** A path sharing the /admin/2fa prefix but that is NOT the setup route must be bounced to setup. */
    public function testPrefixSharingPathIsStillForcedToSetup(): void
    {
        $event = $this->eventFor('/admin/2fa-report');
        $this->listener('required')->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/2fa/setup', $response->getTargetUrl());
    }

    /** The setup route itself is exempt (no setup->setup loop). */
    public function testSetupRouteIsExempt(): void
    {
        $event = $this->eventFor('/admin/2fa/setup');
        $this->listener('required')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
