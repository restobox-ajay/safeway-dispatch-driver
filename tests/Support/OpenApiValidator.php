<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Api\OpenApiSpec;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates real request/response data against the schemas of docs/api/openapi.yaml.
 *
 * The whole document is registered once as a JSON Schema resource and every check points at a JSON Pointer
 * inside it, so `$ref`s between components resolve exactly as written in the spec (no copies that could
 * diverge from what the docs UI serves).
 */
final class OpenApiValidator
{
    private const BASE = 'https://contract.test/openapi.json';

    private readonly Validator $validator;

    public function __construct(private readonly OpenApiSpec $spec)
    {
        $document = clone $spec->document();
        $document->{'$id'} = self::BASE;

        $this->validator = new Validator();
        $this->validator->setMaxErrors(25);
        $this->validator->resolver()->registerRaw($document);
    }

    /**
     * The JSON Pointer to a response's JSON schema, following a `$ref` to a shared component if the
     * response is one. Returns null when the response documents no JSON body (e.g. 204).
     *
     * @param array{id:string,method:string,path:string,operation:\stdClass,pathItem:\stdClass} $operation
     */
    public function responseSchemaPointer(array $operation, string $status): ?string
    {
        $response = $operation['operation']->responses->{$status} ?? null;
        if ($response === null) {
            return null;
        }

        $base = isset($response->{'$ref'})
            ? substr((string) $response->{'$ref'}, 1)
            : sprintf('/paths/%s/%s/responses/%s', $this->escape($operation['path']), strtolower($operation['method']), $status);

        $resolved = $this->spec->resolve($response);

        return isset($resolved->content->{'application/json'}->schema)
            ? $base . '/content/application~1json/schema'
            : null;
    }

    /** @param array{id:string,method:string,path:string,operation:\stdClass,pathItem:\stdClass} $operation */
    public function requestSchemaPointer(array $operation): ?string
    {
        if (!isset($operation['operation']->requestBody->content->{'application/json'}->schema)) {
            return null;
        }

        return sprintf(
            '/paths/%s/%s/requestBody/content/application~1json/schema',
            $this->escape($operation['path']),
            strtolower($operation['method']),
        );
    }

    /**
     * @param mixed $data decoded with objects (json_decode(..., false)), so `{}` and `[]` stay distinct
     *
     * @return list<string> human-readable violations; empty when the data conforms
     */
    public function errors(mixed $data, string $pointer): array
    {
        $result = $this->validator->validate($data, self::BASE . '#' . $pointer);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error()) as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = ($path === '/' ? '(root)' : $path) . ': ' . $message;
            }
        }

        return $errors;
    }

    /**
     * Escape a spec path for use inside a JSON Pointer (RFC 6901) carried in a URI fragment: `~`/`/` per the
     * RFC, and the literal `{` `}` of a path template percent-encoded (they are not legal in a URI).
     */
    public function escape(string $segment): string
    {
        return str_replace(['~', '/', '{', '}'], ['~0', '~1', '%7B', '%7D'], $segment);
    }
}
