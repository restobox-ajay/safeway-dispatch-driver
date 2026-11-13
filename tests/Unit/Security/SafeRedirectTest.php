<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FEATURE-131 (review C17): the post-2FA redirect target must be reduced to a same-origin
 * LOCAL path. Anything that could redirect off-origin falls back to the safe default.
 */
final class SafeRedirectTest extends TestCase
{
    #[DataProvider('localPaths')]
    public function testAcceptsSameOriginLocalPaths(string $candidate): void
    {
        self::assertTrue(SafeRedirect::isLocalPath($candidate));
        self::assertSame($candidate, SafeRedirect::localPathOr($candidate, '/dashboard'));
    }

    public static function localPaths(): array
    {
        return [
            'root'                 => ['/'],
            'simple path'          => ['/dashboard'],
            'nested path'          => ['/account/settings'],
            'path with query'      => ['/dashboard?tab=security&x=1'],
            'path with fragment'   => ['/account#totp'],
            'encoded segment'      => ['/report/%2E%2E'],
        ];
    }

    #[DataProvider('offOriginTargets')]
    public function testRejectsOffOriginOrMalformedTargets(?string $candidate): void
    {
        self::assertFalse(SafeRedirect::isLocalPath($candidate));
        self::assertSame('/dashboard', SafeRedirect::localPathOr($candidate, '/dashboard'));
    }

    public static function offOriginTargets(): array
    {
        return [
            'null'                 => [null],
            'empty'                => [''],
            'absolute http'        => ['http://evil.example.com/x'],
            'absolute https'       => ['https://evil.example.com/x'],
            'scheme-relative'      => ['//evil.example.com/x'],
            'backslash slash'      => ['/\\evil.example.com'],
            'double backslash'     => ['\\\\evil.example.com'],
            'bare relative'        => ['dashboard'],
            'leading space'        => [' /dashboard'],
            'CR injection'         => ["/dashboard\r\nSet-Cookie: x=1"],
            'LF injection'         => ["/dashboard\nfoo"],
        ];
    }
}
