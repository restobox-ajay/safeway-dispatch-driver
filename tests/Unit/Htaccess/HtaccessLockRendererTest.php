<?php

declare(strict_types=1);

namespace App\Tests\Unit\Htaccess;

use App\Htaccess\HtaccessLockRenderer;
use App\Htaccess\HtaccessLockSettings;
use PHPUnit\Framework\TestCase;

/**
 * The exact shapes verified against a real Apache 2.4 (status + body + CIDR + exemptions); these tests
 * pin the text so a refactor cannot silently change what lands in a live .htaccess.
 */
final class HtaccessLockRendererTest extends TestCase
{
    private function render(array $ips = ['203.0.113.0/24', '198.51.100.7'], array $paths = [], int $status = 403, string $file = ''): string
    {
        return (new HtaccessLockRenderer())->render(new HtaccessLockSettings(true, $ips, $paths, $status, $file));
    }

    public function testBlockIsDelimitedByMarkersAndEndsWithANewline(): void
    {
        $block = $this->render();

        self::assertStringStartsWith(HtaccessLockRenderer::BEGIN . "\n", $block);
        self::assertStringEndsWith(HtaccessLockRenderer::END . "\n", $block);
    }

    public function testEachIpBecomesARequireIpLine(): void
    {
        $block = $this->render(['203.0.113.0/24', '2001:db8::1']);

        self::assertStringContainsString("<RequireAny>\n    Require ip 203.0.113.0/24\n    Require ip 2001:db8::1\n</RequireAny>", $block);
        self::assertStringNotContainsString('Require env', $block, 'no exemptions => no env line');
    }

    public function testExemptPathsSetAnEnvVarAllowedThroughByRequireEnv(): void
    {
        $block = $this->render(['203.0.113.7'], ['/health', '/webhooks/*', '/a.b', '/']);

        self::assertStringContainsString('SetEnvIf Request_URI "^/health/?$" HTACCESS_LOCK_EXEMPT', $block);
        self::assertStringContainsString('SetEnvIf Request_URI "^/webhooks/" HTACCESS_LOCK_EXEMPT', $block);
        self::assertStringContainsString('SetEnvIf Request_URI "^/a\.b/?$" HTACCESS_LOCK_EXEMPT', $block);
        self::assertStringContainsString('SetEnvIf Request_URI "^/$" HTACCESS_LOCK_EXEMPT', $block);
        self::assertStringContainsString("    Require ip 203.0.113.7\n    Require env HTACCESS_LOCK_EXEMPT\n", $block);
    }

    public function testStatus403WithAFileServesThatFile(): void
    {
        $block = $this->render(status: 403, file: '/blocked.html');

        self::assertStringContainsString("ErrorDocument 403 /blocked.html\n", $block);
        self::assertStringNotContainsString('RewriteRule', $block);
        self::assertStringNotContainsString('ErrorDocument 404', $block);
    }

    public function testStatus403WithoutAFileGivesAnEmptyBody(): void
    {
        $block = $this->render(status: 403);

        self::assertStringContainsString("ErrorDocument 403 \" \"\n", $block);
        self::assertStringNotContainsString('RewriteRule', $block);
    }

    public function testStatus404RemapsTheInternal403RedirectToARealNotFound(): void
    {
        $block = $this->render(status: 404, file: '/blocked.html');

        self::assertStringContainsString("RewriteCond %{ENV:REDIRECT_STATUS} =403\n    RewriteRule ^ - [R=404,L]", $block);
        self::assertStringContainsString("ErrorDocument 403 /blocked.html\nErrorDocument 404 /blocked.html\n", $block);
    }

    public function testStatus404WithoutAFileRedirectsToAMissingPathAndBlanksThe404Body(): void
    {
        $block = $this->render(status: 404);

        self::assertStringContainsString("ErrorDocument 403 /__htaccess_lock__\nErrorDocument 404 \" \"\n", $block);
    }

    public function testAnEmptyWhitelistDeniesEveryone(): void
    {
        self::assertStringContainsString("    Require all denied\n", $this->render([]));
    }
}
