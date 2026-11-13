<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(regex_quote:APP_DOMAIN)%`: the value with regex characters escaped, for trusted_hosts. Unescaped, every
 * "." in the domain would match any character, so a lookalike host (dots replaced by "-") would pass.
 */
final class RegexQuoteEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return preg_quote((string) $getEnv($name), '#');
    }

    public static function getProvidedTypes(): array
    {
        return ['regex_quote' => 'string'];
    }
}
