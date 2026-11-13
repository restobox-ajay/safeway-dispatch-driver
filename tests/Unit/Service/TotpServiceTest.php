<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TotpService;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Known-answer tests for TotpService (issue #62). Every 2FA functional/acceptance test computes its "correct" code
 * with TotpService::generateCode() itself, so a regression in base32 decoding, the HMAC message packing or the
 * dynamic truncation would change the server and those tests identically — and lock every enrolled account out of
 * real authenticator apps. These vectors come from the RFCs, not from the code under test.
 */
final class TotpServiceTest extends TestCase
{
    /** The RFC 4226 / RFC 6238 SHA-1 test secret: ASCII "12345678901234567890", base32-encoded. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TotpService $totp;

    protected function setUp(): void
    {
        $this->totp = new TotpService();
    }

    /**
     * RFC 6238 Appendix B (SHA-1), counter = floor(T / 30). The RFC lists 8-digit values; a 6-digit TOTP is the
     * same truncated value mod 10^6, i.e. its last six digits.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfc6238Vectors(): iterable
    {
        yield 'T=59'          => [1, '287082'];
        yield 'T=1111111109'  => [37037036, '081804'];
        yield 'T=1111111111'  => [37037037, '050471'];
        yield 'T=1234567890'  => [41152263, '005924'];
        yield 'T=2000000000'  => [66666666, '279037'];
        yield 'T=20000000000' => [666666666, '353130'];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testGeneratesTheRfc6238Codes(int $counter, string $expected): void
    {
        self::assertSame($expected, $this->totp->generateCode(self::RFC_SECRET, $counter));
    }

    /**
     * RFC 4226 Appendix D: HOTP values for counters 0–9 (the TOTP core with an explicit counter).
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfc4226Vectors(): iterable
    {
        foreach (['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'] as $counter => $code) {
            yield "counter $counter" => [$counter, $code];
        }
    }

    #[DataProvider('rfc4226Vectors')]
    public function testGeneratesTheRfc4226Codes(int $counter, string $expected): void
    {
        self::assertSame($expected, $this->totp->generateCode(self::RFC_SECRET, $counter));
    }

    public function testSecretDecodingIgnoresCaseAndPadding(): void
    {
        // Authenticator apps and users hand secrets around lower-cased and with '=' padding.
        self::assertSame('287082', $this->totp->generateCode(strtolower(self::RFC_SECRET), 1));
        self::assertSame('287082', $this->totp->generateCode(self::RFC_SECRET . '====', 1));
    }

    public function testAGeneratedSecretIs160BitsOfBase32(): void
    {
        $secret = $this->totp->generateSecret();

        // 20 random bytes = 160 bits = exactly 32 base32 characters (RFC 4226 recommends 160-bit secrets).
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertNotSame($secret, $this->totp->generateSecret());
    }

    public function testVerifyAcceptsTheCurrentStepAndOneStepEitherSide(): void
    {
        $this->withStableStep(function (int $now): void {
            foreach ([-1, 0, 1] as $drift) {
                $code = $this->totp->generateCode(self::RFC_SECRET, $now + $drift);
                self::assertSame($now + $drift, $this->totp->verifyCode(self::RFC_SECRET, $code), "drift $drift is inside the window");
            }
        });
    }

    public function testVerifyRejectsCodesOutsideTheWindow(): void
    {
        $this->withStableStep(function (int $now): void {
            foreach ([-2, 2] as $drift) {
                $code = $this->totp->generateCode(self::RFC_SECRET, $now + $drift);
                // A code that coincidentally equals an in-window code would be accepted; skip that (1-in-10^6) case.
                $inWindow = array_map(fn (int $d): string => $this->totp->generateCode(self::RFC_SECRET, $now + $d), [-1, 0, 1]);
                if (\in_array($code, $inWindow, true)) {
                    continue;
                }
                self::assertNull($this->totp->verifyCode(self::RFC_SECRET, $code), "drift $drift is outside the window");
            }
        });
    }

    public function testVerifyRejectsACodeAtOrBelowTheLastUsedCounter(): void
    {
        $this->withStableStep(function (int $now): void {
            $code = $this->totp->generateCode(self::RFC_SECRET, $now);

            self::assertNull($this->totp->verifyCode(self::RFC_SECRET, $code, $now), 'a replay of the consumed step is rejected');
            self::assertNull($this->totp->verifyCode(self::RFC_SECRET, $code, $now + 1), 'so is anything at or below a later consumed step');
            self::assertSame($now, $this->totp->verifyCode(self::RFC_SECRET, $code, $now - 1), 'a step after the consumed one is accepted');
        });
    }

    public function testVerifyRejectsAWrongCode(): void
    {
        $this->withStableStep(function (int $now): void {
            // The first code (counting up from the current one) that no in-window step produces.
            $inWindow = array_map(fn (int $d): string => $this->totp->generateCode(self::RFC_SECRET, $now + $d), [-1, 0, 1]);
            $wrong = (int) $inWindow[1];
            do {
                $wrong = ($wrong + 1) % 1000000;
                $candidate = str_pad((string) $wrong, 6, '0', STR_PAD_LEFT);
            } while (\in_array($candidate, $inWindow, true));

            self::assertNull($this->totp->verifyCode(self::RFC_SECRET, $candidate));
        });
    }

    /**
     * Run $check against the current 30 s step. verifyCode() reads the clock itself, so if the step rolls over while
     * $check runs, its window assertions are about the wrong step: a failure then is retried on the new step, and
     * only a failure on a step that did NOT change is reported.
     *
     * @param callable(int): void $check
     */
    private function withStableStep(callable $check): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $before = intdiv(time(), 30);
            try {
                $check($before);
            } catch (AssertionFailedError $e) {
                if (intdiv(time(), 30) === $before) {
                    throw $e;
                }
                continue;
            }

            return;
        }
        self::fail('the clock kept crossing a 30 s boundary');
    }
}
