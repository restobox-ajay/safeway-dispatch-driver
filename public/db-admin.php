<?php

/**
 * phpLiteAdmin auth gateway (ADR-053).
 *
 * Runs directly under the web server, OUTSIDE Symfony's kernel. It does not read the Symfony session;
 * it authorises purely by looking its cookie token up in db_console_session — a short-lived, randomly
 * generated, per-admin credential minted by AdminDatabaseConsoleController.
 *
 * A request is served only if ALL of these hold:
 *   1. the console is armed (config `db_console.enabled_until` is in the future);
 *   2. the IP is not locked out for too many failed attempts;
 *   3. (if DB_CONSOLE_ALLOWED_IPS is set) the IP is on the allowlist;
 *   4. the cookie token hashes to a row in db_console_session;
 *   5. that row has not expired;
 *   6. the request comes from the IP the session was opened from;
 *   7. the owning admin still exists, is active, and still holds ROLE_TECH_SUPPORT.
 *
 * Check 1 is the kill-switch and is deliberately unconditional and first: disarming the console locks
 * out every live session on its next request, no matter how valid its token.
 *
 * Every load failing 3-7 counts as a failed login against the requesting IP (db_console_throttle),
 * mirroring the firewall's login_throttling (5 attempts / 2 minutes): once an IP trips the limit it is
 * refused outright even with an otherwise-valid token. A successful load clears the IP's counter.
 *
 * Fail closed: any problem at all — bad/absent/expired token, wrong IP, revoked account, missing
 * database, or any thrown error — redirects to /admin/login and never loads phpLiteAdmin. The whole
 * decision lives inline in this one file because it must run without the container; the tests drive
 * this file over real HTTP rather than a stand-in for it.
 */

declare(strict_types=1);

use App\Doctrine\SqlitePdoFactory;
use App\Security\ConsoleCookie;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

// Matches security.yaml login_throttling: 5 attempts per 2-minute window.
const DB_CONSOLE_MAX_FAILED_ATTEMPTS = 5;
const DB_CONSOLE_THROTTLE_WINDOW = 120;

/**
 * The env keys this gateway reads. Named explicitly so the seeding below stays targeted.
 *
 * @var list<string>
 */
const DB_CONSOLE_ENV_KEYS = ['APP_ENV', 'DATABASE_URL', 'TRUSTED_PROXIES', 'DB_CONSOLE_ALLOWED_IPS'];

/**
 * Read an env value the way the kernel would, AFTER the seeding below has run.
 */
$envValue = static function (string $key): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';

    return is_string($value) ? trim($value) : '';
};

$denyToLogin = static function (): never {
    if (!headers_sent()) {
        header('Location: /admin/login');
    }
    exit;
};

