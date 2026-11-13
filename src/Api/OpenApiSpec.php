<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\Yaml\Yaml;

/**
 * The hand-written OpenAPI description of the app's two REST surfaces (docs/api/openapi.yaml, ADR-060).
 *
 * One loader serves everything: the admin "API Docs" page, and the tests that hold the spec to the real
 * routes and responses. Loading the same file the docs UI serves means the tests can never validate a
 * different document from the one people read.
 */
class OpenApiSpec
{
    /** @var list<string> the path-item keys that are HTTP operations */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private ?\stdClass $document = null;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The parsed document; YAML maps become objects so empty maps stay `{}` when re-encoded. */
    public function document(): \stdClass
    {
        return $this->document ??= $this->load();
    }

    public function json(): string
    {
        return json_encode($this->document(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Marker (on an operation or a tag) for endpoints only tech support may call. */
    public const AUDIENCE_TECH_SUPPORT = 'tech-support';

    /**
     * The document a given viewer should read: everything for tech support; for everyone else the tech-support-only
     * operations and tags are removed, along with any schema/response/parameter that nothing left refers to — so
     * endpoints a viewer cannot call are not advertised to them. The tests' own copy (document()) is never pruned.
     */
    public function jsonFor(bool $techSupport): string
    {
        if ($techSupport) {
            return $this->json();
        }

        /** @var \stdClass $doc a deep copy, so pruning never touches the shared document */
        $doc = json_decode(json_encode($this->document(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        foreach (get_object_vars($doc->paths ?? new \stdClass()) as $path => $item) {
            foreach (self::HTTP_METHODS as $method) {
                if (($item->{$method}->{'x-audience'} ?? null) === self::AUDIENCE_TECH_SUPPORT) {
                    unset($item->{$method});
                }
            }
            if (!array_intersect(self::HTTP_METHODS, array_keys(get_object_vars($item)))) {
                unset($doc->paths->{$path});
            }
        }
        $doc->tags = array_values(array_filter(
            $doc->tags ?? [],
            static fn (\stdClass $tag): bool => ($tag->{'x-audience'} ?? null) !== self::AUDIENCE_TECH_SUPPORT,
        ));

        $reachable = [];
        $visit = function (mixed $node) use (&$visit, &$reachable, $doc): void {
            if (\is_array($node)) {
                array_walk($node, $visit);

                return;
            }
            if (!\is_object($node)) {
                return;
            }
            if (isset($node->{'$ref'}) && !isset($reachable[$node->{'$ref'}])) {
                $reachable[$node->{'$ref'}] = true;
                $target = $doc;
                foreach (explode('/', substr((string) $node->{'$ref'}, 2)) as $segment) {
                    $target = $target->{$segment} ?? null;
                }
                $visit($target);
            }
            foreach (get_object_vars($node) as $child) {
                $visit($child);
            }
        };
        $visit($doc->paths);

        foreach (['schemas', 'responses', 'parameters'] as $kind) {
            foreach (array_keys(get_object_vars($doc->components->{$kind} ?? new \stdClass())) as $name) {
                if (!isset($reachable['#/components/' . $kind . '/' . $name])) {
                    unset($doc->components->{$kind}->{$name});
                }
            }
        }

        return json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Every documented call, in document order.
     *
     * @return list<array{id:string,method:string,path:string,operation:\stdClass,pathItem:\stdClass}>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document()->paths ?? [] as $path => $pathItem) {
            foreach (self::HTTP_METHODS as $method) {
                if (!isset($pathItem->{$method})) {
                    continue;
                }
                $operations[] = [
                    'id' => (string) ($pathItem->{$method}->operationId ?? ''),
                    'method' => strtoupper($method),
                    'path' => (string) $path,
                    'operation' => $pathItem->{$method},
                    'pathItem' => $pathItem,
                ];
            }
        }

        return $operations;
    }

    /** Resolve a local `#/components/...` reference, or return the node unchanged when it is not a $ref. */
    public function resolve(mixed $node): mixed
    {
        while (\is_object($node) && isset($node->{'$ref'})) {
            $ref = (string) $node->{'$ref'};
            if (!str_starts_with($ref, '#/')) {
                throw new \LogicException(sprintf('Only local $ref values are supported, got "%s".', $ref));
            }
            $target = $this->document();
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!isset($target->{$segment})) {
                    throw new \LogicException(sprintf('Unresolvable $ref "%s".', $ref));
                }
                $target = $target->{$segment};
            }
            $node = $target;
        }

        return $node;
    }

    private function load(): \stdClass
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('OpenAPI spec not found at %s.', $this->path));
        }
        $document = Yaml::parseFile($this->path, Yaml::PARSE_OBJECT_FOR_MAP);
        if (!$document instanceof \stdClass) {
            throw new \RuntimeException(sprintf('%s is not a YAML mapping.', $this->path));
        }

        return $document;
    }
}
