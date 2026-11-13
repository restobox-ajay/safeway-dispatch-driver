<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(regex_quote:NAME)%` — an env value made safe to place inside a regular expression.
 *
 * framework.trusted_hosts takes regexes. With a domain pasted in as-is, every "." matched any character, so
 * `driver-fvstexpress-app-dev-rp017-webhelplogin.com` (registrable by anyone) passed as a trusted host, and a
 * forgot-password post carrying it got the victim's reset link built on the attacker's domain (audit #1).
 */
final class RegexQuoteEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return preg_quote((string) $getEnv($name), '{');
    }

    public static function getProvidedTypes(): array
    {
        return ['regex_quote' => 'string'];
    }
}
