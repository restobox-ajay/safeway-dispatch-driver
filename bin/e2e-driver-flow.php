<?php
/**
 * End-to-end check of the whole FVST Express flow against a running fvstexpress-admin (local / test only):
 *
 *   Admin (web endpoints, as a dispatcher):  sign in → create an order → assign both legs to the driver on Dispatch
 *   Driver app API (/driver-api/v1):         log in → online → stops → Picking Up → Picked Up → In Transit
 *                                            → upload proof photo → Delivered; a second order is Failed (return to pool)
 *   Admin again:                             the orders show Delivered / Failed
 *
 * fvstexpress-admin has no REST API for orders or Dispatch, so those two steps post the same forms / JSON the
 * admin pages post. Everything the driver does goes through the driver API, exactly like the driver app.
 *
 * Usage (Windows / WAMP, from the fvstexpress-driver folder):
 *   php bin/e2e-driver-flow.php --admin-url=http://fvstexpress-admin.local --admin-email=you@example.com --driver=emp-driver-1
 *   Passwords/PIN are asked for, or taken from E2E_ADMIN_PASSWORD / E2E_DRIVER_PIN.
 *   --no-fail   skip the second (failed delivery) order.
 *
 * It creates real orders in the admin database (named "E2E …"). Never point it at production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("Run from the command line.\n"); }
if (!extension_loaded('curl')) { exit("The PHP curl extension is required (enable it in php.ini).\n"); }

$opt = getopt('', ['admin-url::', 'admin-email::', 'driver::', 'no-fail', 'insecure']);
$adminUrl = rtrim((string) ($opt['admin-url'] ?? getenv('E2E_ADMIN_URL') ?: 'http://fvstexpress-admin.local'), '/');
$adminEmail = (string) ($opt['admin-email'] ?? getenv('E2E_ADMIN_EMAIL') ?: ask('Admin email: '));
$adminPassword = (string) (getenv('E2E_ADMIN_PASSWORD') ?: ask('Admin password: ', true));
$driverLogin = (string) ($opt['driver'] ?? getenv('E2E_DRIVER_LOGIN') ?: 'emp-driver-1');
$driverPin = (string) (getenv('E2E_DRIVER_PIN') ?: ask("PIN for {$driverLogin}: ", true));
$withFailure = !isset($opt['no-fail']);
$api = $adminUrl . '/driver-api/v1/';
$jar = tempnam(sys_get_temp_dir(), 'e2e');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$run = date('His');
$failures = 0;

// ------------------------------------------------------------------ helpers
function ask(string $q, bool $secret = false): string
{
    if ($secret && DIRECTORY_SEPARATOR === '/') { system('stty -echo'); }
    echo $q;
    $v = trim((string) fgets(STDIN));
    if ($secret && DIRECTORY_SEPARATOR === '/') { system('stty echo'); echo "\n"; }
    return $v;
}
function step(string $name): void { echo "\n\033[1m== {$name}\033[0m\n"; }
function ok(string $m): void { echo "  \033[32mOK\033[0m  {$m}\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31mFAIL\033[0m {$m}\n"; }
function stop(string $m): never { global $jar; @unlink($jar); echo "\n\033[31mSTOPPED:\033[0m {$m}\n"; exit(1); }
function uuid(): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }

/** @return array{status:int, headers:array<string,string>, body:string} */
function http(string $method, string $url, array $o = []): array
{
    global $jar, $opt;
    $h = curl_init($url);
    $headers = $o['headers'] ?? [];
    curl_setopt_array($h, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if (isset($opt['insecure'])) { curl_setopt($h, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($h, CURLOPT_SSL_VERIFYHOST, 0); }
    if (array_key_exists('json', $o)) { $headers[] = 'Content-Type: application/json'; curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($o['json'])); }
    if (isset($o['form'])) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; curl_setopt($h, CURLOPT_POSTFIELDS, $o['form']); }
    if (isset($o['multipart'])) { curl_setopt($h, CURLOPT_POSTFIELDS, $o['multipart']); }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($h);
    if ($raw === false) { stop("No answer from {$url}: " . curl_error($h)); }
    $size = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $hdr = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
    }
    return ['status' => $status, 'headers' => $hdr, 'body' => substr($raw, $size)];
}

