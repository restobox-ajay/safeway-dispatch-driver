<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

class TotpService
{
    private const ISSUER = 'SymfonyAuth';
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function getOtpAuthUri(string $secret, string $email): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d&algorithm=SHA1',
            rawurlencode(self::ISSUER),
            rawurlencode($email),
            $secret,
            rawurlencode(self::ISSUER),
            self::DIGITS,
            self::PERIOD
        );
    }

    public function getQrCodeDataUri(string $secret, string $email): string
    {
        $uri = $this->getOtpAuthUri($secret, $email);

        $result = (new Builder(writer: new SvgWriter(), data: $uri, size: 200))->build();

        return $result->getDataUri();
    }

    /**
     * Verify a TOTP code. Returns the time-counter the code matched (so the caller can
     * persist it and reject replays), or null if no counter in the window matches or every
     * matching counter is <= $afterCounter (already consumed). Comparison is constant-time.
     */
    public function verifyCode(string $secret, string $code, int $afterCounter = PHP_INT_MIN): ?int
    {
        $current = (int) floor(time() / self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $counter = $current + $i;
            if ($counter <= $afterCounter) {
                // Already consumed by a previous successful verification — reject replay.
                continue;
            }
            if (hash_equals($this->generateCode($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    public function generateCode(string $secret, ?int $counter = null): string
    {
        if ($counter === null) {
            $counter = (int) floor(time() / self::PERIOD);
        }

        $secretBytes = $this->base32Decode($secret);
        $msg = pack('N', 0) . pack('N', $counter);
        $hash = hash_hmac('sha1', $msg, $secretBytes, true);

        $offset = ord($hash[19]) & 0x0f;
        $otp = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $bitsLeft = 0;
        $current = 0;

        foreach (str_split($bytes) as $byte) {
            $current = ($current << 8) | ord($byte);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= $alphabet[($current >> $bitsLeft) & 0x1f];
            }
        }

        if ($bitsLeft > 0) {
            $result .= $alphabet[($current << (5 - $bitsLeft)) & 0x1f];
        }

        return $result;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));
        $result = '';
        $bitsLeft = 0;
        $current = 0;

        foreach (str_split($base32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $current = ($current << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($current >> $bitsLeft) & 0xff);
            }
        }

        return $result;
    }
}
