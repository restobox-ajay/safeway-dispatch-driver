<?php

declare(strict_types=1);

namespace App\Tests\Support\Helper;

use Codeception\Module;

/**
 * Codeception module for driving the JSON Admin API over real HTTP through the
 * shared PhpBrowser client.
 *
 * PhpBrowser's high-level actions (amOnPage/submitForm) cannot send arbitrary verbs
 * (PATCH/DELETE) together with a custom Authorization header and a raw JSON body, and
 * the acceptance suite has no REST/Asserts module enabled. This helper therefore
 * delegates to PhpBrowser's low-level `_request`/`_getResponseContent` (its Helper-facing
 * `@api` methods) and exposes the response assertions the actor otherwise lacks. Because
 * Codeception injects a single PhpBrowser instance shared by every module and the actor,
 * the response produced here is the same one the actor's own actions would read.
 */
class ApiHelper extends Module
{
    /**
     * Send an HTTP request to an API endpoint with an optional Bearer token and an
     * optional JSON body. The JSON is sent as the raw request content (not form data),
     * matching how the API controllers read it via $request->getContent().
     *
     * @param array<string, mixed>|null $json
     */
    public function sendApiRequest(string $method, string $url, ?array $json = null, ?string $bearerToken = null): void
    {
        $server = [];
        if ($bearerToken !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        }

        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $this->phpBrowser()->_request($method, $url, [], [], $server, $content);
    }

    /**
     * Assert the HTTP status code of the last API response.
     */
    public function seeApiResponseCodeIs(int $expected): void
    {
        $this->assertSame(
            $expected,
            (int) $this->phpBrowser()->_getResponseStatusCode(),
            sprintf('Expected API response status %d. Body: %s', $expected, $this->phpBrowser()->_getResponseContent())
        );
    }

    /** Assert the raw body of the last API response does NOT contain the given substring. */
    public function seeApiResponseDoesNotContain(string $needle): void
    {
        $this->assertStringNotContainsString(
            $needle,
            $this->phpBrowser()->_getResponseContent(),
            sprintf('Expected API response body not to contain "%s".', $needle)
        );
    }

    /**
     * Assert the raw body of the last API response contains the given substring. Used to
     * check JSON keys (e.g. `"meta"`) and values (e.g. an email) without a JSON-path module.
     */
    public function seeApiResponseContains(string $needle): void
    {
        $this->assertStringContainsString(
            $needle,
            $this->phpBrowser()->_getResponseContent(),
            sprintf('Expected API response body to contain "%s".', $needle)
        );
    }

    private function phpBrowser(): \Codeception\Module\PhpBrowser
    {
        /** @var \Codeception\Module\PhpBrowser $module */
        $module = $this->getModule('PhpBrowser');

        return $module;
    }
}
