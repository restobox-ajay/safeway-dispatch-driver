<?php

declare(strict_types=1);

namespace App\Tests\Support\Helper;

use App\Kernel;
use Codeception\Module;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lets the acceptance suite compare the API docs the live server SERVES against the API routes the app
 * really has (ADR-060). The docs side is read over real HTTP by the Cest; the "real" side is the router of
 * the same kernel the live server runs (APP_ENV=acceptance), so it is the actual deployed route table,
 * not a list someone maintains by hand.
 */
class ApiDocsHelper extends Module
{
    /** @var list<string> the path-item keys that are HTTP operations in an OpenAPI document */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param bool $techSupport false = leave out the endpoints only tech support may call (what every other admin
     *                          class is shown); the role is read from the controller's real #[IsGranted], not a list
     *
     * @return list<string> "METHOD /path" for every real /admin-api and /api route, sorted
     */
    public function grabRealApiEndpoints(bool $techSupport = true): array
    {
        $kernel = new Kernel('acceptance', true);
        $kernel->boot();

        try {
            $endpoints = [];
            foreach ($kernel->getContainer()->get('router')->getRouteCollection() as $route) {
                $path = $route->getPath();
                if ($path !== '/api' && !str_starts_with($path, '/api/') && !str_starts_with($path, '/admin-api/')) {
                    continue;
                }
                if (!$techSupport && $this->demandsTechSupport((string) $route->getDefault('_controller'))) {
                    continue;
                }
                foreach ($route->getMethods() as $method) {
                    $endpoints[] = $method . ' ' . $path;
                }
            }
        } finally {
            $kernel->shutdown();
        }

        sort($endpoints);

        return $endpoints;
    }

    private function demandsTechSupport(string $controller): bool
    {
        if (!str_contains($controller, '::')) {
            return false;
        }
        [$class, $method] = explode('::', $controller);
        $reflection = new \ReflectionMethod($class, $method);
        foreach ([...$reflection->getAttributes(IsGranted::class), ...$reflection->getDeclaringClass()->getAttributes(IsGranted::class)] as $attribute) {
            if ($attribute->newInstance()->attribute === 'ROLE_TECH_SUPPORT') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> "METHOD /path" for every call in the OpenAPI JSON the server returned, sorted */
    public function grabDocumentedCalls(string $openApiJson): array
    {
        $document = json_decode($openApiJson, true);
        $this->assertIsArray($document, 'The docs endpoint did not return a JSON object.');
        $this->assertArrayHasKey('paths', $document, 'The docs endpoint returned no "paths".');

        $calls = [];
        foreach ($document['paths'] as $path => $item) {
            foreach (self::HTTP_METHODS as $method) {
                if (isset($item[$method])) {
                    $calls[] = strtoupper($method) . ' ' . $path;
                }
            }
        }
        sort($calls);

        return $calls;
    }

    /** The headline check: as many calls in the docs as endpoints this viewer can call in the app. */
    public function seeDocumentedCallCountEqualsRealEndpointCount(string $openApiJson, bool $techSupport = true): void
    {
        $documented = $this->grabDocumentedCalls($openApiJson);
        $real = $this->grabRealApiEndpoints($techSupport);

        $this->assertNotEmpty($real, 'The app reports no API endpoints at all — is the router being read?');
        $this->assertCount(
            \count($real),
            $documented,
            sprintf(
                "The API docs list %d calls but the app has %d API endpoints.\n  Undocumented: %s\n  Documented but not real: %s",
                \count($documented),
                \count($real),
                implode(', ', array_diff($real, $documented)) ?: '(none)',
                implode(', ', array_diff($documented, $real)) ?: '(none)',
            ),
        );
    }

    /** The stronger check behind the count: the two lists are the very same calls. */
    public function seeDocumentedCallsAreExactlyTheRealEndpoints(string $openApiJson, bool $techSupport = true): void
    {
        $this->assertSame(
            $this->grabRealApiEndpoints($techSupport),
            $this->grabDocumentedCalls($openApiJson),
            'The API docs and the real API endpoints differ (left = real routes, right = documented calls).',
        );
    }

    /** The served document mentions $needle (case-insensitive). */
    public function seeServedSpecMentions(string $openApiJson, string $needle): void
    {
        $this->assertStringContainsStringIgnoringCase($needle, $openApiJson, sprintf('The served API docs should mention "%s".', $needle));
    }

    /** The served document gives no hint of $needle anywhere (paths, tags, schemas, prose). */
    public function seeServedSpecDoesNotMention(string $openApiJson, string $needle): void
    {
        $this->assertStringNotContainsStringIgnoringCase($needle, $openApiJson, sprintf('The served API docs must not advertise "%s" to this viewer.', $needle));
    }
}
