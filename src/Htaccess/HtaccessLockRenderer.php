<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Renders the managed `.htaccess` block for a {@see HtaccessLockSettings}.
 *
 * Uses Apache 2.4 `Require` authorization (which LiteSpeed implements), so CIDR and IPv6 work natively:
 *
 *   - `Require ip` lines allow the whitelist; `Require env` lets exempt paths through for any IP.
 *   - A plain `Require` denial always answers 403. A *real* 404 needs an `ErrorDocument 403` internal
 *     redirect plus a rewrite that turns that redirect (REDIRECT_STATUS=403) into a 404 — verified
 *     against Apache 2.4. With no error file the redirect target is a path that does not exist and the
 *     404 body is a blank text document.
 *   - The error file is served to blocked clients because ErrorDocument redirects are not re-authorised.
 *
 * Callers must only pass values produced by {@see HtaccessLockValidator}; nothing here escapes a
 * free-form string. The only interpolated values are canonical IPs/CIDRs, paths from the strict URL-path
 * alphabet (regex-escaped below), and a status code.
 */
final class HtaccessLockRenderer
{
    public const BEGIN = '# ###> app/htaccess-lock ###';
    public const END = '# ###< app/htaccess-lock ###';

    private const ENV_VAR = 'HTACCESS_LOCK_EXEMPT';

    /** Internal redirect target for the blank-404 case: deliberately a path that does not exist. */
    private const BLANK_TARGET = '/__htaccess_lock__';

    public function render(HtaccessLockSettings $settings): string
    {
        $lines = [
            self::BEGIN,
            '# Managed by the admin panel (Htaccess Lock). Edits inside this block are overwritten.',
        ];

        foreach ($settings->exemptPaths as $path) {
            $lines[] = sprintf('SetEnvIf Request_URI "%s" %s', $this->exemptPattern($path), self::ENV_VAR);
        }

        $lines[] = '<RequireAny>';
        if ($settings->ips === []) {
            $lines[] = '    Require all denied';
        }
        foreach ($settings->ips as $ip) {
            $lines[] = '    Require ip ' . $ip;
        }
        if ($settings->exemptPaths !== []) {
            $lines[] = '    Require env ' . self::ENV_VAR;
        }
        $lines[] = '</RequireAny>';

        $file = $settings->errorFile;

        if ($settings->statusCode === HtaccessLockSettings::STATUS_NOT_FOUND) {
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    RewriteEngine On';
            $lines[] = '    RewriteCond %{ENV:REDIRECT_STATUS} =403';
            $lines[] = '    RewriteRule ^ - [R=404,L]';
            $lines[] = '</IfModule>';
            $lines[] = 'ErrorDocument 403 ' . ($file !== '' ? $file : self::BLANK_TARGET);
            $lines[] = 'ErrorDocument 404 ' . ($file !== '' ? $file : '" "');
        } else {
            $lines[] = 'ErrorDocument 403 ' . ($file !== '' ? $file : '" "');
        }

        $lines[] = self::END;

        return implode("\n", $lines) . "\n";
    }

    /** Exact path (optional trailing slash), or a prefix when the path ends in `*`. */
    private function exemptPattern(string $path): string
    {
        if (str_ends_with($path, '*')) {
            return '^' . $this->quote(substr($path, 0, -1));
        }

        return '^' . $this->quote(rtrim($path, '/') === '' ? '/' : rtrim($path, '/')) . ($path === '/' ? '$' : '/?$');
    }

    /** Escape the regex metacharacters the path alphabet can contain (`.` is the only one). */
    private function quote(string $literal): string
    {
        return str_replace('.', '\\.', $literal);
    }
}
