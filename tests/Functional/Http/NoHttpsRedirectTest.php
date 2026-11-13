<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies that no HTTP-to-HTTPS redirect is configured in any environment.
 * FEATURE-063.
 */
final class NoHttpsRedirectTest extends WebTestCase
{
    public function testGetLoginOverHttpReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/staff/login');

        self::assertResponseStatusCodeSame(200);
    }

    public function testGetAdminLoginOverHttpReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseStatusCodeSame(200);
    }

    public function testNoRequiresChannelHttpsInSecurityConfig(): void
    {
        $securityYaml = file_get_contents(
            dirname(__DIR__, 3) . '/config/packages/security.yaml'
        );

        self::assertStringNotContainsString(
            'requires_channel: https',
            $securityYaml,
            'security.yaml must not contain requires_channel: https'
        );
    }

    public function testNoForceHttpsInFrameworkConfig(): void
    {
        $frameworkYaml = file_get_contents(
            dirname(__DIR__, 3) . '/config/packages/framework.yaml'
        );

        self::assertStringNotContainsString(
            'force_https',
            $frameworkYaml,
            'framework.yaml must not contain force_https'
        );
        self::assertStringNotContainsString(
            'require_ssl',
            $frameworkYaml,
            'framework.yaml must not contain require_ssl'
        );
    }
}
