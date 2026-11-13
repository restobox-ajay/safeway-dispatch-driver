<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Service\ConfigService;
use App\Session\SessionTtlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * ADR-051: the session is the "stay signed in" credential, so its TTL is resolved per request from
 * DB config — a baseline idle window for everyone, a long window for a session that ticked
 * "Remember me". These tests pin the resolution rules and the fail-safe behaviour.
 */
final class SessionTtlResolverTest extends TestCase
{
    /** @param array<string,string> $config */
    private function resolver(array $config, ?bool $longSession, bool $started = true): SessionTtlResolver
    {
        $configService = new class ($config) extends ConfigService {
            /** @param array<string,string> $values */
            public function __construct(private array $values) {}

            public function getInt(string $key, int $default = 0): int
            {
                return isset($this->values[$key]) ? (int) $this->values[$key] : $default;
            }
        };

        $stack = new RequestStack();
        if ($longSession !== null) {
            $request = Request::create('/admin/dashboard');
            $session = new Session(new MockArraySessionStorage());
            if ($started) {
                $session->start();
                if ($longSession) {
                    $session->set(SessionTtlResolver::LONG_SESSION_KEY, true);
                }
            }
            $request->setSession($session);
            $stack->push($request);
        }

        return new SessionTtlResolver($configService, $stack);
    }

    public function testBaselineIdleWindowIsUsedWithoutTheFlag(): void
    {
        // 3 hours by default — replaces PHP's 24-minute gc_maxlifetime.
        self::assertSame(180 * 60, $this->resolver([], false)->resolve());
    }

    public function testRememberMeWindowIsUsedWithTheFlag(): void
    {
        self::assertSame(21 * 86400, $this->resolver([], true)->resolve());
    }

    public function testConfiguredValuesOverrideDefaults(): void
    {
        $baseline = $this->resolver(['session.idle_lifetime_minutes' => '45'], false);
        self::assertSame(45 * 60, $baseline->resolve());

        $long = $this->resolver(['session.remember_me_lifetime_days' => '7'], true);
        self::assertSame(7 * 86400, $long->resolve());
    }

    public function testNonPositiveConfiguredValuesAreFlooredNotZero(): void
    {
        // A 0/negative row must never yield a zero TTL (that would expire every session instantly).
        self::assertSame(60, $this->resolver(['session.idle_lifetime_minutes' => '0'], false)->resolve());
        self::assertSame(86400, $this->resolver(['session.remember_me_lifetime_days' => '-5'], true)->resolve());
    }

    public function testFallsBackToBaselineWithNoRequestOrUnstartedSession(): void
    {
        // Console commands / warmups have no request; an unstarted session must not be touched.
        self::assertSame(180 * 60, $this->resolver([], null)->resolve());
        self::assertSame(180 * 60, $this->resolver([], true, started: false)->resolve());
    }

    public function testResolutionIsMemoisedThenResettable(): void
    {
        // Memoised so the handler's per-write call does not re-query config on every write.
        $configService = new class extends ConfigService {
            public int $calls = 0;

            public function __construct() {}

            public function getInt(string $key, int $default = 0): int
            {
                ++$this->calls;

                return $default;
            }
        };

        $resolver = new SessionTtlResolver($configService, new RequestStack());

        $resolver->resolve();
        $resolver->resolve();
        self::assertSame(1, $configService->calls);

        $resolver->reset();
        $resolver->resolve();
        self::assertSame(2, $configService->calls);
    }
}
