<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\OpenApiSpec;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin "API Docs" page and the OpenAPI document it renders (ADR-060). Open to EVERY admin class —
 * any admin role can be issued an admin API token — and to nobody else; the spec is served through the
 * gated route, not as a static file, so the API description is not public.
 */
final class ApiDocsPageTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM admin WHERE email LIKE 'apidocs-%@example.com'");
        $conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'apidocs-%@example.com'");
        $conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $conn->executeStatement('DELETE FROM admin_sessions');
        $this->em->clear();
    }

    /** @return iterable<string,array{string}> */
    public static function adminClasses(): iterable
    {
        yield 'plain admin' => ['ROLE_ADMIN'];
        yield 'superadmin' => ['ROLE_SUPER_ADMIN'];
        yield 'tech-support' => ['ROLE_TECH_SUPPORT'];
    }

    private function loginAs(string $role): void
    {
        if ($role === 'ROLE_TECH_SUPPORT') {
            $this->loginAsEnrolledTechSupport('apidocs-ts@example.com');

            return;
        }
        $this->createTestAdmin('apidocs-' . strtolower($role) . '@example.com', roles: [$role]);
        $this->loginAsAdmin('apidocs-' . strtolower($role) . '@example.com');
    }

    #[DataProvider('adminClasses')]
    public function testEveryAdminClassSeesThePageWithAWorkingSpecUrlAndSidebarLink(string $role): void
    {
        $this->loginAs($role);

        $this->client->request('GET', '/admin/api-docs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API Docs');
        self::assertSelectorExists('#swagger-ui[data-spec-url="/admin/api-docs/openapi.json"]');
        self::assertSelectorExists('link[rel=stylesheet][href="/vendor/swagger-ui/swagger-ui.css"]');
        self::assertSelectorExists('script[src="/vendor/swagger-ui/swagger-ui-bundle.js"]');
        self::assertSelectorExists('script[src="/vendor/swagger-ui/swagger-initializer.js"]');
        self::assertSelectorExists('aside.sidebar a[href="/admin/api-docs"]', 'the nav must link to the docs');
        self::assertSelectorTextContains('.warning', 'real requests');
        self::assertStringContainsString('app:admin:create-api-token', (string) $this->client->getResponse()->getContent(), 'the page must say how to get a token');
    }

    #[DataProvider('adminClasses')]
    public function testEveryAdminClassCanFetchTheSpecAndItIsTheFileOnDiskMinusWhatTheyCannotCall(string $role): void
    {
        $this->loginAs($role);

        $this->client->request('GET', '/admin/api-docs/openapi.json');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'an authenticated API description must not be cached by intermediaries');

        $served = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $onDisk = self::getContainer()->get(OpenApiSpec::class)->document();
        self::assertStringStartsWith('3.1.', $served->openapi);
        self::assertGreaterThanOrEqual(10, \count((array) $served->paths));

        $servedOps = array_map(static fn (array $o): string => $o['method'] . ' ' . $o['path'], $this->operationsOf($served));
        $diskOps = $this->operationsOf($onDisk);
        $expected = array_map(
            static fn (array $o): string => $o['method'] . ' ' . $o['path'],
            array_filter($diskOps, static fn (array $o): bool => $role === 'ROLE_TECH_SUPPORT' || ($o['operation']->{'x-audience'} ?? null) !== OpenApiSpec::AUDIENCE_TECH_SUPPORT),
        );
        self::assertSame(array_values($expected), $servedOps, 'served = every documented call this admin class may actually make');

        if ($role === 'ROLE_TECH_SUPPORT') {
            self::assertEquals($onDisk, $served, 'tech support gets docs/api/openapi.yaml untouched');
        }
    }

    #[DataProvider('adminClasses')]
    public function testTheHtaccessLockApiIsDocumentedForTechSupportAndInvisibleToEveryoneElse(string $role): void
    {
        $this->loginAs($role);

        $this->client->request('GET', '/admin/api-docs/openapi.json');
        $raw = (string) $this->client->getResponse()->getContent();
        $served = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

        if ($role === 'ROLE_TECH_SUPPORT') {
            self::assertObjectHasProperty('/admin-api/htaccess-lock/ips', $served->paths);
            self::assertContains('Admin htaccess lock', array_map(static fn (object $t): string => $t->name, $served->tags));
            self::assertObjectHasProperty('HtaccessLock', $served->components->schemas);

            return;
        }

        // Not merely the paths: the tag, the schemas, the shared responses and any prose must not advertise it.
        self::assertStringNotContainsString('htaccess', strtolower($raw), 'a viewer who cannot call the Htaccess Lock API must not be told it exists');
        self::assertObjectNotHasProperty('/admin-api/htaccess-lock', $served->paths);
        self::assertObjectNotHasProperty('HtaccessLock', $served->components->schemas);
        self::assertObjectNotHasProperty('Forbidden', $served->components->responses);
        self::assertObjectHasProperty('/admin-api/users', $served->paths, 'everything they CAN call is still there');
        self::assertObjectHasProperty('Error', $served->components->schemas, 'shared schemas still used by remaining operations are kept');
    }

    /** @return list<array{method:string,path:string,operation:object}> */
    private function operationsOf(object $document): array
    {
        $ops = [];
        foreach ($document->paths as $path => $item) {
            foreach (['get', 'put', 'post', 'delete', 'patch'] as $method) {
                if (isset($item->{$method})) {
                    $ops[] = ['method' => strtoupper($method), 'path' => (string) $path, 'operation' => $item->{$method}];
                }
            }
        }

        return $ops;
    }

    public function testAnonymousVisitorsAreSentToTheAdminLoginForBothRoutes(): void
    {
        $this->client->request('GET', '/admin/api-docs');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', '/admin/api-docs/openapi.json');
        self::assertResponseRedirects('/admin/login');
    }

    public function testAnOrdinaryUserCannotReadTheApiDocs(): void
    {
        $this->createTestUser('apidocs-user@example.com', password: 'userpass');
        $this->loginUser('apidocs-user@example.com', 'userpass', followRedirect: true);

        $this->client->request('GET', '/admin/api-docs');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', '/admin/api-docs/openapi.json');
        self::assertResponseRedirects('/admin/login');
    }

    public function testTheVendoredSwaggerUiIsPresentWithItsLicenceAndABootScriptThatHardCodesNoRoute(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/public/vendor/swagger-ui';

        foreach (['swagger-ui-bundle.js' => 100_000, 'swagger-ui.css' => 10_000, 'swagger-initializer.js' => 100, 'LICENSE' => 1_000, 'NOTICE' => 10] as $file => $minBytes) {
            self::assertFileExists("$dir/$file");
            self::assertGreaterThan($minBytes, filesize("$dir/$file"), "$file looks truncated.");
        }
        self::assertStringContainsString('Apache License', (string) file_get_contents("$dir/LICENSE"));

        $init = (string) file_get_contents("$dir/swagger-initializer.js");
        self::assertStringContainsString("getAttribute('data-spec-url')", $init);
        self::assertStringNotContainsString('/admin/api-docs', $init, 'the boot script must read the spec URL from the page, never hard-code a route');
    }
}