/** Admin web request; follows redirects (GET), returns the last response and the final URL. */
function admin(string $method, string $path, array $o = []): array
{
    global $adminUrl;
    $url = str_starts_with($path, 'http') ? $path : $adminUrl . $path;
    for ($i = 0; $i < 6; $i++) {
        $r = http($method, $url, $o);
        if ($r['status'] < 300 || $r['status'] >= 400 || !isset($r['headers']['location'])) { $r['url'] = $url; return $r; }
        $loc = $r['headers']['location'];
        $url = str_starts_with($loc, 'http') ? $loc : $adminUrl . $loc;
        $method = 'GET'; $o = [];
    }
    stop("Too many redirects at {$path}");
}

/** Driver API call as the signed-in driver. */
function dapi(string $method, string $path, ?array $json = null, array $extra = []): array
{
    global $api, $token;
    $headers = ['Accept: application/json', 'X-App-Version: e2e-1.0', 'X-Device-Id: e2e-script'];
    if ($token) { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($method !== 'GET') { $headers[] = 'Idempotency-Key: ' . uuid(); }
    $o = ['headers' => $headers] + $extra;
    if ($json !== null) { $o['json'] = $json; }
    $r = http($method, $api . $path, $o);
    $r['data'] = json_decode($r['body'], true);
    return $r;
}
function expect(array $r, int $status, string $what): ?array
{
    // Any 2xx counts as success for a 2xx expectation (endpoints answer 200, 201 or 204).
    if ($r['status'] === $status || ($status < 300 && $r['status'] >= 200 && $r['status'] < 300)) { ok("{$what} ({$r['status']})"); return is_array($r['data'] ?? null) ? $r['data'] : []; }
    $msg = is_array($r['data'] ?? null) ? json_encode($r['data']) : substr(trim(strip_tags($r['body'])), 0, 300);
    bad("{$what}: expected {$status}, got {$r['status']} — {$msg}");
    return null;
}

/** Every field of the first form whose action contains $action, as the browser would submit it. */
function formFields(string $html, string $action): array
{
    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    foreach ($doc->getElementsByTagName('form') as $form) {
        if (!str_contains($form->getAttribute('action'), $action)) { continue; }
        $x = new DOMXPath($doc);
        $f = [];
        foreach ($x->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) { continue; }
            $tag = strtolower($el->nodeName);
            $type = strtolower($el->getAttribute('type'));
            if ($tag === 'input' && in_array($type, ['checkbox', 'radio'], true)) { if ($el->hasAttribute('checked')) { $f[] = [$name, $el->getAttribute('value') ?: 'on']; } continue; }
            if ($tag === 'input' && in_array($type, ['submit', 'button', 'file', 'image'], true)) { continue; }
            if ($tag === 'select') {
                $val = null;
                foreach ($x->query('.//option', $el) as $op) { if ($val === null || $op->hasAttribute('selected')) { $val = $op->hasAttribute('value') ? $op->getAttribute('value') : $op->textContent; if ($op->hasAttribute('selected')) { break; } } }
                $f[] = [$name, (string) $val]; continue;
            }
            $f[] = [$name, $tag === 'textarea' ? $el->textContent : $el->getAttribute('value')];
        }
        return $f;
    }
    return [];
}
function setField(array &$f, string $name, string $value): void
{
    foreach ($f as &$p) { if ($p[0] === $name) { $p[1] = $value; return; } }
    $f[] = [$name, $value];
}
function encode(array $f): string { return implode('&', array_map(static fn ($p) => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $f)); }

// ------------------------------------------------------------------ 1. admin sign-in
step("Admin sign-in at {$adminUrl}");
$r = admin('GET', '/admin/login');
if (!preg_match('/name="_csrf_token" value="([^"]+)"/', $r['body'], $m)) { stop("No admin login form at {$adminUrl}/admin/login (HTTP {$r['status']})."); }
$r = admin('POST', '/admin/login', ['form' => http_build_query(['email' => $adminEmail, 'password' => $adminPassword, '_csrf_token' => html_entity_decode($m[1])])]);
if (str_contains($r['url'], '/admin/login')) { stop('Admin sign-in refused (wrong email/password?).'); }
if (str_contains($r['url'], '2fa')) { stop('This admin has two-factor auth on; use an admin without 2FA for the script.'); }
ok('signed in → ' . parse_url($r['url'], PHP_URL_PATH));

