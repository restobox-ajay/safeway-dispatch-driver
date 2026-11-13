<?php

declare(strict_types=1);

namespace App\Tests\Functional\Driver;

use App\Driver\Api\DriverApiClient;
use App\Driver\Api\DriverApiException;
use App\Driver\Api\DriverTokenStore;
use App\Entity\DriverApiSession;
use App\Repository\DriverApiSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * FEATURE-192: the client for the fvstexpress-admin driver API — headers, token renewal (once, even when
 * two requests race), error wording identical to the Android app, offline, idempotency keys.
 */
final class DriverApiClientTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RequestStack $requests;
    private Session $session;
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM driver_api_session');
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/driver/tasks', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setSession($this->session);
        $this->requests = new RequestStack();
        $this->requests->push($request);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM driver_api_session');
        parent::tearDown();
    }

    /** @param list<MockResponse|\Throwable> $responses */
    private function client(array $responses, string $hostHeader = ''): DriverApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[strtolower($k)] = $v;
            }
            $body = $options['body'] ?? '';
            if ($body instanceof \Closure) { // a streamed body (multipart upload): read it as the server would
                $chunks = '';
                while ('' !== $chunk = $body(16372)) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) $body];
            $next = array_shift($responses) ?? throw new \LogicException('Unexpected call ' . $method . ' ' . $url);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
        $store = new DriverTokenStore($this->requests, self::getContainer()->get(DriverApiSessionRepository::class), $this->em);

        return new DriverApiClient($http, $store, $this->requests, 'http://admin.test/driver-api/v1/', $hostHeader);
    }

    private static function json(mixed $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    private static function tokens(string $access = 'acc-1', string $refresh = 'ref-1', int $expiresIn = 900): MockResponse
    {
        return self::json(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $expiresIn, 'driver' => ['id' => 7, 'name' => 'Marcus Chen']]);
    }

    private function row(): ?DriverApiSession
    {
        $this->em->clear();

        return self::getContainer()->get(DriverApiSessionRepository::class)->findOneBy([]);
    }

    public function testLoginSendsTheAndroidHeadersAndKeepsTheTokensOnTheServer(): void
    {
        $client = $this->client([self::tokens()], 'fvstexpress-admin.local');

        $driver = $client->login('EMP-00412', '4321', 'device-abc');

        $this->assertSame('Marcus Chen', $driver['name']);
        $call = $this->sent[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('http://admin.test/driver-api/v1/auth/login', $call['url']);
        $this->assertSame(['login' => 'EMP-00412', 'pin' => '4321', 'device_id' => 'device-abc'], json_decode($call['body'], true));
        $this->assertSame('application/json', $call['headers']['accept']);
        $this->assertSame(DriverApiClient::APP_VERSION, $call['headers']['x-app-version']);
        $this->assertSame('device-abc', $call['headers']['x-device-id']);
        $this->assertSame('203.0.113.7', $call['headers']['x-forwarded-for']);
        $this->assertSame('fvstexpress-admin.local', $call['headers']['host']);
        $this->assertArrayNotHasKey('authorization', $call['headers']);

        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertSame('acc-1', $row->getAccessToken());
        $this->assertSame(7, $row->getDriverId());
        $key = $this->session->get(DriverTokenStore::SESSION_KEY);
        $this->assertIsString($key);
        $this->assertSame(hash('sha256', $key), $row->getKeyHash(), 'only the hash of the session key is stored');
        $this->assertStringNotContainsString('acc-1', serialize($this->session->all()), 'the browser session never holds the tokens');
    }

    public function testWrongPinShowsTheApiWording(): void
    {
        $client = $this->client([self::json(['error' => "That login and PIN don't match.", 'code' => 'BAD_CREDENTIALS'], 401)]);

        try {
            $client->login('EMP-00412', '0000', 'd');
            $this->fail('expected a refusal');
        } catch (DriverApiException $e) {
            $this->assertSame("That login and PIN don't match.", $e->getMessage());
            $this->assertSame('BAD_CREDENTIALS', $e->errorCode);
            $this->assertFalse($e->isSessionExpired());
        }
        $this->assertNull($this->row());
    }

    public function testAuthenticatedCallsCarryTheBearerTokenAndAWriteKeepsTheTapsIdempotencyKey(): void
    {
        $client = $this->client([self::tokens(), self::json(['stops' => []]), self::json(['status' => 'picking_up']), self::json(['ok' => true])]);
        $client->login('EMP-00412', '4321', 'd');

        $this->assertSame(['stops' => []], $client->get('stops', ['view' => 'today']));
        $client->post('stops/P-1/picking-up', ['client_time' => '2026-10-14T09:00:00+00:00'], 'tap-key-1');
        $client->post('work', ['event' => 'start']);

        $this->assertSame('http://admin.test/driver-api/v1/stops?view=today', $this->sent[1]['url']);
        $this->assertSame('Bearer acc-1', $this->sent[1]['headers']['authorization']);
        $this->assertArrayNotHasKey('idempotency-key', $this->sent[1]['headers'], 'reads carry no key');
        $this->assertSame('tap-key-1', $this->sent[2]['headers']['idempotency-key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->sent[3]['headers']['idempotency-key'], 'a write without a key gets a fresh one');
    }

    public function testAnExpiredAccessTokenIsRenewedOnceAndTheCallRetried(): void
    {
        $client = $this->client([
            self::tokens('acc-1', 'ref-1'),
            self::json(['error' => 'Log in again.', 'code' => 'TOKEN_INVALID'], 401),
            self::tokens('acc-2', 'ref-2'),
            self::json(['online' => true]),
        ]);
        $client->login('EMP-00412', '4321', 'd');

        $this->assertSame(['online' => true], $client->post('presence', ['online' => true]));

        $this->assertSame('http://admin.test/driver-api/v1/auth/refresh', $this->sent[2]['url']);
        $this->assertSame(['refresh_token' => 'ref-1'], json_decode($this->sent[2]['body'], true));
        $this->assertSame('Bearer acc-2', $this->sent[3]['headers']['authorization']);
        $this->assertSame($this->sent[1]['headers']['idempotency-key'], $this->sent[3]['headers']['idempotency-key'], 'the retry is the same tap');
        $row = $this->row();
        $this->assertSame('acc-2', $row?->getAccessToken());
        $this->assertSame('ref-2', $row?->getRefreshToken(), 'the rotated refresh token is kept');
    }

    public function testAPhotoRetriedAfterARenewedTokenStillCarriesTheFile(): void
    {
        $client = $this->client([
            self::tokens('acc-1', 'ref-1'),
            self::json(['error' => 'Log in again.', 'code' => 'TOKEN_INVALID'], 401),
            self::tokens('acc-2', 'ref-2'),
            self::json(['photo_id' => 'pho_0123456789abcdef']),
        ]);
        $client->login('EMP-00412', '4321', 'd');
        $file = tempnam(sys_get_temp_dir(), 'pho');
        file_put_contents($file, 'JPEG-BYTES-1234');

        try {
            $result = $client->upload('photos', ['reference' => 'JOB-1'], 'file', $file, 'proof.jpg', 'image/jpeg');
        } finally {
            unlink($file);
        }

        $this->assertSame(['photo_id' => 'pho_0123456789abcdef'], $result);
        $this->assertStringContainsString('JPEG-BYTES-1234', $this->sent[1]['body']);
        $this->assertStringContainsString('JPEG-BYTES-1234', $this->sent[3]['body'], 'the retry sends the photo again, not an empty body');
        $this->assertSame($this->sent[1]['headers']['idempotency-key'], $this->sent[3]['headers']['idempotency-key']);
    }

    public function testATokenAboutToExpireIsRenewedBeforeTheCall(): void
    {
        $client = $this->client([self::tokens('acc-1', 'ref-1', 10), self::tokens('acc-2', 'ref-2'), self::json(['id' => 7])]);
        $client->login('EMP-00412', '4321', 'd');

        $client->get('me');

        $this->assertStringEndsWith('/auth/refresh', $this->sent[1]['url']);
        $this->assertSame('Bearer acc-2', $this->sent[2]['headers']['authorization']);
    }

    public function testNoDatabaseLockIsHeldWhileDispatchRenewsTheToken(): void
    {
        $client = $this->client([self::tokens('acc-1', 'ref-1')]);
        $client->login('EMP-00412', '4321', 'd');
        $store = new DriverTokenStore($this->requests, self::getContainer()->get(DriverApiSessionRepository::class), $this->em);
        $row = $store->current();
        $this->assertNotNull($row);
        $conn = $this->em->getConnection();

        $renewed = $store->refresh($row, 'acc-1', function () use ($conn): array {
            // SQLite has one writer: holding the write lock through a slow call to Dispatch made every other
            // request (sessions, audit) fail with "database is locked" after 5 s.
            $this->assertFalse($conn->isTransactionActive(), 'no transaction (and so no write lock) during the HTTP call');

            return ['access_token' => 'acc-2', 'refresh_token' => 'ref-2', 'expires_in' => 900];
        });

        $this->assertSame('acc-2', $renewed->getAccessToken());
        $this->assertSame('ref-2', $this->row()?->getRefreshToken());
    }

    public function testWhenAnotherRequestAlreadyRenewedTheTokenItIsReusedNotRefreshedAgain(): void
    {
        $client = $this->client([self::tokens('acc-1', 'ref-1')]);
        $client->login('EMP-00412', '4321', 'd');
        // Another request (another PHP process) renewed it meanwhile.
        $this->em->getConnection()->executeStatement("UPDATE driver_api_session SET access_token = 'acc-2', refresh_token = 'ref-2'");
        $store = new DriverTokenStore($this->requests, self::getContainer()->get(DriverApiSessionRepository::class), $this->em);
        $row = $store->current();
        $this->assertNotNull($row);

        $called = false;
        $renewed = $store->refresh($row, 'acc-1', function () use (&$called): array {
            $called = true;

            return ['access_token' => 'x', 'refresh_token' => 'y'];
        });

        $this->assertFalse($called, 'the rotated refresh token must not be spent twice');
        $this->assertSame('acc-2', $renewed->getAccessToken());
    }

    public function testARefusedRefreshSignsTheDriverOut(): void
    {
        $client = $this->client([
            self::tokens(),
            self::json(['code' => 'TOKEN_INVALID'], 401),
            self::json(['error' => 'Log in again.', 'code' => 'REFRESH_INVALID'], 401),
        ]);
        $client->login('EMP-00412', '4321', 'd');

        try {
            $client->get('stops');
            $this->fail('expected the session to end');
        } catch (DriverApiException $e) {
            $this->assertTrue($e->isSessionExpired());
            $this->assertSame(DriverApiException::SESSION_EXPIRED, $e->getMessage());
        }
        $this->assertNull($this->row());
        $this->assertFalse($this->session->has(DriverTokenStore::SESSION_KEY));
    }

    public function testDispatchFailingDuringARefreshKeepsTheDriverSignedIn(): void
    {
        $client = $this->client([self::tokens(), self::json(['code' => 'TOKEN_INVALID'], 401), self::json(['error' => 'Down'], 503)]);
        $client->login('EMP-00412', '4321', 'd');

        try {
            $client->get('stops');
            $this->fail('expected the server error');
        } catch (DriverApiException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertSame('acc-1', $this->row()?->getAccessToken());
    }

    public function testNoConnectionIsWordedLikeTheAndroidApp(): void
    {
        $client = $this->client([self::tokens(), new TransportException('Could not resolve host')]);
        $client->login('EMP-00412', '4321', 'd');

        try {
            $client->get('stops');
            $this->fail('expected offline');
        } catch (DriverApiException $e) {
            $this->assertTrue($e->offline);
            $this->assertSame('No connection to Dispatch. Check your signal and try again.', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, array<mixed>|null, string}> */
    public static function errorWording(): iterable
    {
        yield 'API text wins' => [422, ['error' => 'Add at least one photo.', 'errors' => ['photos' => 'Required.']], 'Add at least one photo.'];
        yield 'else first field error' => [422, ['errors' => ['left_at' => 'Say where you left it.']], 'Say where you left it.'];
        yield '401' => [401, null, 'Your session expired. Please log in again.'];
        yield '403' => [403, null, "You're offline or not allowed to do this."];
        yield '404' => [404, null, 'Not found — it may have been reassigned.'];
        yield '409' => [409, null, 'This stop changed. Pull down to refresh.'];
        yield 'other' => [500, null, 'Server error (500).'];
    }

    /** @param array<mixed>|null $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorWording')]
    public function testErrorsAreWordedLikeTheAndroidApp(int $status, ?array $body, string $expected): void
    {
        $e = DriverApiException::fromResponse($status, $body);

        $this->assertSame($expected, $e->getMessage());
        $this->assertSame($status, $e->status);
    }

    public function testFieldErrorsAreKept(): void
    {
        $e = DriverApiException::fromResponse(422, ['error' => 'Fix the form.', 'errors' => ['left_at' => 'Required.', 'contact' => 'Pick one.']]);

        $this->assertSame(['left_at' => 'Required.', 'contact' => 'Pick one.'], $e->fieldErrors);
    }

    public function testLogoutRevokesAtDispatchAndStillSignsOutWhenOffline(): void
    {
        $client = $this->client([self::tokens(), new TransportException('offline')]);
        $client->login('EMP-00412', '4321', 'd');

        $client->logout();

        $this->assertStringEndsWith('/auth/logout', $this->sent[1]['url']);
        $this->assertSame(['refresh_token' => 'ref-1'], json_decode($this->sent[1]['body'], true));
        $this->assertNull($this->row());
        $this->assertFalse($this->session->has(DriverTokenStore::SESSION_KEY));
    }

    public function testCallingWithoutASignedInDriverIsASessionExpiry(): void
    {
        $client = $this->client([]);

        $this->expectException(DriverApiException::class);
        $this->expectExceptionMessage(DriverApiException::SESSION_EXPIRED);
        $client->get('me');
    }

    public function testAMissingBaseUrlFailsClearly(): void
    {
        $store = new DriverTokenStore($this->requests, self::getContainer()->get(DriverApiSessionRepository::class), $this->em);
        $client = new DriverApiClient(new MockHttpClient(), $store, $this->requests, '', '');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DRIVER_API_BASE_URL is not set');
        $client->login('a', 'b', 'c');
    }
}