try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    // A REAL environment variable must beat the .env files, exactly as it does for the kernel. Dotenv
    // enforces that by refusing to override a key already present in $_ENV/$_SERVER — but it can only
    // see what is there, and the process environment does not always reach those superglobals:
    // PHP's default variables_order is "GPCS" (no "E"), and the built-in server puts only request
    // variables in $_SERVER. getenv() sees the real environment in every SAPI, so seed from it FIRST;
    // otherwise bootEnv fills $_ENV from .env and the actual environment silently loses.
    foreach (DB_CONSOLE_ENV_KEYS as $key) {
        if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            $real = getenv($key);
            if (is_string($real) && $real !== '') {
                $_SERVER[$key] = $real;
            }
        }
    }

    // Now load the .env chain for everything not already provided. The argument is the BASE path, not
    // a file: bootEnv derives the chain from it and prefers a compiled .env.local.php when present,
    // exactly as public/index.php and bin/console do.
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

    // Resolve the client IP with the SAME helper the app uses at mint time, reading the shared
    // trusted-proxy config so the two agree when running behind a proxy.
    $trustedProxies = $envValue('TRUSTED_PROXIES');
    if ($trustedProxies !== '') {
        Request::setTrustedProxies(
            array_filter(array_map('trim', explode(',', $trustedProxies))),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );
    }
    $currentRequest = Request::createFromGlobals();
    $currentIp = (string) $currentRequest->getClientIp();

    // Point phpLiteAdmin at the SAME SQLite file the app uses, resolved from DATABASE_URL rather than
    // hardcoded, so the gateway follows whatever path each environment declares instead of assuming
    // one convention and silently denying when a deployment differs.
    $dbPath = ConsoleCookie::sqlitePath(
        $envValue('DATABASE_URL'),
        dirname(__DIR__),
        $envValue('APP_ENV') ?: 'dev',
    );
    if ($dbPath === null || !is_file($dbPath)) {
        $denyToLogin();
    }

    // The project connection baseline (WAL, busy_timeout 5000, foreign keys on, …) applies here too (ADR-056/061,
    // issue #43): built by the same factory as the session handler's connection, from the same DATABASE_URL, so
    // it is the same file the path check above just found.
    $pdo = (new SqlitePdoFactory(dirname(__DIR__), $envValue('APP_ENV') ?: 'dev'))->create($envValue('DATABASE_URL'));
    $now = time();

    // 1. Kill-switch, above everything else. A missing row reads as 0 => off.
    $sw = $pdo->prepare('SELECT config_value FROM config WHERE config_key = :k LIMIT 1');
    $sw->execute(['k' => ConsoleCookie::ENABLED_UNTIL_KEY]);
    if ((int) $sw->fetchColumn() <= $now) {
        $denyToLogin();
    }

    // 2. Rate limit, keyed by IP, checked before any further work.
    $throttle = $pdo->prepare('SELECT window_start, attempts FROM db_console_throttle WHERE ip_address = :ip LIMIT 1');
    $throttle->execute(['ip' => $currentIp]);
    $throttleRow = $throttle->fetch(PDO::FETCH_ASSOC);
    if (
        $throttleRow !== false
        && ($now - (int) $throttleRow['window_start']) < DB_CONSOLE_THROTTLE_WINDOW
        && (int) $throttleRow['attempts'] >= DB_CONSOLE_MAX_FAILED_ATTEMPTS
    ) {
        $denyToLogin();
    }

    // Records this load as a failed login against the IP (fixed window), then denies.
    $denyFailedLogin = static function () use ($pdo, $currentIp, $now, $throttleRow, $denyToLogin): never {
        if ($throttleRow === false || ($now - (int) $throttleRow['window_start']) >= DB_CONSOLE_THROTTLE_WINDOW) {
            $pdo->prepare(
                'INSERT OR REPLACE INTO db_console_throttle (ip_address, window_start, attempts) VALUES (:ip, :ws, 1)'
            )->execute(['ip' => $currentIp, 'ws' => $now]);
        } else {
            $pdo->prepare('UPDATE db_console_throttle SET attempts = attempts + 1 WHERE ip_address = :ip')
                ->execute(['ip' => $currentIp]);
        }
        $denyToLogin();
    };

    // 3. Master override: when DB_CONSOLE_ALLOWED_IPS is set, ONLY those IPs/CIDRs may reach the
    //    console at all — a hard gate above every other check. Empty => no restriction.
    $allowIps = $envValue('DB_CONSOLE_ALLOWED_IPS');
    if ($allowIps !== '' && !IpUtils::checkIp($currentIp, array_filter(array_map('trim', explode(',', $allowIps))))) {
        $denyFailedLogin();
    }

    // 4. The credential: an opaque random token. Hash it and look the session up.
    $token = $_COOKIE[ConsoleCookie::COOKIE_NAME] ?? '';
    if (!is_string($token) || $token === '') {
        $denyFailedLogin();
    }

    $stmt = $pdo->prepare(
        'SELECT id, admin_id, expires_at, ip_address FROM db_console_session WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => ConsoleCookie::hashToken($token)]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session === false) {
        $denyFailedLogin();
    }

    // 5. Expired: drop the row and count it as a failed login.
    if (strtotime((string) $session['expires_at']) <= $now) {
        $pdo->prepare('DELETE FROM db_console_session WHERE id = :id')->execute(['id' => $session['id']]);
        $denyFailedLogin();
    }

    // 6. Bound to the IP that opened it, so a stolen token cannot travel.
    if ((string) $session['ip_address'] !== $currentIp) {
        $denyFailedLogin();
    }

    // 7. The account must still exist, be active, and still hold ROLE_TECH_SUPPORT. The gateway runs
    //    outside the kernel, so this is the only place a since-revoked admin is caught.
    $acct = $pdo->prepare('SELECT status, roles FROM admin WHERE id = :id LIMIT 1');
    $acct->execute(['id' => $session['admin_id']]);
    $account = $acct->fetch(PDO::FETCH_ASSOC);
    $roles = $account !== false ? json_decode((string) $account['roles'], true) : null;
    if (
        $account === false
        || (string) $account['status'] !== 'active'
        || !is_array($roles)
        || !in_array('ROLE_TECH_SUPPORT', $roles, true)
    ) {
        $denyFailedLogin();
    }

    // Authorised. Clear the IP's failed-login counter, slide the expiry forward on activity (so the
    // console auto-closes after an idle window), and tidy up any lapsed sessions.
    $pdo->prepare('DELETE FROM db_console_throttle WHERE ip_address = :ip')->execute(['ip' => $currentIp]);

    $windowStmt = $pdo->prepare('SELECT config_value FROM config WHERE config_key = :k LIMIT 1');
    $windowStmt->execute(['k' => ConsoleCookie::WINDOW_MINUTES_KEY]);
    $window = ConsoleCookie::windowSeconds($windowStmt->fetchColumn() ?: null);

    $pdo->prepare('UPDATE db_console_session SET expires_at = :exp WHERE id = :id')
        ->execute(['exp' => date('Y-m-d H:i:s', $now + $window), 'id' => $session['id']]);

    $pdo->prepare('DELETE FROM db_console_session WHERE expires_at <= :now')
        ->execute(['now' => date('Y-m-d H:i:s', $now)]);

    $pdo = null;
} catch (\Throwable) {
    $denyToLogin();
}

// phpLiteAdmin starts a native PHP session. Under PHP's default name (PHPSESSID, path /) it would share the panel's
// session cookie: adopting the admin's live Symfony session id into a second store, or — with
// session.use_strict_mode — replacing that cookie and logging the admin out of the panel (issue #47). Give it its
// own cookie, scoped to the gateway, never sent cross-site.
session_name('pla_console');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/db-admin.php',
    'secure'   => $currentRequest->isSecure(),
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Authorised — hand off to phpLiteAdmin, which is kept OUTSIDE the docroot so it can never be
// requested directly, whatever its own in-file guard does.
define('APP_DB_PATH', $dbPath);
$password = '';
define('PHPLITEADMIN_AUTHORIZED', true);
require_once dirname(__DIR__) . '/tools/phpliteadmin.php';
