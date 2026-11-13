<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-131 (review C17): framework.trusted_hosts is pinned to the app's real hosts
 * (DEFAULT_URI host + ADMIN_DOMAIN + APP_DOMAIN, all 'localhost' in the test env). A request
 * carrying a spoofed/unknown Host header is rejected with 400 BEFORE any controller runs, so
 * host-header injection (password-reset link poisoning, off-origin redirects) is impossible.
 */
final class TrustedHostTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        // Static Request trusted-host state is reset by the next kernel boot; nothing to undo.
        parent::tearDown();
    }

    public function testTrustedHostIsAccepted(): void
    {
        // The test env's trusted host is 'localhost' (BrowserKit's default host).
        $this->client->request('GET', '/staff/login');

        $this->assertResponseIsSuccessful();
        self::assertNotSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testSpoofedHostHeaderIsRejected(): void
    {
        $this->client->request('GET', '/staff/login', [], [], ['HTTP_HOST' => 'evil.example.com']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * AC5: a forgot-password POST with a spoofed Host cannot poison the reset link, because the
     * spoofed Host is rejected (400) before the controller generates any absolute URL.
     */
    public function testForgotPasswordWithSpoofedHostIsRejectedBeforeGeneratingLink(): void
    {
        $this->client->request(
            'POST',
            '/forgot-password',
            ['email' => 'anyone@example.com'],
            [],
            ['HTTP_HOST' => 'evil.example.com'],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        // The request never reached the controller, so no reset email (which would carry an
        // absolute link built from the request Host) was ever generated.
        $this->assertEmailCount(0);
    }
}