// ------------------------------------------------------------------ 2. driver login (to know the driver id) + online
step("Driver API login as {$driverLogin}");
$token = null;
$login = dapi('POST', 'auth/login', ['login' => $driverLogin, 'pin' => $driverPin]);
$d = expect($login, 200, 'POST auth/login');
if (!$d || empty($d['access_token'])) { stop('Driver login failed — check the login and PIN.'); }
$token = $d['access_token'];
$refresh = $d['refresh_token'] ?? '';
$driverId = (int) ($d['driver']['id'] ?? 0);
ok("driver #{$driverId} {$d['driver']['name']}");
expect(dapi('GET', 'ping'), 200, 'GET ping');
expect(dapi('GET', 'me'), 200, 'GET me');
expect(dapi('POST', 'presence', ['online' => true, 'latitude' => 49.2827, 'longitude' => -123.1207]), 200, 'POST presence (online)');

// ------------------------------------------------------------------ 3. create orders (admin New Order form)
$orders = [];
foreach ($withFailure ? ['deliver', 'fail'] : ['deliver'] as $i => $kind) {
    step("Admin: create order ({$kind})");
    $r = admin('GET', '/admin/orders/new');
    $f = formFields($r['body'], '/admin/orders/new');
    if ($f === []) { stop('No New Order form at /admin/orders/new (is this admin allowed to create orders?).'); }
    foreach ([
        'sender_first' => "E2E Sender {$run}", 'sender_phone' => '6045550100',
        'recipient_first' => "E2E {$kind} {$run}", 'recipient_last' => 'Test', 'recipient_phone' => '6045550111',
        'delivery_street' => $i === 0 ? '4488 Hazelbridge Way' : '1200 Main St', 'delivery_city' => $i === 0 ? 'Richmond' : 'Vancouver',
        'delivery_province' => 'BC', 'delivery_postal' => $i === 0 ? 'V6X 3R8' : 'V6A 4B6', 'delivery_buzzer' => '204',
        'pickup_date' => $today, 'delivery_date' => $today, 'pickup_start' => '0000', 'pickup_end' => '2359',
        'delivery_start' => '0000', 'delivery_end' => '2359', 'no_response' => 'E2E test order — safe to delete',
    ] as $k => $v) { setField($f, $k, $v); }
    $r = admin('POST', '/admin/orders/new', ['form' => encode($f)]);
    if (!preg_match('#/admin/orders/(\d+)#', $r['url'], $m)) {
        $errs = [];
        if (preg_match_all('#class="[^"]*(?:form-error|field-error|invalid-feedback|error)[^"]*"[^>]*>([^<]+)<#', $r['body'], $mm)) { $errs = array_unique(array_map('trim', $mm[1])); }
        stop('Order not created. ' . ($errs ? implode(' | ', $errs) : 'HTTP ' . $r['status']));
    }
    $orders[$kind] = (int) $m[1];
    ok("JOB-{$m[1]} created");
}

// ------------------------------------------------------------------ 4. Dispatch: both legs to the driver
step('Admin: Dispatch — assign to the driver');
$r = admin('GET', '/admin/dispatch?date=' . $today);
if (!preg_match('/data-dispatch-token="([^"]+)"/', $r['body'], $m)) { stop('No Dispatch board token on /admin/dispatch.'); }
$cards = [];
foreach ($orders as $id) { $cards[] = ['ids' => ["JOB-{$id}"], 'leg' => 'P']; $cards[] = ['ids' => ["JOB-{$id}"], 'leg' => 'D']; }
$r = http('POST', $adminUrl . '/admin/dispatch/save', ['headers' => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
    'json' => ['_token' => html_entity_decode($m[1]), 'date' => $today, 'columns' => [['driver' => $driverId, 'cards' => $cards]]]]);
$r['data'] = json_decode($r['body'], true);
if (!expect($r, 200, 'POST /admin/dispatch/save')) {
    stop('Dispatch refused. If it says the driver isn\'t accepting assignments: give the driver a shift today (Admin › Driver Shifts) — they are online now.');
}
foreach (($r['data']['changes'] ?? []) as $c) { echo "      {$c}\n"; }

