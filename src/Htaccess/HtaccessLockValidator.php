<?php

declare(strict_types=1);

namespace App\Htaccess;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Turns the raw admin form input into a {@see HtaccessLockSettings}, or a list of errors.
 *
 * This is the injection boundary for a feature that writes straight into a web-server config file:
 * nothing from the form reaches the renderer except tokens that matched a strict grammar here
 * (canonical IPs/CIDRs, a restricted URL-path alphabet, a 403/404 choice). A line such as
 * "1.2.3.4\nRequire all granted" can never survive — every line is validated on its own.
 *
 * It also owns the self-lockout guard: enabling the lock is refused unless the admin's own IP is
 * covered by the whitelist, since a lock that locks out its own controls is unrecoverable from the UI.
 */
final class HtaccessLockValidator
{
    /** Leading slash, then a conservative URL-path alphabet; an optional trailing `*` (paths only) = prefix. */
    private const PATH_PATTERN = '#^/[A-Za-z0-9/._~%-]{0,199}\*?$#';

    private const MAX_LINES = 500;

    public function validate(
        bool $enabled,
        string $ipsRaw,
        string $exemptPathsRaw,
        string $statusRaw,
        string $errorFileRaw,
        ?string $requesterIp,
        string $docroot,
    ): HtaccessLockValidation {
        $errors = [];

        $ips = $this->parseIps($ipsRaw, $errors);
        $paths = $this->parseExemptPaths($exemptPathsRaw, $errors);

        $status = trim($statusRaw);
        if (!\in_array($status, ['403', '404'], true)) {
            $errors[] = 'Blocked status code must be 403 or 404.';
        }

        $errorFile = $this->validateErrorFile(trim($errorFileRaw), $docroot, $errors);

        if ($enabled) {
            if ($ips === []) {
                $errors[] = 'Add at least one allowed IP before enabling the lock — an empty whitelist blocks everyone, including you.';
            } elseif ($requesterIp !== null && !IpUtils::checkIp($requesterIp, $ips)) {
                $errors[] = sprintf(
                    'Your current IP (%s) is not covered by the whitelist. Add it first, or this save would lock you out of the admin panel.',
                    $requesterIp,
                );
            }
        }

        if ($errors !== []) {
            return new HtaccessLockValidation(null, $errors);
        }

        return new HtaccessLockValidation(
            new HtaccessLockSettings($enabled, $ips, $paths, (int) $status, $errorFile),
            [],
        );
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function parseIps(string $raw, array &$errors): array
    {
        $ips = [];
        foreach ($this->lines($raw, 'IP whitelist', $errors) as $number => $line) {
            $canonical = $this->canonicalIpOrCidr($line);
            if ($canonical === null) {
                $errors[] = sprintf('IP whitelist line %d is not a valid IPv4/IPv6 address or CIDR: "%s".', $number, $this->printable($line));
                continue;
            }
            $ips[$canonical] = true;
        }

        return array_keys($ips);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function parseExemptPaths(string $raw, array &$errors): array
    {
        $paths = [];
        foreach ($this->lines($raw, 'Exempt paths', $errors) as $number => $line) {
            if (preg_match(self::PATH_PATTERN, $line) !== 1 || str_contains($line, '..') || str_contains($line, '//')) {
                $errors[] = sprintf('Exempt path line %d must be a URL path starting with "/" (letters, digits and / . _ ~ %% - only, optional trailing *): "%s".', $number, $this->printable($line));
                continue;
            }
            if ($line === '/*') {
                $errors[] = sprintf('Exempt path line %d ("/*") would exempt the whole site and defeat the lock.', $number);
                continue;
            }
            $paths[$line] = true;
        }

        return array_keys($paths);
    }

    /** @param list<string> $errors */
    private function validateErrorFile(string $path, string $docroot, array &$errors): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match(self::PATH_PATTERN, $path) !== 1 || str_ends_with($path, '*') || str_contains($path, '..') || str_contains($path, '//')) {
            $errors[] = 'Error file must be a URL path to a file under the web root, e.g. /blocked.html.';

            return '';
        }

        $root = realpath($docroot);
        $file = $root === false ? false : realpath($root . $path);
        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            $errors[] = sprintf('Error file "%s" does not exist under the web root.', $path);

            return '';
        }

        return $path;
    }

    /**
     * Non-blank, non-comment lines keyed by their 1-based line number.
     *
     * @param list<string> $errors
     *
     * @return array<int, string>
     */
    private function lines(string $raw, string $label, array &$errors): array
    {
        $all = preg_split('/\R/', $raw) ?: [];
        if (\count($all) > self::MAX_LINES) {
            $errors[] = sprintf('%s has more than %d lines.', $label, self::MAX_LINES);

            return [];
        }

        $lines = [];
        foreach ($all as $index => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $lines[$index + 1] = $line;
        }

        return $lines;
    }

    /** The canonical form of one IPv4/IPv6 address or CIDR, or null when it is not one (or is a /0, which defeats the lock). */
    public function normalizeIp(string $value): ?string
    {
        return $this->canonicalIpOrCidr(trim($value));
    }

    /** One exempt path exactly as it would be stored, or null when it is not an acceptable exempt path. */
    public function normalizeExemptPath(string $value): ?string
    {
        $value = trim($value);
        if (preg_match(self::PATH_PATTERN, $value) !== 1 || str_contains($value, '..') || str_contains($value, '//') || $value === '/*') {
            return null;
        }

        return $value;
    }

    private function canonicalIpOrCidr(string $value): ?string
    {
        $prefix = null;
        if (str_contains($value, '/')) {
            [$value, $prefixRaw] = explode('/', $value, 2);
            if (!ctype_digit($prefixRaw) || \strlen($prefixRaw) > 3) {
                return null;
            }
            $prefix = (int) $prefixRaw;
        }

        $isV4 = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = !$isV4 && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$isV4 && !$isV6) {
            return null;
        }

        $binary = inet_pton($value);
        if ($binary === false) {
            return null;
        }
        $canonical = inet_ntop($binary);
        if ($canonical === false) {
            return null;
        }

        if ($prefix === null) {
            return $canonical;
        }

        // /0 would match every address and silently defeat the whole lock.
        if ($prefix < 1 || $prefix > ($isV4 ? 32 : 128)) {
            return null;
        }

        return $canonical . '/' . $prefix;
    }

    private function printable(string $line): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $line) ?? '', 0, 60);
    }
}
