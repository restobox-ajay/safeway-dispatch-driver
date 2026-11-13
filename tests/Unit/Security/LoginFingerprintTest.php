<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\LoginFingerprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * FEATURE-107 (review C14): the fingerprint formula lives in exactly one place. If this test and
 * the listeners both went through LoginFingerprint, a formula change can never silently diverge
 * between the history writer and the notification recogniser.
 */
final class LoginFingerprintTest extends TestCase
{
    public function testFingerprintIsSha256OfIpAndUserAgent(): void
    {
        $fp = new LoginFingerprint();

        self::assertSame(
            hash('sha256', '203.0.113.7' . 'Mozilla/5.0'),
            $fp->fingerprint('203.0.113.7', 'Mozilla/5.0'),
        );
    }

    public function testFingerprintIsDeterministic(): void
    {
        $fp = new LoginFingerprint();

        self::assertSame(
            $fp->fingerprint('10.0.0.1', 'AgentX/1.0'),
            $fp->fingerprint('10.0.0.1', 'AgentX/1.0'),
        );
        self::assertNotSame(
            $fp->fingerprint('10.0.0.1', 'AgentX/1.0'),
            $fp->fingerprint('10.0.0.2', 'AgentX/1.0'),
        );
    }

    public function testContextFromRequestExtractsIpUserAgentAndFingerprint(): void
    {
        $fp = new LoginFingerprint();
        $request = Request::create('/staff/login', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.4',
            'HTTP_USER_AGENT' => 'CustomAgent/9',
        ]);

        $ctx = $fp->contextFromRequest($request);

        self::assertSame('198.51.100.4', $ctx['ip']);
        self::assertSame('CustomAgent/9', $ctx['userAgent']);
        self::assertSame($fp->fingerprint('198.51.100.4', 'CustomAgent/9'), $ctx['fingerprint']);
    }

    public function testContextFromRequestDefaultsMissingIpAndTruncatesUserAgent(): void
    {
        $fp = new LoginFingerprint();
        $longAgent = str_repeat('A', 600);
        $request = Request::create('/staff/login', 'POST', server: [
            'HTTP_USER_AGENT' => $longAgent,
        ]);
        // No REMOTE_ADDR set on a fabricated request → getClientIp() is null.
        $request->server->remove('REMOTE_ADDR');

        $ctx = $fp->contextFromRequest($request);

        self::assertSame('0.0.0.0', $ctx['ip']);
        self::assertSame(512, strlen($ctx['userAgent']));
        self::assertSame($fp->fingerprint('0.0.0.0', substr($longAgent, 0, 512)), $ctx['fingerprint']);
    }
}