// ------------------------------------------------------------------ 5. driver: the delivered order
$id = $orders['deliver'];
step("Driver: JOB-{$id} pickup → delivery");
$list = expect(dapi('GET', 'stops?view=today'), 200, 'GET stops?view=today');
$keys = [];
foreach (($list['days'] ?? []) as $day) { foreach (($day['stops'] ?? []) as $s) { $keys[] = $s['stop_key']; } }
in_array("P-{$id}", $keys, true) ? ok("P-{$id} is on today's list") : bad("P-{$id} missing from today's list (" . implode(', ', $keys) . ')');
expect(dapi('GET', 'route?date=' . $today), 200, 'GET route');
$stop = expect(dapi('GET', "stops/P-{$id}"), 200, "GET stops/P-{$id}");
expect(dapi('GET', "orders/JOB-{$id}"), 200, "GET orders/JOB-{$id}");
$pickupRefs = array_map(static fn ($o) => $o['reference'], $stop['orders'] ?? []);
if (!in_array("JOB-{$id}", $pickupRefs, true)) { $pickupRefs[] = "JOB-{$id}"; }
expect(dapi('POST', "stops/P-{$id}/picking-up", ['client_time' => date(DATE_ATOM)]), 200, 'POST picking-up');
$others = array_values(array_diff($pickupRefs, ["JOB-{$id}"]));
$body = ['picked_up' => ["JOB-{$id}"], 'left_behind' => array_map(static fn ($r) => ['reference' => $r, 'action' => 'restart'], $others), 'client_time' => date(DATE_ATOM)];
if ($others) { $body['note'] = 'E2E: only the test order'; }
$pu = expect(dapi('POST', "stops/P-{$id}/picked-up", $body), 200, 'POST picked-up');
expect(dapi('GET', "stops/D-{$id}"), 200, "GET stops/D-{$id}");
expect(dapi('POST', "stops/D-{$id}/in-transit", ['client_time' => date(DATE_ATOM)]), 200, 'POST in-transit');

$jpeg = tempnam(sys_get_temp_dir(), 'pod') . '.jpg';
file_put_contents($jpeg, base64_decode('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'));
$ph = dapi('POST', 'photos', null, ['multipart' => ['file' => new CURLFile($jpeg, 'image/jpeg', 'proof.jpg'), 'reference' => "JOB-{$id}",
    'client_photo_id' => 'e2e-' . uuid(), 'taken_at' => date(DATE_ATOM), 'latitude' => '49.18', 'longitude' => '-123.13']]);
$photo = expect($ph, 201, 'POST photos (proof)');
@unlink($jpeg);
$refused = dapi('POST', "stops/D-{$id}/delivered", ['photos' => [], 'left_at' => 'Front desk', 'customer_contact' => 'called']);
$refused['status'] === 422 ? ok('delivered without a photo is refused (422)') : bad("delivered without a photo: expected 422, got {$refused['status']}");
$del = expect(dapi('POST', "stops/D-{$id}/delivered", ['photos' => [$photo['photo_id'] ?? ''], 'left_at' => 'Front desk', 'customer_contact' => 'called',
    'recipient_name' => 'E2E Recipient', 'latitude' => 49.18, 'longitude' => -123.13, 'client_time' => date(DATE_ATOM)]), 200, 'POST delivered');
$after = expect(dapi('GET', "stops/D-{$id}"), 200, 'GET finished stop');
($after['status'] ?? '') === 'delivered' ? ok('stop shows delivered, proof: ' . json_encode($after['proof'] ?? null)) : bad('stop status is ' . ($after['status'] ?? '?'));

