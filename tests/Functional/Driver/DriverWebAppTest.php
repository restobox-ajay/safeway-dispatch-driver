<?php

declare(strict_types=1);

namespace App\Tests\Functional\Driver;

use App\Tests\Support\Driver\FakeAdminApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The driver web app end to end through the real firewall, templates and /driver/api pass-through, with the
 * fvstexpress-admin API scripted (FakeAdminApi): login/logout (FEATURE-193), shell (194), tasks and route
 * (195), stop pages (196…198), failed (199), messages (200), profile/hours (201), analytics (204).
 */
final class DriverWebAppTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeAdminApi::reset();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM driver_api_session');
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement("DELETE FROM login_attempts WHERE realm = 'driver'");
    }

    private function logIn(array $driver = []): void
    {
        FakeAdminApi::signedIn($driver);
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form')->form(['login' => 'EMP-00412', 'pin' => '4321']));
        $this->assertResponseRedirects('/driver/tasks');
    }

    private static function stopSummary(array $over = []): array
    {
        return $over + [
            'stop_key' => 'P-118', 'leg' => 'pickup', 'seq' => 1, 'urgent' => false, 'status' => 'assigned',
            'address' => '1200 Main St, Vancouver', 'buzzer' => null, 'business' => 'Acme Dental', 'contact' => 'Lee Wong',
            'window' => ['start' => '09:00', 'end' => '11:00'], 'deadline_label' => 'By 11:00 AM',
            'orders' => [['reference' => 'JOB-118', 'name' => 'Lab samples']], 'completed_at' => null,
        ];
    }

    private static function stopDetail(array $over = []): array
    {
        return $over + [
            'stop_key' => 'P-118', 'leg' => 'pickup', 'urgent' => false, 'status' => 'assigned',
            'address' => '1200 Main St, Vancouver', 'buzzer' => null, 'business' => 'Acme Dental',
            'window' => ['start' => '09:00', 'end' => '11:00'], 'note' => 'Ring twice', 'no_response_instructions' => null,
            'contacts' => [['role' => 'pickup_contact', 'name' => 'Lee Wong', 'phone' => '+1 604 555 0199']],
            'orders' => [
                ['reference' => 'JOB-118', 'name' => 'Lab samples', 'status' => 'assigned', 'package_count' => 2, 'sender' => 'Lee Wong', 'recipient' => 'Dr. Kim', 'dropoff_stop_key' => 'D-118', 'sender_phone' => '+1 604 555 0199', 'recipient_phone' => null],
            ],
            'timeline' => [['status' => 'assigned', 'at' => '2026-10-14T07:30:00-07:00']],
            'banner' => null, 'proof' => null, 'next_allowed' => ['picking_up'], 'can_undo' => false,
        ];
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/driver/tasks');
        preg_match('/csrf: "([^"]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'pages carry the action CSRF token');

        return $m[1];
    }

    // ---------------------------------------------------------------- FEATURE-193 login / logout

    /** @return iterable<string, array{string}> */
    public static function driverLogins(): iterable
    {
        yield 'phone number' => ['(604) 555-0110'];
        yield 'employee card #' => ['EMP-00412'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverLogins')]
    public function testTheMockupLoginFormSignsInWithAPhoneNumberOrACardNumber(string $login): void
    {
        FakeAdminApi::signedIn();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('.login-screen form')->form(['login' => '  ' . $login . ' ', 'pin' => '4321']));

        $this->assertResponseRedirects('/driver/tasks');
        $calls = FakeAdminApi::callsTo('POST', 'auth/login');
        $this->assertCount(1, $calls);
        $sent = json_decode($calls[0]['body'], true);
        $this->assertSame($login, $sent['login'], 'the login field reaches Dispatch as typed, trimmed');
        $this->assertSame('4321', $sent['pin'], 'the PIN field reaches Dispatch');
        $this->assertMatchesRegularExpression('/^web-[a-f0-9]{32}$/', $sent['device_id']);
    }

    public function testLoginPageFollowsTheMockupAndGivesTheBrowserADeviceId(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.login-brand h1', 'FVST Express');
        $this->assertSelectorTextContains('.login-brand .brand-mark', 'FV');
        $this->assertCount(1, $crawler->filter('.phone-frame .app-screen .login-screen form'), 'the mockup login.html screen');
        $this->assertSame(['Phone number or employee card #', 'PIN / Password'], $crawler->filter('form label')->each(static fn ($l) => $l->text()), 'the mockup login.html fields');
        $this->assertSelectorTextContains('button[type="submit"]', 'Log in');
        $this->assertSelectorTextContains('.login-screen', 'Forgot your PIN? Contact dispatch.');
        $this->assertCount(0, $crawler->filter('.auth-card'), 'no longer the admin sign-in card');
        $this->assertCount(1, $crawler->filter('input[name="login"]'));
        $this->assertCount(1, $crawler->filter('input[name="pin"][type="password"]'));
        $this->assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
        $cookie = $this->client->getCookieJar()->get('fvst_device', '/');
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $cookie->getValue());
    }

    public function testTheOldDriverLoginAddressAndStaffLoginStillWork(): void
    {
        $this->client->request('GET', '/driver/login');
        $this->assertResponseRedirects('/login', 301, 'bookmarks and home-screen icons made before the move');

        $crawler = $this->client->request('GET', '/staff/login');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[name="email"]'), 'staff keep their email + password sign-in');
        $this->assertCount(0, $crawler->filter('input[name="pin"]'));
    }

    public function testAWrongPinShowsTheAdminApisWordsAndSignsNobodyIn(): void
    {
        FakeAdminApi::answer('POST', 'auth/login', 401, ['error' => "That login and PIN don't match. After 5 wrong tries the login is locked for a while.", 'code' => 'BAD_CREDENTIALS']);
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form')->form(['login' => 'EMP-00412', 'pin' => '0000']));
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.form-error', "That login and PIN don't match.");
        $this->assertSame('EMP-00412', $this->client->getCrawler()->filter('input[name="login"]')->attr('value'), 'the login is kept');
        $this->client->request('GET', '/driver/tasks');
        $this->assertResponseRedirects('/login');
    }

    public function testRepeatedWrongPinsFromOneConnectionAreThrottledHereBeforeReachingDispatch(): void
    {
        // Dispatch sees every driver login coming from this server, so its own per-IP limit cannot tell one
        // phone from another: this app limits each phone's connection itself (10 tries per 5 minutes).
        FakeAdminApi::answer('POST', 'auth/login', 401, ['error' => "That login and PIN don't match.", 'code' => 'BAD_CREDENTIALS']);
        for ($i = 1; $i <= 11; ++$i) {
            $crawler = $this->client->request('GET', '/login');
            $this->client->submit($crawler->filter('form')->form(['login' => 'EMP-' . $i, 'pin' => '0000']));
            $this->assertResponseRedirects('/login', null, 'try ' . $i);
        }

        $this->assertCount(10, FakeAdminApi::callsTo('POST', 'auth/login'), 'the 11th try never reached Dispatch');
        $this->client->followRedirect();
        $this->assertSelectorExists('.form-error');
    }

    public function testASuccessfulDriverLoginDoesNotCountAgainstTheLimit(): void
    {
        $this->logIn();
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne("SELECT COUNT(*) FROM login_attempts WHERE realm = 'driver'");
        $this->assertSame(0, (int) $count);
    }

    public function testEmptyFieldsAreRefusedWithoutAskingDispatch(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form')->form(['login' => 'EMP-00412', 'pin' => '']));
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.form-error', 'Enter your phone number or employee card # and your PIN.');
        $this->assertSame([], FakeAdminApi::callsTo('POST', 'auth/login'));
    }

    public function testAForgedLoginPostNeverReachesDispatch(): void
    {
        $this->client->request('POST', '/login', ['login' => 'EMP-00412', 'pin' => '4321', '_csrf_token' => 'forged']);

        $this->assertResponseRedirects('/login');
        $this->assertSame([], FakeAdminApi::callsTo('POST', 'auth/login'));
    }

    public function testLoginSendsThePinToDispatchAndLandsOnTheTasks(): void
    {
        $this->logIn();
        $login = FakeAdminApi::callsTo('POST', 'auth/login')[0];

        $this->assertSame(['login' => 'EMP-00412', 'pin' => '4321'], array_intersect_key(json_decode($login['body'], true), ['login' => 1, 'pin' => 1]));
        $this->assertStringStartsWith('web-', $login['headers']['x-device-id']);
        $this->assertTrue($this->client->getRequest()->getSession()->get('_long_session'), 'drivers stay signed in like on Android');
    }

    public function testTheServiceWorkerIsServedUnderDriverForItsScopeEvenSignedOut(): void
    {
        $this->client->request('GET', '/driver/sw.js');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSignedOutDriverPagesGoToTheLoginPage(): void
    {
        foreach (['/driver/tasks', '/driver/route', '/driver/stops/P-1', '/driver/profile', '/driver/analytics'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseRedirects('/login', null, $url);
        }
    }

    public function testSignedOutApiCallsAnswerJson401SoQueuedTapsAreKept(): void
    {
        // A redirect would be followed by fetch() to the 200 login page and read as "sent": the offline
        // queue would then drop the driver's deliveries. The page needs the JSON answer it already handles.
        foreach ([['GET', '/driver/api/stops'], ['POST', '/driver/api/stops/D-1/delivered']] as [$method, $url]) {
            $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

            $this->assertResponseStatusCodeSame(401, $url);
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            $this->assertSame('SESSION_EXPIRED', $body['code'] ?? null, $url);
            $this->assertSame('/login', $body['redirect'] ?? null, $url);
        }
    }

    public function testADriverSessionIsNotABoilerplateUserSession(): void
    {
        $this->logIn();
        $this->client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/staff/login');
    }

    public function testLogoutRevokesAtDispatchAndEndsTheSession(): void
    {
        $this->logIn();
        FakeAdminApi::answer('POST', 'auth/logout', 204);
        $this->client->request('GET', '/driver/logout');

        $this->assertResponseRedirects('/login');
        $this->assertSame(['refresh_token' => 'ref-1'], json_decode(FakeAdminApi::callsTo('POST', 'auth/logout')[0]['body'], true));
        $this->client->request('GET', '/driver/tasks');
        $this->assertResponseRedirects('/login');
    }

    public function testWhenDispatchRefusesToRenewTheTokensTheDriverIsSentToLogIn(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 401, ['error' => 'Log in again.', 'code' => 'TOKEN_INVALID']);
        FakeAdminApi::answer('POST', 'auth/refresh', 401, ['error' => 'Log in again.', 'code' => 'REFRESH_INVALID']);
        $this->client->request('GET', '/driver/tasks');

        $this->assertResponseRedirects('/login');
        $this->client->request('GET', '/driver/tasks');
        $this->assertResponseRedirects('/login', null, 'the session is over');
    }

    // ---------------------------------------------------------------- FEATURE-194 shell / FEATURE-195 tasks + route

    public function testTheTaskListShowsTheStopsLikeTheAndroidCards(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => [['date' => '2026-10-14', 'label' => 'Today — Wed, Oct 14', 'stops' => [
            self::stopSummary(),
            self::stopSummary(['stop_key' => 'D-119', 'leg' => 'dropoff', 'urgent' => true, 'business' => null, 'contact' => 'Grace Tan', 'address' => '4488 Hazelbridge Way',
                'window' => ['start' => '13:00', 'end' => '13:30'], 'orders' => [['reference' => 'JOB-119', 'name' => 'Grace-1'], ['reference' => 'JOB-120', 'name' => 'Grace-2'], ['reference' => 'JOB-121', 'name' => 'Grace-3']]]),
        ]]]]);
        $crawler = $this->client->request('GET', '/driver/tasks');

        $this->assertResponseIsSuccessful();
        $this->assertSame('view=today', FakeAdminApi::callsTo('GET', 'stops')[0]['query']);
        $cards = $crawler->filter('.task-card');
        $this->assertCount(2, $cards);
        $this->assertSame('/driver/stops/P-118', $cards->eq(0)->attr('href'));
        $this->assertSame('Lab samples', $cards->eq(0)->filter('.task-card-order')->text());
        $this->assertStringContainsString('Acme Dental', $cards->eq(0)->text());
        $this->assertStringContainsString('1200 Main St, Vancouver · Lee Wong', $cards->eq(0)->filter('.task-card-addr')->text());
        $this->assertSame('By 11:00 AM', $cards->eq(0)->filter('.task-card-time')->text(), 'a pickup shows its deadline');
        $this->assertCount(1, $cards->eq(0)->filter('.kc-leg-pickup'));
        $this->assertSame('Grace-1 & Grace-2', $cards->eq(1)->filter('.task-card-order')->text(), 'two orders named, as on Android');
        $this->assertSame('1:30 PM', $cards->eq(1)->filter('.task-card-time')->text());
        $this->assertCount(1, $cards->eq(1)->filter('.urgency-flag'));
        $this->assertCount(2, $crawler->filter('.dgb-window'), 'a day bar under each card');

        // Shell: top bar, drawer, bottom tabs, Call Dispatch from /me.
        $this->assertCount(1, $crawler->filter('[data-online-toggle]'));
        $this->assertSame('false', $crawler->filter('[data-online-toggle]')->attr('aria-checked'), 'Offline');
        $this->assertCount(1, $crawler->filter('.bottom-nav a[href="tel:+16045550100"]'));
        $this->assertCount(1, $crawler->filter('.sidebar-drawer a[href="/driver/logout"]'));
        $this->assertCount(1, $crawler->filter('link[rel="manifest"][href="/driver-app/manifest.webmanifest"]'));
    }

    public function testAllViewHasDayHeadersAndEmptyViewsUseTheAndroidText(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => [['date' => '2026-10-15', 'label' => 'Thu, Oct 15', 'stops' => [self::stopSummary()]]]]);
        $crawler = $this->client->request('GET', '/driver/tasks?view=all');
        $this->assertSame('THU, OCT 15', $crawler->filter('.task-list-date-header')->text());

        FakeAdminApi::reset();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => []]);
        FakeAdminApi::answer('GET', 'messages', 200, ['unread' => 0, 'messages' => []]);
        $this->client->request('GET', '/driver/tasks?view=completed');
        $this->assertSelectorTextContains('.app-content', 'Nothing completed yet today.');
        $this->client->request('GET', '/driver/tasks');
        $this->assertSelectorTextContains('.app-content', 'No tasks right now. Pull down to refresh.');
    }

    public function testNoConnectionShowsTheAndroidMessage(): void
    {
        $this->logIn();
        FakeAdminApi::offline('GET', 'stops');
        $this->client->request('GET', '/driver/tasks');

        $this->assertResponseStatusCodeSame(503);
        $this->assertSelectorTextContains('[role="alert"]', 'No connection to Dispatch. Check your signal and try again.');
    }

    public function testTheRouteMapPlacesStopsWithCoordinatesAndListsTheRest(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'route', 200, [
            'driver_position' => ['latitude' => 49.25, 'longitude' => -123.1, 'at' => '2026-10-14T09:10:00-07:00'],
            'waypoints' => [
                ['seq' => 1, 'stop_key' => 'P-118', 'leg' => 'pickup', 'done' => true, 'urgent' => false, 'latitude' => 49.28, 'longitude' => -123.12, 'order_count' => 1, 'address' => '1200 Main St'],
                ['seq' => 2, 'stop_key' => 'D-118', 'leg' => 'dropoff', 'done' => false, 'urgent' => true, 'latitude' => 49.2, 'longitude' => -123.0, 'order_count' => 1, 'address' => '88 Oak Ave'],
                ['seq' => 3, 'stop_key' => 'D-119', 'leg' => 'dropoff', 'done' => false, 'urgent' => false, 'latitude' => null, 'longitude' => null, 'order_count' => 2, 'address' => '4488 Hazelbridge Way'],
            ],
        ]);
        $crawler = $this->client->request('GET', '/driver/route');

        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('.map-pin'));
        $this->assertCount(1, $crawler->filter('.map-pin.is-done'));
        $this->assertCount(1, $crawler->filter('.map-pin.is-urgent[href="/driver/stops/D-118"]'));
        $this->assertCount(1, $crawler->filter('.driver-marker'));
        $this->assertSame('', FakeAdminApi::callsTo('GET', 'route')[0]['query'], 'no date: Dispatch\'s today, not the UTC server\'s');
        $this->assertCount(1, $crawler->filter('.route-line'), 'from the driver to the one stop still ahead');
        $this->assertStringContainsString('3. 4488 Hazelbridge Way', $crawler->filter('.task-list')->text());
    }

    // ---------------------------------------------------------------- FEATURE-196…199 stop pages

    public function testAnAssignedPickupOffersPickingUpAndItsContacts(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/P-118', 200, self::stopDetail());
        $crawler = $this->client->request('GET', '/driver/stops/P-118');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', '1200 Main St, Vancouver');
        $this->assertSelectorTextContains('.kc-leg-tag', 'PICK');
        $this->assertSame('Mark Picking Up', $crawler->filter('[data-advance="picking_up"]')->text());
        $this->assertSame(['done', 'ahead', 'ahead'], $crawler->filter('.status-stepper .step')->each(static fn ($s) => str_contains((string) $s->attr('class'), 'done') ? 'done' : (str_contains((string) $s->attr('class'), 'current') ? 'current' : 'ahead')));
        $this->assertStringContainsString('7:30 AM', $crawler->filter('.step-time')->text());
        $this->assertStringContainsString('Ring twice', $crawler->filter('.info-row-start')->text());
        $this->assertCount(2, $crawler->filter('[data-modal="picker-call"] a[href="tel:+1 604 555 0199"]'), 'the pickup contact, and the same person as the order sender');
        $this->assertCount(1, $crawler->filter('a[href="/driver/stops/P-118/failed?ref=JOB-118"]'));
        $data = json_decode($crawler->filter('#stop-data')->text(), true);
        $this->assertSame('P-118', $data['stop_key']);
        $this->assertSame(2, $data['orders'][0]['package_count']);
    }

    public function testEveryStopViewHasAVisibleWayBackToTheTasks(): void
    {
        $this->logIn();
        $views = [
            'P-118' => ['status' => 'assigned'],
            'P-119' => ['stop_key' => 'P-119', 'status' => 'picking_up', 'next_allowed' => ['picked_up']],
            'P-120' => ['stop_key' => 'P-120', 'status' => 'picked_up', 'next_allowed' => []],
            'D-118' => ['stop_key' => 'D-118', 'leg' => 'dropoff', 'status' => 'in_transit', 'next_allowed' => ['delivered']],
            'D-119' => ['stop_key' => 'D-119', 'leg' => 'dropoff', 'status' => 'failed', 'next_allowed' => []],
        ];
        foreach ($views as $key => $overrides) {
            FakeAdminApi::answer('GET', 'stops/' . $key, 200, self::stopDetail($overrides));
            $crawler = $this->client->request('GET', '/driver/stops/' . $key);

            $this->assertResponseIsSuccessful();
            $this->assertCount(1, $crawler->filter('.driver-topbar a[data-back-to-tasks][href="/driver/tasks"]'), $key . ': top-bar back arrow');
            $button = $crawler->filter('.app-content a.btn[data-back-to-tasks][href="/driver/tasks"]');
            $this->assertCount(1, $button, $key . ': a labelled Back to tasks button in the page');
            $this->assertStringContainsString('Back to tasks', $button->text());
        }
    }

    public function testAMalformedEntryFromDispatchDoesNotBreakTheTaskList(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => ['oops', ['date' => '2026-10-14', 'label' => 'Today', 'stops' => 'none']]]);
        $this->client->request('GET', '/driver/tasks');

        $this->assertResponseIsSuccessful();
    }

    public function testAProofPhotoIsNotKeptInTheBrowserCache(): void
    {
        // A shared phone: the next driver must not find the previous driver's proof photos in the cache.
        $this->logIn();
        FakeAdminApi::answer('GET', 'photos/pho_0123456789abcdef', 200, 'image');
        $this->client->request('GET', '/driver/photos/pho_0123456789abcdef');

        $this->assertResponseIsSuccessful();
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testPickingUpShowsTheChecklist(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/P-118', 200, self::stopDetail(['status' => 'picking_up', 'next_allowed' => ['picked_up'], 'can_undo' => true]));
        $crawler = $this->client->request('GET', '/driver/stops/P-118');

        $this->assertCount(1, $crawler->filter('.pickup-checklist-row[data-reference="JOB-118"]'));
        $this->assertSame('2 items', $crawler->filter('.pickup-checklist-count')->text());
        $this->assertCount(1, $crawler->filter('[data-mark-picked-up][disabled]'), 'nothing ticked yet');
        $this->assertCount(1, $crawler->filter('[data-undo]'));
        $this->assertCount(1, $crawler->filter('.step.current'));
    }

    public function testInTransitDropoffShowsTheProofOfDeliveryForm(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/D-118', 200, self::stopDetail([
            'stop_key' => 'D-118', 'leg' => 'dropoff', 'status' => 'in_transit', 'business' => null, 'buzzer' => '204', 'next_allowed' => ['delivered'],
            'contacts' => [['role' => 'recipient', 'name' => 'Dr. Kim', 'phone' => '+1 604 555 0111'], ['role' => 'sender', 'name' => 'Lee Wong', 'phone' => null]],
        ]));
        $crawler = $this->client->request('GET', '/driver/stops/D-118');

        $this->assertSelectorTextContains('h1', 'Buzzer: 204');
        $this->assertCount(1, $crawler->filter('[data-proof-of-delivery] [data-open-camera]'));
        $this->assertCount(1, $crawler->filter('[data-proof-of-delivery] input[type="file"][capture="environment"]'), 'fallback when the browser has no camera access');
        $camera = $crawler->filter('[data-camera][hidden]');
        $this->assertCount(1, $camera, 'the in-app camera (task-camera.html) starts closed');
        $this->assertCount(1, $camera->filter('video[playsinline]'));
        $this->assertCount(1, $camera->filter('[data-camera-shutter]'));
        $this->assertStringContainsString('1200 Main St, Vancouver', $camera->filter('.camera-stamp')->text(), 'the stamp carries the stop address');
        $this->assertCount(3, $crawler->filter('[data-contact-toggle] button'));
        $this->assertCount(1, $crawler->filter('[data-mark-delivered][disabled]'));
        $this->assertCount(1, $crawler->filter('.info-card .action-row a.btn[href="tel:+1 604 555 0111"]'), 'drop-off Call dials the recipient');
        $this->assertSelectorTextContains('.btn-danger', 'Delivery failed / report a problem');
    }

    public function testAFinishedDropoffShowsItsProof(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/D-118', 200, self::stopDetail([
            'stop_key' => 'D-118', 'leg' => 'dropoff', 'status' => 'delivered', 'next_allowed' => [],
            'contacts' => [['role' => 'recipient', 'name' => 'Dr. Kim', 'phone' => null]],
            'proof' => ['outcome' => 'delivered', 'photo_count' => 2, 'left_at' => 'Front desk', 'customer_contact' => 'called', 'recipient_name' => 'Dr. Kim', 'failure_reason' => null, 'note' => null, 'completed_at' => '2026-10-14T10:05:00-07:00'],
        ]));
        $crawler = $this->client->request('GET', '/driver/stops/D-118');

        $this->assertSelectorTextContains('.badge-success', 'Done');
        $this->assertCount(2, $crawler->filter('.photo-thumb'));
        $this->assertStringContainsString('Front desk', $crawler->filter('[data-proof-of-delivery]')->text());
        $this->assertStringContainsString('Called', $crawler->filter('[data-proof-of-delivery]')->text());
        $this->assertStringContainsString('10:05 AM', $crawler->filter('.info-card')->eq(0)->text());
        $this->assertStringContainsString('Delivered — see other stops on the', $crawler->filter('.app-content')->text());
    }

    public function testAStopThatLeftTheRouteSaysSo(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/P-999', 404, ['error' => 'Not found — it may have been reassigned.', 'code' => 'STOP_NOT_FOUND']);
        $crawler = $this->client->request('GET', '/driver/stops/P-999');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('[role="alert"]', 'Not found — it may have been reassigned.');
        // Reloading only brings the same answer back, so the driver must have a way out.
        $this->assertCount(1, $crawler->filter('.driver-topbar a[data-back-to-tasks][href="/driver/tasks"]'), 'top-bar back arrow');
        $this->assertStringContainsString('Back to tasks', $crawler->filter('.app-content a.btn[data-back-to-tasks][href="/driver/tasks"]')->text());
    }

    public function testAnErrorOnTheTaskListItselfOffersNoBackButton(): void
    {
        $this->logIn();
        FakeAdminApi::offline('GET', 'stops');
        $crawler = $this->client->request('GET', '/driver/tasks');

        $this->assertCount(0, $crawler->filter('.app-content [data-back-to-tasks]'), 'it would only lead back here');
        $this->assertCount(1, $crawler->filter('[data-sidebar-open]'), 'the normal menu stays');
    }

    public function testReportAProblemListsTheSharedReasons(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops/D-118', 200, self::stopDetail(['stop_key' => 'D-118', 'leg' => 'dropoff', 'status' => 'in_transit', 'address' => '88 Oak Ave, Burnaby']));
        FakeAdminApi::answer('GET', 'failure-reasons', 200, [['code' => 'customer_not_present', 'label' => 'Customer Not Present', 'note_required' => false], ['code' => 'other', 'label' => 'Other', 'note_required' => true]]);
        $crawler = $this->client->request('GET', '/driver/stops/D-118/failed?ref=JOB-118');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Lab samples — 88 Oak Ave', $crawler->filter('h1 + p')->text());
        $this->assertSame(['', 'customer_not_present', 'other'], $crawler->filter('[data-failed-reason] option')->each(static fn ($o) => $o->attr('value')));
        $this->assertCount(1, $crawler->filter('[data-failed-step="restart"]'));
        $this->assertCount(1, $crawler->filter('[data-failed-step="return_to_pool"]'));
    }

    // ---------------------------------------------------------------- FEATURE-201 / 204 profile + analytics

    public function testProfileShowsTodaysHoursAndTheBreakButton(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'me', 200, ['id' => 7, 'name' => 'Marcus Chen', 'employee_card_number' => 'EMP-00412', 'vehicle_type' => 'Cargo Van', 'online' => true, 'dispatch_phone' => null]);
        FakeAdminApi::answer('GET', 'work/today', 200, ['started_at' => '08:02', 'worked_minutes' => 214, 'on_break' => false, 'breaks' => [['start' => '12:00', 'end' => '12:30']]]);
        $crawler = $this->client->request('GET', '/driver/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Marcus Chen');
        $this->assertStringContainsString('Cargo Van · EMP-00412', $crawler->filter('.app-content')->text());
        $this->assertStringContainsString('3h 34m', $crawler->filter('.stat-row')->text());
        $this->assertStringContainsString('8:02 AM', $crawler->filter('.stat-row')->text());
        $this->assertStringContainsString('12:00 PM – 12:30 PM (30 min)', $crawler->filter('.app-content')->text());
        $this->assertSelectorTextContains('.topbar-title', 'Profile');
        $this->assertCount(1, $crawler->filter('[data-online-toggle]'), 'one switch, in the page as the mockup');
        $this->assertCount(0, $crawler->filter('.driver-topbar [data-online-toggle]'));
        $break = $crawler->filter('[data-api-action]');
        $this->assertSame('{"event":"break_start"}', $break->attr('data-body'));
        $this->assertNull($break->attr('disabled'));
        $this->assertSame('true', $crawler->filter('[data-online-toggle]')->first()->attr('aria-checked'), 'Online: /me refreshed the stored driver');
    }

    public function testAnalyticsShowsTheWeek(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'analytics/summary', 200, ['today' => ['deliveries' => 6, 'hours' => 3.5], 'daily' => [
            ['date' => '2026-10-12', 'label' => 'Mon', 'stops' => 14, 'on_time_pct' => 93, 'hours' => 7.8, 'today' => false],
            ['date' => '2026-10-14', 'label' => 'Wed', 'stops' => 6, 'on_time_pct' => 100, 'hours' => 3.5, 'today' => true],
        ]]);
        $crawler = $this->client->request('GET', '/driver/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['6', '3.5h'], $crawler->filter('.stat-value')->each(static fn ($v) => $v->text()));
        $this->assertStringContainsString('Mon', $crawler->filter('.info-card')->text());
        $this->assertStringContainsString('14 stops · 93% on-time · 7.8h', $crawler->filter('.info-card')->text());
        $this->assertStringContainsString('Wed (Today)', $crawler->filter('.info-card')->text());
        $this->assertCount(1, $crawler->filter('.bottom-nav a.active[href="/driver/analytics"]'));
        $this->assertSelectorTextContains('.topbar-title', 'Analytics');
        $this->assertCount(1, $crawler->filter('[data-online-toggle][hidden]'), 'kept (hidden) so GPS pings go on while Online');
    }

    // ---------------------------------------------------------------- hidden GPS tester (test servers only)

    public function testTheGpsTesterIsNotInThePageUnlessTheServerTurnsItOn(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => []]);
        $crawler = $this->client->request('GET', '/driver/tasks');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-gps-tester-open]'), 'drivers on a real server cannot fake their location');
        $this->assertCount(0, $crawler->filter('[data-gps-tester-overlay]'));
        $this->assertCount(0, $crawler->filter('[data-gps-test-zone]'));

        $this->client->request('GET', '/driver/test/zones');
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame([], FakeAdminApi::callsTo('GET', 'test/zones'), 'Dispatch is not asked');
    }

    private function withGpsTester(callable $fn): void
    {
        self::ensureKernelShutdown();
        $_SERVER['DRIVER_GPS_TESTER'] = $_ENV['DRIVER_GPS_TESTER'] = '1';
        try {
            $this->client = static::createClient();
            $fn();
        } finally {
            unset($_SERVER['DRIVER_GPS_TESTER'], $_ENV['DRIVER_GPS_TESTER']);
        }
    }

    public function testWithTheFlagOnTheZoneDropdownGetsTheAdminsZones(): void
    {
        $this->withGpsTester(function (): void {
            $this->logIn();
            FakeAdminApi::answer('GET', 'test/zones', 200, ['zones' => [
                ['id' => 1, 'name' => 'Vancouver', 'latitude' => 49.25494, 'longitude' => -123.207],
                ['id' => 4, 'name' => 'North Surrey', 'latitude' => 49.1866, 'longitude' => -122.8],
            ]]);
            $this->client->request('GET', '/driver/test/zones');
        });

        $this->assertResponseIsSuccessful();
        $this->assertSame(['Vancouver', 'North Surrey'], array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['zones'], 'name'));
        $this->assertCount(1, FakeAdminApi::callsTo('GET', 'test/zones'), 'asked of Dispatch as the signed-in driver');
    }

    public function testWhenDispatchCannotListTheZonesTheDropdownGetsJson(): void
    {
        $this->withGpsTester(function (): void {
            $this->logIn();
            FakeAdminApi::answer('GET', 'test/zones', 500, ['error' => 'Boom']);
            $this->client->request('GET', '/driver/test/zones');
        });

        $this->assertResponseStatusCodeSame(502);
        $this->assertArrayHasKey('error', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testWithTheFlagOnTheGpsTesterSitsAfterConnectivity(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['DRIVER_GPS_TESTER'] = $_ENV['DRIVER_GPS_TESTER'] = '1';
        try {
            $this->client = static::createClient();
            $this->logIn();
            FakeAdminApi::answer('GET', 'stops', 200, ['days' => []]);
            $crawler = $this->client->request('GET', '/driver/tasks');
        } finally {
            unset($_SERVER['DRIVER_GPS_TESTER'], $_ENV['DRIVER_GPS_TESTER']);
        }

        $this->assertResponseIsSuccessful();
        $menu = $crawler->filter('.sidebar-drawer-nav > *')->each(static fn ($n) => trim($n->text()));
        $this->assertSame(['Profile', 'Analytics', 'Connectivity', 'GPS Test', 'Test location: zone Choose a zone…', '', 'Log Out'], $menu, 'right after Connectivity, then the zone dropdown');
        $this->assertCount(0, $crawler->filter('[data-gps-tester-open][hidden]'), 'shown straight away, no unlock');

        $panel = $crawler->filter('[data-gps-tester-overlay]');
        $this->assertSelectorTextContains('[data-gps-tester-overlay] h3', 'GPS Location Reporting');
        $this->assertStringContainsString('Last Location Reported', $panel->text());
        $this->assertSame(['49.261111,-123.113889', '49.1044,-122.8011'], $panel->filter('[data-gps-tester-place] option')->each(static fn ($o) => $o->attr('value')));
        $this->assertSame(['Vancouver', 'Surrey'], $panel->filter('[data-gps-tester-place] option')->each(static fn ($o) => explode(' ', $o->text())[0]));
        $this->assertSame(['1', '2', '5', '10', '25', '50', '100', '250', '500'], $panel->filter('[data-gps-tester-distance] option')->each(static fn ($o) => $o->attr('value')));
        $this->assertSame('100', $panel->filter('[data-gps-tester-distance] option[selected]')->attr('value'));
        $this->assertSame(['1', '1000'], $panel->filter('[data-gps-tester-unit] option')->each(static fn ($o) => $o->attr('value')), 'm or km');
        $this->assertSame(['north', 'south', 'east', 'west'], $panel->filter('[data-gps-tester-direction] option')->each(static fn ($o) => $o->attr('value')));
        $this->assertCount(1, $panel->filter('button[data-gps-tester-move]'));
        $this->assertCount(1, $panel->filter('[data-gps-tester-lat]'));
        $this->assertCount(1, $panel->filter('[data-gps-tester-lng]'));
        $this->assertCount(1, $panel->filter('[data-gps-tester-custom-go]'));
        $this->assertCount(1, $panel->filter('[data-gps-tester-real]'), 'a way back to the phone\'s real GPS');
    }

    // ---------------------------------------------------------------- /driver/api pass-through

    public function testATapIsPassedToDispatchOnceWithTheTapsOwnKey(): void
    {
        $this->logIn();
        $csrf = $this->csrf();
        FakeAdminApi::answer('POST', 'stops/P-118/picking-up', 200, ['status' => 'picking_up', 'can_undo' => true]);
        $this->client->request('POST', '/driver/api/stops/P-118/picking-up', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_IDEMPOTENCY_KEY' => 'tap-123',
        ], content: '{"client_time":"2026-10-14T09:00:00-07:00","latitude":49.2,"longitude":-123.1}');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['status' => 'picking_up', 'can_undo' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        $call = FakeAdminApi::callsTo('POST', 'stops/P-118/picking-up')[0];
        $this->assertSame('tap-123', $call['headers']['idempotency-key']);
        $this->assertSame('Bearer acc-1', $call['headers']['authorization']);
        $this->assertSame(['client_time' => '2026-10-14T09:00:00-07:00', 'latitude' => 49.2, 'longitude' => -123.1], json_decode($call['body'], true));
    }

    public function testWritesNeedThePagesCsrfToken(): void
    {
        $this->logIn();
        $this->client->request('POST', '/driver/api/work', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'nope'], content: '{"event":"break_start"}');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame([], FakeAdminApi::callsTo('POST', 'work'));
    }

    public function testOnlyTheAndroidAppsCallsArePassedThrough(): void
    {
        $this->logIn();
        $csrf = $this->csrf();
        foreach ([['GET', 'stops/../auth/refresh'], ['POST', 'auth/logout'], ['GET', 'admin/drivers'], ['DELETE', 'messages/1']] as [$method, $path]) {
            $this->client->request($method, '/driver/api/' . $path, server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            $this->assertResponseStatusCodeSame(404, $method . ' ' . $path);
        }
    }

    public function testFailuresComeBackAsJsonTheOfflineQueueCanReadOn(): void
    {
        $this->logIn();
        $csrf = $this->csrf();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf];

        FakeAdminApi::offline('POST', 'stops/D-118/in-transit');
        $this->client->request('POST', '/driver/api/stops/D-118/in-transit', server: $headers, content: '{}');
        $this->assertResponseStatusCodeSame(503);
        $this->assertSame(['error' => 'No connection to Dispatch. Check your signal and try again.', 'offline' => true], json_decode((string) $this->client->getResponse()->getContent(), true));

        FakeAdminApi::answer('POST', 'stops/D-118/delivered', 422, ['error' => 'Add at least one photo.', 'errors' => ['photos' => 'Required.'], 'code' => 'VALIDATION']);
        $this->client->request('POST', '/driver/api/stops/D-118/delivered', server: $headers, content: '{"photos":[]}');
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['error' => 'Add at least one photo.', 'errors' => ['photos' => 'Required.'], 'code' => 'VALIDATION'], json_decode((string) $this->client->getResponse()->getContent(), true));

        FakeAdminApi::only('GET', 'messages', 401, ['code' => 'TOKEN_INVALID']);
        FakeAdminApi::answer('POST', 'auth/refresh', 401, ['code' => 'REFRESH_INVALID']);
        $this->client->request('GET', '/driver/api/messages');
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(['error' => 'Your session expired. Please log in again.', 'code' => 'SESSION_EXPIRED', 'redirect' => '/login'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testReadsPassOnlyKnownQueryParameters(): void
    {
        $this->logIn();
        FakeAdminApi::answer('GET', 'stops', 200, ['days' => []]);
        $this->client->request('GET', '/driver/api/stops?view=all&driver_id=99');

        $this->assertResponseIsSuccessful();
        $this->assertSame('view=all', FakeAdminApi::callsTo('GET', 'stops')[0]['query']);
    }
}
