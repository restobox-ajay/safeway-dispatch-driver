<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Bundle\AuthWebhook\Service\HttpWebhookDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security review C2: the webhook URL is a free-text, admin-configured value that flows
 * into file_get_contents. HttpWebhookDispatcher::isAllowedUrl is the SSRF guard — it must
 * reject non-http(s) schemes and any host resolving to a loopback/private/reserved address.
 */
final class WebhookSsrfGuardTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function blockedUrls(): array
    {
        return [
            'file scheme (local read)' => ['file:///etc/passwd'],
            'ftp scheme'               => ['ftp://example.com/x'],
            'php wrapper'              => ['php://filter/resource=/etc/passwd'],
            'gopher scheme'            => ['gopher://127.0.0.1:6379/'],
            'no scheme'                => ['/admin/config'],
            'garbage'                  => ['not a url'],
            'loopback v4'              => ['http://127.0.0.1/hook'],
            'loopback v6'              => ['http://[::1]/hook'],
            'cloud metadata'           => ['http://169.254.169.254/latest/meta-data/iam/'],
            'private 10/8'             => ['http://10.0.0.5/hook'],
            'private 192.168/16'       => ['https://192.168.1.10/hook'],
            'private 172.16/12'        => ['http://172.16.0.1/hook'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedUrlsAreRejected(string $url): void
    {
        self::assertFalse(HttpWebhookDispatcher::isAllowedUrl($url), $url . ' must be blocked');
    }

    /** @return array<string,array{0:string}> */
    public static function allowedUrls(): array
    {
        // Public IP literals (no DNS needed): example.com and a public resolver.
        return [
            'public v4 http'  => ['http://93.184.216.34/hook'],
            'public v4 https' => ['https://8.8.8.8/webhook'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function testPubliclyRoutableUrlsAreAllowed(string $url): void
    {
        self::assertTrue(HttpWebhookDispatcher::isAllowedUrl($url), $url . ' should be allowed');
    }
}