// ------------------------------------------------------------------ 6. driver: the failed order
if ($withFailure) {
    $id2 = $orders['fail'];
    step("Driver: JOB-{$id2} pickup → failed delivery");
    expect(dapi('POST', "stops/P-{$id2}/picked-up", ['picked_up' => ["JOB-{$id2}"], 'left_behind' => [], 'client_time' => date(DATE_ATOM)]), 200, 'POST picked-up');
    $reasons = expect(dapi('GET', 'failure-reasons'), 200, 'GET failure-reasons');
    expect(dapi('POST', "stops/D-{$id2}/in-transit", []), 200, 'POST in-transit');
    expect(dapi('POST', "stops/D-{$id2}/failed", ['reference' => "JOB-{$id2}", 'reason' => $reasons[0]['code'] ?? 'customer_not_present',
        'note' => 'E2E: nobody answered', 'next_step' => 'return_to_pool', 'client_time' => date(DATE_ATOM)]), 200, 'POST failed (return to pool)');
}

// ------------------------------------------------------------------ 7. driver: the rest of the API
step('Driver: other screens');
expect(dapi('GET', 'stops?view=completed'), 200, 'GET stops?view=completed');
expect(dapi('GET', 'stops?view=all'), 200, 'GET stops?view=all');
$work = expect(dapi('GET', 'work/today'), 200, 'GET work/today');
if ($work) { echo '      worked ' . ($work['worked_minutes'] ?? 0) . ' min, started ' . ($work['started_at'] ?? '—') . "\n"; }
expect(dapi('POST', 'work', ['event' => 'break_start', 'at' => date(DATE_ATOM)]), 200, 'POST work break_start');
expect(dapi('POST', 'work', ['event' => 'break_end', 'at' => date(DATE_ATOM)]), 200, 'POST work break_end');
expect(dapi('GET', 'analytics/summary?range=week'), 200, 'GET analytics/summary');
$msgs = expect(dapi('GET', 'messages'), 200, 'GET messages');
if (!empty($msgs['messages'][0]['id'])) {
    $mid = $msgs['messages'][0]['id'];
    expect(dapi('POST', "messages/{$mid}/read"), 200, "POST messages/{$mid}/read");
}
expect(dapi('POST', 'presence/history', ['pings' => [['latitude' => 49.2, 'longitude' => -123.1, 'recorded_at' => date(DATE_ATOM)]]]), 200, 'POST presence/history');
expect(dapi('POST', 'app-issues', ['type' => 'other', 'detail' => 'E2E script test report — safe to ignore',
    'device' => ['model' => 'e2e-script', 'os' => PHP_OS_FAMILY, 'app_version' => 'e2e-1.0'], 'net_status' => ['online' => true, 'server_reachable' => true]]), 201, 'POST app-issues');
expect(dapi('POST', 'exceptions', ['order_ref' => 'JOB-' . $orders['deliver'], 'type' => 'other', 'note' => 'E2E: report a problem — safe to ignore']), 201, 'POST exceptions (report a problem)');
$ref = dapi('POST', 'auth/refresh', ['refresh_token' => $refresh]);
if ($d2 = expect($ref, 200, 'POST auth/refresh')) { $token = $d2['access_token']; $refresh = $d2['refresh_token']; }

// ------------------------------------------------------------------ 8. admin sees the result
step('Admin: order pages');
foreach ($orders as $kind => $oid) {
    $r = admin('GET', "/admin/orders/{$oid}");
    $text = preg_replace('/\s+/', ' ', strip_tags($r['body']));
    $want = $kind === 'deliver' ? 'Delivered' : 'Failed';
    $r['status'] === 200 && stripos($text, $want) !== false ? ok("JOB-{$oid} shows {$want}") : bad("JOB-{$oid} page doesn't show {$want} (HTTP {$r['status']})");
}

step('Driver: offline + logout');
expect(dapi('POST', 'presence', ['online' => false]), 200, 'POST presence (offline)');
expect(dapi('POST', 'auth/logout', ['refresh_token' => $refresh]), 204, 'POST auth/logout');
$token = 'revoked-' . $token;
$gone = dapi('GET', 'me');
$gone['status'] === 401 ? ok('old token refused after logout (401)') : bad("after logout GET me returned {$gone['status']}");

@unlink($jar);
echo "\n" . ($failures === 0 ? "\033[32mALL CHECKS PASSED\033[0m" : "\033[31m{$failures} CHECK(S) FAILED\033[0m") . ' — orders: ' . implode(', ', array_map(static fn ($i) => "JOB-{$i}", $orders)) . "\n";
exit($failures === 0 ? 0 : 1);
