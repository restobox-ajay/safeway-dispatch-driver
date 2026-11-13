<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ site_embed() }}` prints the site embed script on every page (owner, 2026-11-13):
 *   <script src="SITE_EMBED_SRC" data-site="SITE_EMBED_ID" async></script>
 * Both come from .env / .env.local; either empty = nothing is printed (the script is off). The address must be https:// with a host and a
 * path of plain characters, the id letters, digits, "-" and "_" only, so a setting can never put anything else into a page.
 */
final class SiteEmbedExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(env: 'default::SITE_EMBED_SRC')] private readonly ?string $src = null,
        #[Autowire(env: 'default::SITE_EMBED_ID')] private readonly ?string $site = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('site_embed', $this->tag(...), ['is_safe' => ['html']])];
    }

    public function tag(): string
    {
        $src = trim((string) $this->src);
        $site = trim((string) $this->site);
        if ($src === '' || $site === ''
            || preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $src) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $site) !== 1) {
            return '';
        }

        return sprintf('<script src="%s" data-site="%s" async></script>', htmlspecialchars($src, \ENT_QUOTES), htmlspecialchars($site, \ENT_QUOTES));
    }
}
