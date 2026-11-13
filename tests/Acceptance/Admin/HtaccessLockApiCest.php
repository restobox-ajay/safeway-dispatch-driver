<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Admin;

use App\Tests\Support\AcceptanceTester;

/**
 * The Htaccess Lock API over real HTTP against the live server (ADR-062): tech support only, one gate.
 *
 * What PHPUnit's contract test proves per operation, this proves end to end: a real bearer token, the real
 * firewall and role checks, the real .htaccess file on disk (acceptance uses var/acceptance-htaccess/, never
 * public/.htaccess — config/services_acceptance.yaml) and the real audit log. The live server is PHP's built-in
 * server, which ignores .htaccess, so nothing is ever enforced here; real Apache enforcement is what the
 * lock's self-test (and the ADR-059 hand verification) is for.
 */
class HtaccessLockApiCest
{
    private const PASSWORD = 'password123';
    private const SECRET = 'JBSWY3DPEHPK3PXP';
    private const ORIGINAL = "RewriteEngine On\n";

    private function dir(): string
    {
        return \dirname(__DIR__, 3) . '/var/acceptance-htaccess';
    }

    private function htaccess(): string
    {
        return (string) @file_get_contents($this->dir() . '/.htaccess');
    }

    private function mustContain(string $haystack, string $needle): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException(sprintf("Expected .htaccess to contain \"%s\"; it is:\n%s", $needle, $haystack));
        }
    }

    private function mustNotContain(string $haystack, string $needle): void
    {
        if (str_contains($haystack, $needle)) {
            throw new \RuntimeException(sprintf("Expected .htaccess NOT to contain \"%s\"; it is:\n%s", $needle, $haystack));
        }
    }

    private function wipe(): void
    {
        foreach (glob($this->dir() . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }
        @rmdir($this->dir());
        @unlink(\dirname(__DIR__, 3) . '/var/acceptance-htaccess.lock');
    }

    public function _before(AcceptanceTester $I): void
    {
        $this->wipe();
        mkdir($this->dir(), 0775, true);
        file_put_contents($this->dir() . '/.htaccess', self::ORIGINAL);
    }

    public function _after(AcceptanceTester $I): void
    {
        $this->wipe();
    }

    private function techSupportToken(AcceptanceTester $I): string
    {
        return $I->createAdminAccessToken($I->createTechSupportAdminWith2fa('ts-api@example.com', self::PASSWORD, self::SECRET));
    }

    public function techSupportManagesTheWhitelistAndTheLockEndToEnd(AcceptanceTester $I): void
    {
        $token = $this->techSupportToken($I);

        // list (empty) -> add x2 -> list
        $I->sendApiRequest('GET', '/admin-api/htaccess-lock/ips', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('{"data":[]}');

        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1'], $token);
        $I->seeApiResponseCodeIs(201);
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.0/24'], $token);
        $I->seeApiResponseCodeIs(201);
        $I->seeApiResponseContains('"127.0.0.1","203.0.113.0\/24"');
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.0/24'], $token);
        $I->seeApiResponseCodeIs(409);

        // enable -> the real file now holds the managed block with both entries, the rest untouched
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/enable', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"enabled":true');
        $I->seeApiResponseContains('"in_sync":true');
        $file = $this->htaccess();
        $this->mustContain($file, 'Require ip 203.0.113.0/24');
        $this->mustContain($file, 'Require ip 127.0.0.1');
        $this->mustContain($file, self::ORIGINAL);

        // remove one -> gone from the running file; the one covering the caller cannot go while the lock is on
        $I->sendApiRequest('DELETE', '/admin-api/htaccess-lock/ips?ip=127.0.0.1', null, $token);
        $I->seeApiResponseCodeIs(422);
        $I->seeApiResponseContains('lock you out');
        $I->sendApiRequest('DELETE', '/admin-api/htaccess-lock/ips?ip=203.0.113.0/24', null, $token);
        $I->seeApiResponseCodeIs(200);
        $this->mustNotContain($this->htaccess(), '203.0.113.0/24');

        // exempt paths
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/health'], $token);
        $I->seeApiResponseCodeIs(201);
        $this->mustContain($this->htaccess(), 'Request_URI');

        // disable -> block removed, original file byte-for-byte back, whitelist kept
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/disable', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('"enabled":false');
        $I->seeApiResponseContains('"ips":["127.0.0.1"]');
        if ($this->htaccess() !== self::ORIGINAL) {
            throw new \RuntimeException('disabling must restore .htaccess exactly; got: ' . $this->htaccess());
        }
    }

    public function everyChangeIsAuditedAndTheAdminPageShowsWhatTheApiDid(AcceptanceTester $I): void
    {
        $token = $this->techSupportToken($I);

        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.77'], $token);
        $I->seeApiResponseCodeIs(201);

        // audit trail (via the audit-log API) — written by the gate, once
        $I->sendApiRequest('GET', '/admin-api/audit-log?per_page=100', null, $token);
        $I->seeApiResponseCodeIs(200);
        $I->seeApiResponseContains('admin.htaccess_lock_ip_add');
        $I->seeApiResponseContains('ts-api@example.com');

        // and the web page (same gate, same state) lists the IP the API added
        $I->amOnPage('/admin/login');
        $I->submitForm('form', ['email' => 'ts-api@example.com', 'password' => self::PASSWORD]);
        $I->amOnPage('/admin/dashboard');
        $I->submitForm('form', ['_code' => $I->generateTotpCode(self::SECRET)]);
        $I->amOnPage('/admin/htaccess-lock');
        $I->seeResponseCodeIs(200);
        $I->seeInField('ips', '203.0.113.77');
    }

    public function theLockRefusesToLockTheCallerOut(AcceptanceTester $I): void
    {
        $token = $this->techSupportToken($I);
        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.9'], $token);
        $I->seeApiResponseCodeIs(201);

        $I->sendApiRequest('POST', '/admin-api/htaccess-lock/enable', null, $token);

        $I->seeApiResponseCodeIs(422);
        $I->seeApiResponseContains('lock you out');
        if ($this->htaccess() !== self::ORIGINAL) {
            throw new \RuntimeException('a refused change must write nothing; got: ' . $this->htaccess());
        }
    }

    public function plainAdminsAndSuperAdminsAreRefusedEverySingleCall(AcceptanceTester $I): void
    {
        $calls = [
            ['GET', '/admin-api/htaccess-lock', null],
            ['PATCH', '/admin-api/htaccess-lock', ['status_code' => 403]],
            ['POST', '/admin-api/htaccess-lock/enable', null],
            ['POST', '/admin-api/htaccess-lock/disable', null],
            ['GET', '/admin-api/htaccess-lock/ips', null],
            ['POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1']],
            ['DELETE', '/admin-api/htaccess-lock/ips?ip=127.0.0.1', null],
            ['GET', '/admin-api/htaccess-lock/exempt-paths', null],
            ['POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/health']],
            ['DELETE', '/admin-api/htaccess-lock/exempt-paths?path=/health', null],
            ['GET', '/admin-api/htaccess-lock/self-test', null],
            ['POST', '/admin-api/htaccess-lock/self-test', null],
        ];

        foreach (['ROLE_ADMIN' => 'plain@example.com', 'ROLE_SUPER_ADMIN' => 'super@example.com'] as $role => $email) {
            $token = $I->createAdminAccessToken($I->createAdmin($email, self::PASSWORD, ['roles' => [$role]]));

            foreach ($calls as [$method, $url, $body]) {
                $I->sendApiRequest($method, $url, $body, $token);
                $I->seeApiResponseCodeIs(403);
                $I->seeApiResponseContains('"error":"Access denied."');
            }
        }

        foreach ($calls as [$method, $url, $body]) {
            $I->sendApiRequest($method, $url, $body, null);
            $I->seeApiResponseCodeIs(401);
        }

        if ($this->htaccess() !== self::ORIGINAL) {
            throw new \RuntimeException('refused calls must not touch .htaccess; got: ' . $this->htaccess());
        }
    }
}
