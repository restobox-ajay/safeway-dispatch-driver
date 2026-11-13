<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\OpenApiSpec;
use App\Tests\Support\OpenApiValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Holds docs/api/openapi.yaml to the running application (ADR-060). The spec is hand-written, so these
 * tests are what stop it rotting:
 *   - it must be a well-formed description (unique operation ids, resolvable refs, a 401 on every secured call);
 *   - every example in it must validate against its own schema (an example that lies is worse than none);
 *   - the set of documented calls must equal the set of real /admin-api and /api routes — nothing
 *     undocumented, nothing phantom — and so must the plain count;
 *   - auth schemes, path parameters and bundle labels must match what the router and security config say.
 * The companion OpenApiContractTest then calls every operation for real and validates the responses.
 */
final class OpenApiSpecTest extends KernelTestCase
{
    private OpenApiSpec $spec;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->spec = self::getContainer()->get(OpenApiSpec::class);
    }

    /** @return array<string,Route> "METHOD /path" => route, for every real API route */
    private function realApiRoutes(): array
    {
        $routes = [];
        foreach (self::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            $isApi = $path === '/api' || str_starts_with($path, '/api/') || str_starts_with($path, '/admin-api/');
            if (!$isApi) {
                continue;
            }
            self::assertNotSame([], $route->getMethods(), sprintf('API route "%s" must declare its HTTP methods so it can be documented.', $name));
            foreach ($route->getMethods() as $method) {
                $routes[$method . ' ' . $path] = $route;
            }
        }
        ksort($routes);

        return $routes;
    }

    /** @return array<string,array> "METHOD /path" => operation, for every documented call */
    private function documented(): array
    {
        $documented = [];
        foreach ($this->spec->operations() as $op) {
            $documented[$op['method'] . ' ' . $op['path']] = $op;
        }
        ksort($documented);

        return $documented;
    }

    public function testTheNumberOfDocumentedCallsEqualsTheNumberOfRealApiEndpoints(): void
    {
        $real = $this->realApiRoutes();
        $documented = $this->documented();

        self::assertCount(
            \count($real),
            $documented,
            sprintf(
                "The docs list %d calls but the app has %d API endpoints.\n  Undocumented: %s\n  Phantom: %s",
                \count($documented),
                \count($real),
                implode(', ', array_keys(array_diff_key($real, $documented))) ?: '(none)',
                implode(', ', array_keys(array_diff_key($documented, $real))) ?: '(none)',
            ),
        );
    }

    public function testEveryRealApiRouteIsDocumentedAndNothingDocumentedIsPhantom(): void
    {
        $real = array_keys($this->realApiRoutes());
        $documented = array_keys($this->documented());

        self::assertSame(
            [],
            array_values(array_diff($real, $documented)),
            'These API endpoints exist in the app but are NOT in docs/api/openapi.yaml — document them.',
        );
        self::assertSame(
            [],
            array_values(array_diff($documented, $real)),
            'These calls are documented in docs/api/openapi.yaml but the app has no such endpoint — remove or fix them.',
        );
    }

    public function testSpecIsAWellFormedOpenApi31Document(): void
    {
        $doc = $this->spec->document();

        self::assertStringStartsWith('3.1.', (string) $doc->openapi);
        self::assertNotSame('', (string) ($doc->info->title ?? ''));
        self::assertNotSame('', (string) ($doc->info->version ?? ''));

        $declaredTags = array_map(static fn (object $t): string => $t->name, $doc->tags ?? []);
        $schemes = array_keys((array) ($doc->components->securitySchemes ?? []));
        $ids = [];

        foreach ($this->spec->operations() as $op) {
            $label = $op['method'] . ' ' . $op['path'];
            $o = $op['operation'];

            self::assertMatchesRegularExpression('/^[a-z][A-Za-z0-9]+$/', $op['id'], "$label needs a camelCase operationId.");
            self::assertArrayNotHasKey($op['id'], $ids, "operationId {$op['id']} is used twice.");
            $ids[$op['id']] = true;

            self::assertNotSame('', (string) ($o->summary ?? ''), "$label needs a summary.");
            self::assertNotEmpty($o->tags ?? [], "$label needs a tag.");
            foreach ($o->tags as $tag) {
                self::assertContains($tag, $declaredTags, "$label uses undeclared tag \"$tag\".");
            }

            self::assertCount(1, $o->security ?? [], "$label must declare exactly one security requirement.");
            foreach ((array) $o->security[0] as $scheme => $scopes) {
                self::assertContains($scheme, $schemes, "$label uses undeclared security scheme \"$scheme\".");
            }

            // (array) turns numeric property names like '401' into int keys, so normalise back to strings.
            $statuses = array_map('strval', array_keys((array) $o->responses));
            self::assertNotEmpty(array_filter($statuses, static fn (string $s): bool => $s[0] === '2'), "$label documents no 2xx response.");
            self::assertContains('401', $statuses, "$label is authenticated, so it must document 401.");
            foreach ($o->responses as $status => $response) {
                $resolved = $this->spec->resolve($response);
                self::assertNotSame('', (string) ($resolved->description ?? ''), "$label $status needs a description.");
            }
        }
    }

    public function testEveryRefInTheSpecResolves(): void
    {
        $count = 0;
        $walk = function (mixed $node) use (&$walk, &$count): void {
            if (\is_object($node)) {
                if (isset($node->{'$ref'})) {
                    $this->spec->resolve($node); // throws LogicException when it does not resolve
                    ++$count;
                }
                foreach (get_object_vars($node) as $child) {
                    $walk($child);
                }
            } elseif (\is_array($node)) {
                array_map($walk, $node);
            }
        };
        $walk($this->spec->document());

        self::assertGreaterThan(20, $count, 'the spec should be full of $refs; is it being loaded?');
    }

    public function testEveryExampleInTheSpecValidatesAgainstItsOwnSchema(): void
    {
        $validator = new OpenApiValidator($this->spec);
        $checked = 0;

        foreach ($this->spec->operations() as $op) {
            $label = $op['method'] . ' ' . $op['path'];

            foreach ($op['operation']->responses as $status => $response) {
                $resolved = $this->spec->resolve($response);
                $media = $resolved->content->{'application/json'} ?? null;
                if ($media === null || !isset($media->example)) {
                    continue;
                }
                $pointer = $validator->responseSchemaPointer($op, (string) $status);
                self::assertSame([], $validator->errors($media->example, (string) $pointer), "$label $status: the documented response example does not match its schema.");
                ++$checked;
            }

            $requestPointer = $validator->requestSchemaPointer($op);
            if ($requestPointer !== null) {
                $example = $op['operation']->requestBody->content->{'application/json'}->example ?? null;
                self::assertNotNull($example, "$label documents a request body but gives no example.");
                self::assertSame([], $validator->errors($example, $requestPointer), "$label: the documented request example does not match its schema.");
                ++$checked;
            }

            $parameters = array_merge($op['pathItem']->parameters ?? [], $op['operation']->parameters ?? []);
            foreach ($parameters as $i => $parameter) {
                $resolved = $this->spec->resolve($parameter);
                if (!isset($resolved->example)) {
                    continue;
                }
                $pointer = isset($parameter->{'$ref'})
                    ? substr($parameter->{'$ref'}, 1) . '/schema'
                    : sprintf('/paths/%s/%s/parameters/%d/schema', $validator->escape($op['path']), strtolower($op['method']), $i);
                if (!isset($op['operation']->parameters[$i]) && !isset($parameter->{'$ref'})) {
                    $pointer = sprintf('/paths/%s/parameters/%d/schema', $validator->escape($op['path']), $i);
                }
                self::assertSame([], $validator->errors($resolved->example, $pointer), "$label: parameter \"{$resolved->name}\" example does not match its schema.");
                ++$checked;
            }
        }

        self::assertGreaterThan(30, $checked, 'expected many examples to be checked; is the spec being read?');
    }

    public function testPathParametersInTheSpecMatchTheRouteVariables(): void
    {
        $routes = $this->realApiRoutes();

        foreach ($this->documented() as $key => $op) {
            preg_match_all('/\{(\w+)\}/', $op['path'], $m);
            $inPath = $m[1];

            $declared = [];
            foreach (array_merge($op['pathItem']->parameters ?? [], $op['operation']->parameters ?? []) as $parameter) {
                $p = $this->spec->resolve($parameter);
                if ($p->in === 'path') {
                    self::assertTrue($p->required ?? false, "$key: path parameter {$p->name} must be required.");
                    $declared[] = $p->name;
                }
            }
            sort($inPath);
            sort($declared);
            self::assertSame($inPath, $declared, "$key: {placeholders} in the path and declared path parameters differ.");

            if (isset($routes[$key])) {
                $compiled = $routes[$key]->compile()->getVariables();
                sort($compiled);
                self::assertSame($compiled, $inPath, "$key: the route's variables differ from the documented path parameters.");
            }
        }
    }

    public function testEachCallDocumentsTheAuthSchemeItsFirewallActuallyUses(): void
    {
        foreach ($this->documented() as $key => $op) {
            $scheme = array_key_first((array) $op['operation']->security[0]);
            $expected = str_starts_with($op['path'], '/admin-api/') ? 'adminBearer' : 'userBearer';

            self::assertSame($expected, $scheme, "$key: /admin-api is the stateless admin_api firewall (Admin tokens); /api is the PAT firewall (user tokens).");
        }
    }

    public function testBundleLabelsMatchTheBundleThatActuallyOwnsEachRoute(): void
    {
        foreach ($this->realApiRoutes() as $key => $route) {
            $controller = (string) $route->getDefault('_controller');
            $documentedBundle = $this->documented()[$key]['operation']->{'x-bundle'} ?? null;

            if (preg_match('/^App\\\\Bundle\\\\([A-Za-z0-9]+)\\\\/', $controller, $m) === 1) {
                $expected = strtolower(preg_replace('/(?<!^)([A-Z0-9])/', '-$1', $m[1]) ?? '') . '-bundle';
                self::assertSame($expected, $documentedBundle, "$key is owned by $expected, so the docs must say `x-bundle: $expected`.");
            } else {
                self::assertNull($documentedBundle, "$key is a core route, so the docs must not label it as a bundle endpoint.");
            }
        }
    }

    public function testTheAudienceMarkerMatchesTheRoleTheEndpointReallyDemands(): void
    {
        $routes = $this->realApiRoutes();
        $techSupportOps = 0;

        foreach ($this->documented() as $key => $op) {
            if (!str_starts_with($op['path'], '/admin-api/')) {
                continue;
            }
            $audience = $op['operation']->{'x-audience'} ?? null;
            self::assertContains($audience, [null, OpenApiSpec::AUDIENCE_TECH_SUPPORT], "$key: unknown x-audience.");

            [$class, $method] = explode('::', (string) $routes[$key]->getDefault('_controller'));
            $roles = $this->isGrantedRoles(new \ReflectionMethod($class, $method));
            self::assertNotSame([], $roles, "$key: every admin-api controller must carry #[IsGranted] (see security.yaml).");

            if ($audience === OpenApiSpec::AUDIENCE_TECH_SUPPORT) {
                ++$techSupportOps;
                self::assertSame(['ROLE_TECH_SUPPORT'], $roles, "$key is documented as tech-support only, so the controller must demand exactly ROLE_TECH_SUPPORT.");
                self::assertArrayHasKey('403', (array) $op['operation']->responses, "$key is role-restricted, so it must document 403.");
                foreach ($op['operation']->tags as $tag) {
                    $declared = array_values(array_filter($this->spec->document()->tags, static fn (object $t): bool => $t->name === $tag))[0];
                    self::assertSame(OpenApiSpec::AUDIENCE_TECH_SUPPORT, $declared->{'x-audience'} ?? null, "$key: its tag \"$tag\" must carry the same x-audience so the section is hidden with it.");
                }
            } else {
                self::assertNotContains('ROLE_TECH_SUPPORT', $roles, "$key demands tech support, so the docs must say `x-audience: tech-support` (otherwise other admins are shown a call they cannot make).");
            }
        }

        self::assertGreaterThanOrEqual(12, $techSupportOps, 'the Htaccess Lock API is tech-support only');
    }

    public function testTagsMarkedTechSupportAreUsedByNothingElse(): void
    {
        foreach ($this->spec->document()->tags as $tag) {
            if (($tag->{'x-audience'} ?? null) !== OpenApiSpec::AUDIENCE_TECH_SUPPORT) {
                continue;
            }
            foreach ($this->documented() as $key => $op) {
                if (\in_array($tag->name, $op['operation']->tags, true)) {
                    self::assertSame(OpenApiSpec::AUDIENCE_TECH_SUPPORT, $op['operation']->{'x-audience'} ?? null, "$key sits under the tech-support tag \"{$tag->name}\" but is not itself marked.");
                }
            }
        }
    }

    /** @return list<string> */
    private function isGrantedRoles(\ReflectionMethod $method): array
    {
        $attributes = array_merge($method->getAttributes(IsGranted::class), $method->getDeclaringClass()->getAttributes(IsGranted::class));

        return array_values(array_map(static fn (\ReflectionAttribute $a): string => (string) $a->newInstance()->attribute, $attributes));
    }
}
