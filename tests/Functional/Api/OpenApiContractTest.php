<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\OpenApiSpec;
use App\Entity\Admin;
use App\Entity\User;
use App\Htaccess\CurlLoopbackProbe;
use App\Htaccess\HtaccessLockRenderer;
use App\Service\AuditLogger;
use App\Service\InvitationService;
use App\Tests\Support\OpenApiValidator;
use App\Tests\Support\ScriptedHtaccessProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Contract test for docs/api/openapi.yaml (ADR-060): ONE case per documented operation. Each case calls
 * the real endpoint over the real kernel and holds the real answer to the docs:
 *
 *   - every response status must be documented, and its JSON body must validate against the documented
 *     schema — which forbids extra fields (additionalProperties:false), so a field added to the code but
 *     not the docs fails here, as does a renamed field, a changed type, a bad date or a wrong enum value;
 *   - 204s must have an empty body;
 *   - every request body sent is the spec's own documented example, so the example is proven to work;
 *   - every status code the docs list for the operation must actually be produced by the scenario, so the
 *     docs cannot promise a 409 the app never returns.
 *
 * An operation added to the spec with no scenario below fails immediately ("No contract scenario"), and
 * OpenApiSpecTest fails for a real route with no documentation — so neither side can drift silently.
 */
final class OpenApiContractTest extends WebTestCase
{
    private const DEFAULT_TOKEN = '__default__';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private OpenApiSpec $spec;
    private OpenApiValidator $validator;
    private string $adminToken = '';
    private string $superAdminToken = '';
    private string $techSupportToken = '';
    private string $userToken = '';
    private string $htaccessDir = '';

    /** @var array<string,list<int>> operationId => statuses actually produced */
    private array $observed = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->spec = self::getContainer()->get(OpenApiSpec::class);
        $this->validator = new OpenApiValidator($this->spec);

        $this->htaccessDir = self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess';

        $this->cleanup();
        mkdir($this->htaccessDir, 0775, true);   // the Htaccess Lock's test docroot (config/services.yaml when@test)
        $this->adminToken = $this->createAdminToken('contractapi-admin@example.com', 'ROLE_ADMIN');
        $this->superAdminToken = $this->createAdminToken('contractapi-super@example.com', 'ROLE_SUPER_ADMIN');
        $this->techSupportToken = $this->createAdminToken('contractapi-ts@example.com', 'ROLE_TECH_SUPPORT');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    /** @return iterable<string,array{string}> */
    public static function documentedOperations(): iterable
    {
        $spec = new OpenApiSpec(\dirname(__DIR__, 3) . '/docs/api/openapi.yaml');
        foreach ($spec->operations() as $op) {
            yield $op['method'] . ' ' . $op['path'] => [$op['id']];
        }
    }

    #[DataProvider('documentedOperations')]
    public function testOperationBehavesExactlyAsDocumented(string $operationId): void
    {
        $scenario = 'scenario' . ucfirst($operationId);
        self::assertTrue(
            method_exists($this, $scenario),
            sprintf('No contract scenario for the documented operation "%s". Add `%s()` to OpenApiContractTest — every documented call must be exercised for real.', $operationId, $scenario),
        );

        $this->{$scenario}();

        $documented = array_map('intval', array_keys((array) $this->operation($operationId)['operation']->responses));
        $observed = array_unique($this->observed[$operationId] ?? []);
        sort($documented);
        sort($observed);
        self::assertSame(
            $documented,
            array_values($observed),
            sprintf('%s: the statuses the docs list must be exactly the statuses the real endpoint produced in its scenario.', $operationId),
        );
    }

    // ------------------------------------------------------------------ scenarios (one per operation)

    private function scenarioListUsers(): void
    {
        $this->createUserFixture('contractapi-a@example.com');
        $this->createUserFixture('contractapi-b@example.com');

        $filtered = $this->call('listUsers', '/admin-api/users', query: $this->queryFromExamples('listUsers'));
        self::assertGreaterThanOrEqual(2, $filtered->meta->total);

        $unfiltered = $this->call('listUsers', '/admin-api/users');
        self::assertGreaterThanOrEqual(2, \count($unfiltered->data));

        $this->call('listUsers', '/admin-api/users', token: null);
    }

    private function scenarioCreateUser(): void
    {
        $example = $this->requestExample('createUser');

        $created = $this->call('createUser', '/admin-api/users', $example);
        self::assertSame($example['email'], $created->email);
        self::assertSame($example['name'], $created->name);
        self::assertSame($example['status'], $created->status);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$example['email']]));

        $this->call('createUser', '/admin-api/users', $example);          // duplicate email -> 422
        $this->call('createUser', '/admin-api/users', $example, token: null);
    }

    private function scenarioGetUser(): void
    {
        $user = $this->createUserFixture('contractapi-get@example.com');

        $body = $this->call('getUser', $this->userUrl($user->getId()));
        self::assertSame($user->getId(), $body->id);

        $this->call('getUser', $this->userUrl(999999));
        $this->call('getUser', $this->userUrl($user->getId()), token: null);
    }

    private function scenarioUpdateUser(): void
    {
        $user = $this->createUserFixture('contractapi-upd@example.com');
        $example = $this->requestExample('updateUser');

        $body = $this->call('updateUser', $this->userUrl($user->getId()), $example);
        self::assertSame($example['name'], $body->name);

        $this->call('updateUser', $this->userUrl($user->getId()), ['status' => 'banned'], requestIsValid: false);
        $this->call('updateUser', $this->userUrl(999999), $example);
        $this->call('updateUser', $this->userUrl($user->getId()), $example, token: null);
    }

    private function scenarioDeleteUser(): void
    {
        $user = $this->createUserFixture('contractapi-del@example.com');

        $this->call('deleteUser', $this->userUrl($user->getId()));
        self::assertSame('inactive', $this->conn->fetchOne('SELECT status FROM "user" WHERE id = ?', [$user->getId()]), 'documented as a soft delete');

        $this->call('deleteUser', $this->userUrl(999999));
        $this->call('deleteUser', $this->userUrl($user->getId()), token: null);
    }

    private function scenarioActivateUser(): void
    {
        $user = $this->createUserFixture('contractapi-act@example.com', 'inactive');

        $body = $this->call('activateUser', $this->userUrl($user->getId(), '/activate'));
        self::assertSame('active', $body->user->status);

        $this->call('activateUser', $this->userUrl(999999, '/activate'));
        $this->call('activateUser', $this->userUrl($user->getId(), '/activate'), token: null);
    }

    private function scenarioDeactivateUser(): void
    {
        $user = $this->createUserFixture('contractapi-deact@example.com');

        $body = $this->call('deactivateUser', $this->userUrl($user->getId(), '/deactivate'));
        self::assertSame('inactive', $body->user->status);

        $this->call('deactivateUser', $this->userUrl(999999, '/deactivate'));
        $this->call('deactivateUser', $this->userUrl($user->getId(), '/deactivate'), token: null);
    }

    private function scenarioForceLogoutUser(): void
    {
        $user = $this->createUserFixture('contractapi-logout@example.com');

        $this->call('forceLogoutUser', $this->userUrl($user->getId(), '/force-logout'));
        $this->call('forceLogoutUser', $this->userUrl(999999, '/force-logout'));
        $this->call('forceLogoutUser', $this->userUrl($user->getId(), '/force-logout'), token: null);
    }

    private function scenarioSendUserPasswordReset(): void
    {
        $user = $this->createUserFixture('contractapi-reset@example.com');

        $this->call('sendUserPasswordReset', $this->userUrl($user->getId(), '/password-reset'));
        $this->call('sendUserPasswordReset', $this->userUrl(999999, '/password-reset'));
        $this->call('sendUserPasswordReset', $this->userUrl($user->getId(), '/password-reset'), token: null);
    }

    private function scenarioRevokeUserTokens(): void
    {
        $user = $this->createUserFixture('contractapi-revoke@example.com');
        $this->createPersonalAccessToken($user);

        $this->call('revokeUserTokens', $this->userUrl($user->getId(), '/tokens'));
        self::assertNotNull($this->conn->fetchOne('SELECT revoked_at FROM personal_access_tokens WHERE user_id = ?', [$user->getId()]), 'the token must now be revoked');

        $this->call('revokeUserTokens', $this->userUrl(999999, '/tokens'));
        $this->call('revokeUserTokens', $this->userUrl($user->getId(), '/tokens'), token: null);
    }

    private function scenarioUnlockUser(): void
    {
        $user = $this->createUserFixture('contractapi-unlock@example.com');
        $this->conn->executeStatement('INSERT INTO account_lockouts (locked_until, user_id) VALUES (?, ?)', ['2099-01-01 00:00:00', $user->getId()]);

        $this->call('unlockUser', $this->userUrl($user->getId(), '/unlock'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_lockouts WHERE user_id = ?', [$user->getId()]), 'the lockout must be cleared');

        $this->call('unlockUser', $this->userUrl(999999, '/unlock'));
        $this->call('unlockUser', $this->userUrl($user->getId(), '/unlock'), token: null);
    }

    private function scenarioResetUserTwoFactor(): void
    {
        $user = $this->createUserFixture('contractapi-2fa@example.com');
        $this->conn->executeStatement(
            'INSERT INTO two_factor_settings (totp_secret, is_totp_enabled, user_id) VALUES (?, 1, ?)',
            ['JBSWY3DPEHPK3PXP', $user->getId()],
        );

        $this->call('resetUserTwoFactor', $this->userUrl($user->getId(), '/2fa'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM two_factor_settings WHERE user_id = ? AND is_totp_enabled = 1', [$user->getId()]), '2FA must be reset');

        $this->call('resetUserTwoFactor', $this->userUrl(999999, '/2fa'));
        $this->call('resetUserTwoFactor', $this->userUrl($user->getId(), '/2fa'), token: null);
    }

    private function scenarioSendInvitation(): void
    {
        $example = $this->requestExample('sendInvitation');

        $body = $this->call('sendInvitation', '/admin-api/invitations', $example);
        self::assertSame($example['email'], $body->email);
        self::assertNull($body->used_at);

        $this->call('sendInvitation', '/admin-api/invitations', ['email' => 'not-an-email'], requestIsValid: false);
        $this->call('sendInvitation', '/admin-api/invitations', $example, token: null);
    }

    private function scenarioResendInvitation(): void
    {
        $invitation = self::getContainer()->get(InvitationService::class)->send('contractapi-invitee@example.com');
        $url = sprintf('/admin-api/invitations/%d/resend', $invitation->getId());

        $this->call('resendInvitation', $url);

        $this->conn->executeStatement('UPDATE invitations SET used_at = ? WHERE id = ?', ['2026-01-01 00:00:00', $invitation->getId()]);
        $this->call('resendInvitation', $url);                                                  // already used -> 409

        $this->call('resendInvitation', '/admin-api/invitations/999999/resend');
        $this->call('resendInvitation', $url, token: null);
    }

    private function scenarioListAuditLog(): void
    {
        self::getContainer()->get(AuditLogger::class)->log('contractapi-actor@example.com', 'admin', '203.0.113.9', 'admin.user_create', 'success', 'contract test');

        $all = $this->call('listAuditLog', '/admin-api/audit-log');
        self::assertGreaterThanOrEqual(1, \count($all->data), 'the validated items are the point of this check');

        $this->call('listAuditLog', '/admin-api/audit-log', query: $this->queryFromExamples('listAuditLog'));
        $this->call('listAuditLog', '/admin-api/audit-log', query: ['date_from' => 'banana'], requestIsValid: false);
        $this->call('listAuditLog', '/admin-api/audit-log', token: null);
    }

    private function scenarioPing(): void
    {
        $this->userToken = $this->createPersonalAccessToken($this->createUserFixture('contractapi-pinger@example.com'));

        $this->call('ping', '/api/ping');
        self::assertSame(200, $this->lastStatus, 'a user personal access token reaches /api');
        $this->call('ping', '/api/ping', token: null);
        self::assertSame(401, $this->lastStatus, 'no token, no /api');
        // Issue #58: this assertion used to run BEFORE the admin-token call, so it checked the token-less one and
        // an /api that accepted admin tokens would have passed (200 is a documented ping status).
        $this->call('ping', '/api/ping', token: $this->adminToken);
        self::assertSame(401, $this->lastStatus, 'an ADMIN token is not a user personal access token');
    }

    // ---- Htaccess Lock API (tech support only). Every call goes through the one gate (ADR-062); the file
    // ---- under test is var/test-htaccess/.htaccess, never public/.htaccess.

    private function scenarioGetHtaccessLock(): void
    {
        $lock = $this->call('getHtaccessLock', '/admin-api/htaccess-lock');
        self::assertFalse($lock->enabled);
        self::assertSame([], $lock->ips);
        self::assertSame(404, $lock->status_code);
        self::assertSame('127.0.0.1', $lock->your_ip);

        $this->assertNotForAdminsBelowTechSupport('getHtaccessLock', '/admin-api/htaccess-lock');
    }

    private function scenarioUpdateHtaccessLock(): void
    {
        $example = $this->requestExample('updateHtaccessLock');

        $lock = $this->call('updateHtaccessLock', '/admin-api/htaccess-lock', $example);
        self::assertSame($example['status_code'], $lock->status_code);
        self::assertSame($example['exempt_paths'], $lock->exempt_paths);
        self::assertSame('/health', $this->config('htaccess_lock.exempt_paths'));

        $this->call('updateHtaccessLock', '/admin-api/htaccess-lock', ['status_code' => 500], requestIsValid: false);
        $this->call('updateHtaccessLock', '/admin-api/htaccess-lock', ['enabled' => true], requestIsValid: false);
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_update', 'success'), 'the change is audited exactly once');

        $this->corruptHtaccess();
        $this->call('updateHtaccessLock', '/admin-api/htaccess-lock', ['status_code' => 403]);   // 500: unwritable/garbled file
        self::assertSame('404', $this->config('htaccess_lock.status_code'), 'nothing is recorded when the file could not be written');

        $this->assertNotForAdminsBelowTechSupport('updateHtaccessLock', '/admin-api/htaccess-lock', $example);
    }

    private function scenarioEnableHtaccessLock(): void
    {
        $this->call('enableHtaccessLock', '/admin-api/htaccess-lock/enable');                   // 422: nobody whitelisted
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '203.0.113.9']);
        $refused = $this->call('enableHtaccessLock', '/admin-api/htaccess-lock/enable');        // 422: would lock the caller out
        self::assertStringContainsString('lock you out', $refused->error);
        self::assertStringNotContainsString(HtaccessLockRenderer::BEGIN, $this->htaccess(), 'a refused change writes nothing');

        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1']);
        $lock = $this->call('enableHtaccessLock', '/admin-api/htaccess-lock/enable');
        self::assertTrue($lock->enabled);
        self::assertTrue($lock->file->has_block);
        self::assertTrue($lock->file->in_sync);
        self::assertStringContainsString(HtaccessLockRenderer::BEGIN, $this->htaccess());
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_enable', 'success'));

        $this->corruptHtaccess();
        $this->call('enableHtaccessLock', '/admin-api/htaccess-lock/enable');                   // 500

        $this->assertNotForAdminsBelowTechSupport('enableHtaccessLock', '/admin-api/htaccess-lock/enable');
    }

    private function scenarioDisableHtaccessLock(): void
    {
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1']);
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/enable');
        self::assertStringContainsString(HtaccessLockRenderer::BEGIN, $this->htaccess());

        $lock = $this->call('disableHtaccessLock', '/admin-api/htaccess-lock/disable');
        self::assertFalse($lock->enabled);
        self::assertSame(['127.0.0.1'], $lock->ips, 'the saved whitelist is kept');
        self::assertStringNotContainsString(HtaccessLockRenderer::BEGIN, $this->htaccess());
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_disable', 'success'));

        $this->corruptHtaccess();
        $this->call('disableHtaccessLock', '/admin-api/htaccess-lock/disable');                 // 500

        $this->assertNotForAdminsBelowTechSupport('disableHtaccessLock', '/admin-api/htaccess-lock/disable');
    }

    private function scenarioListWhitelistedIps(): void
    {
        self::assertSame([], $this->call('listWhitelistedIps', '/admin-api/htaccess-lock/ips')->data);

        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '2001:DB8::1']);
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '198.51.100.0/24']);
        self::assertSame(['2001:db8::1', '198.51.100.0/24'], $this->call('listWhitelistedIps', '/admin-api/htaccess-lock/ips')->data, 'stored in canonical form');

        $this->assertNotForAdminsBelowTechSupport('listWhitelistedIps', '/admin-api/htaccess-lock/ips');
    }

    private function scenarioAddWhitelistedIp(): void
    {
        $example = $this->requestExample('addWhitelistedIp');

        $list = $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', $example);
        self::assertSame([$example['ip']], $list->data);
        self::assertSame($example['ip'], $this->config('htaccess_lock.ips'));
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_ip_add', 'success'), 'added, audited once');
        self::assertSame('contractapi-ts@example.com', $this->conn->fetchOne("SELECT actor FROM audit_log WHERE action = 'admin.htaccess_lock_ip_add'"));

        $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', $example);                          // 409
        $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', ['ip' => '999.1.1.1']);             // 422
        $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', ['ip' => '10.0.0.0/0']);            // 422: /0 would defeat the lock
        $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', ['address' => '1.2.3.4'], requestIsValid: false);

        $this->corruptHtaccess();
        $this->call('addWhitelistedIp', '/admin-api/htaccess-lock/ips', ['ip' => '198.51.100.7']);          // 500
        self::assertSame($example['ip'], $this->config('htaccess_lock.ips'), 'nothing is recorded when the file could not be written');

        $this->assertNotForAdminsBelowTechSupport('addWhitelistedIp', '/admin-api/htaccess-lock/ips', $example);
    }

    private function scenarioRemoveWhitelistedIp(): void
    {
        $example = $this->queryFromExamples('removeWhitelistedIp');
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '127.0.0.1']);
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => $example['ip']]);
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/enable');

        $this->call('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: ['ip' => '127.0.0.1']);   // 422: would lock the caller out
        $list = $this->call('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: $example);
        self::assertSame(['127.0.0.1'], $list->data);
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_ip_remove', 'success'));
        self::assertStringNotContainsString($example['ip'], $this->htaccess(), 'the running .htaccess no longer lets that range in');

        $this->call('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: $example);                // 404
        $this->call('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: ['ip' => 'banana']);      // 422

        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/ips', ['ip' => '198.51.100.1']);
        $this->corruptHtaccess();
        $this->call('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: ['ip' => '198.51.100.1']); // 500

        $this->assertNotForAdminsBelowTechSupport('removeWhitelistedIp', '/admin-api/htaccess-lock/ips', query: $example);
    }

    private function scenarioListExemptPaths(): void
    {
        self::assertSame([], $this->call('listExemptPaths', '/admin-api/htaccess-lock/exempt-paths')->data);

        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/webhooks/*']);
        self::assertSame(['/webhooks/*'], $this->call('listExemptPaths', '/admin-api/htaccess-lock/exempt-paths')->data);

        $this->assertNotForAdminsBelowTechSupport('listExemptPaths', '/admin-api/htaccess-lock/exempt-paths');
    }

    private function scenarioAddExemptPath(): void
    {
        $example = $this->requestExample('addExemptPath');

        $list = $this->call('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', $example);
        self::assertSame([$example['path']], $list->data);
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_exempt_add', 'success'));

        $this->call('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', $example);                    // 409
        $this->call('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', ['path' => 'no-leading-slash']); // 422
        $this->call('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/*']);            // 422: would exempt the whole site

        $this->corruptHtaccess();
        $this->call('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/other']);        // 500

        $this->assertNotForAdminsBelowTechSupport('addExemptPath', '/admin-api/htaccess-lock/exempt-paths', $example);
    }

    private function scenarioRemoveExemptPath(): void
    {
        $example = $this->queryFromExamples('removeExemptPath');
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => $example['path']]);
        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/exempt-paths', ['path' => '/keep']);

        $list = $this->call('removeExemptPath', '/admin-api/htaccess-lock/exempt-paths', query: $example);
        self::assertSame(['/keep'], $list->data);
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_exempt_remove', 'success'));

        $this->call('removeExemptPath', '/admin-api/htaccess-lock/exempt-paths', query: $example);          // 404
        $this->call('removeExemptPath', '/admin-api/htaccess-lock/exempt-paths', query: ['path' => 'nope']); // 422

        $this->corruptHtaccess();
        $this->call('removeExemptPath', '/admin-api/htaccess-lock/exempt-paths', query: ['path' => '/keep']); // 500

        $this->assertNotForAdminsBelowTechSupport('removeExemptPath', '/admin-api/htaccess-lock/exempt-paths', query: $example);
    }

    private function scenarioGetHtaccessLockSelfTest(): void
    {
        $this->scriptPassingSelfTest();   // before the first request: the probe must be in place in the container that serves them
        $this->call('getHtaccessLockSelfTest', '/admin-api/htaccess-lock/self-test');                        // 404: never run

        $this->rawAsTechSupport('POST', '/admin-api/htaccess-lock/self-test');
        $report = $this->call('getHtaccessLockSelfTest', '/admin-api/htaccess-lock/self-test');
        self::assertTrue($report->passed);
        self::assertCount(6, $report->steps);

        $this->assertNotForAdminsBelowTechSupport('getHtaccessLockSelfTest', '/admin-api/htaccess-lock/self-test');
    }

    private function scenarioRunHtaccessLockSelfTest(): void
    {
        file_put_contents($this->htaccessDir . '/.htaccess', "RewriteEngine On\n");
        $this->scriptPassingSelfTest();

        $report = $this->call('runHtaccessLockSelfTest', '/admin-api/htaccess-lock/self-test');
        self::assertTrue($report->passed);
        self::assertSame("RewriteEngine On\n", $this->htaccess(), 'the test leaves the file exactly as it found it');
        self::assertSame(1, $this->auditCount('admin.htaccess_lock_test', 'success'));

        $this->assertNotForAdminsBelowTechSupport('runHtaccessLockSelfTest', '/admin-api/htaccess-lock/self-test');
    }

    // ---- Htaccess Lock helpers

    /** "Tech support only — not even a superadmin": both lower admin classes must get the documented 403. */
    private function assertNotForAdminsBelowTechSupport(string $operationId, string $url, ?array $json = null, array $query = []): void
    {
        foreach ([$this->adminToken, $this->superAdminToken] as $token) {
            $this->call($operationId, $url, $json, $query, $token);
            self::assertSame(403, $this->lastStatus);
        }
        $this->call($operationId, $url, $json, $query, token: null);
        self::assertSame(401, $this->lastStatus);
    }

    /** A call that is set-up rather than under test: same real endpoint and gate, but not counted for the contract. */
    private function rawAsTechSupport(string $method, string $url, ?array $json = null): void
    {
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->techSupportToken,
            'CONTENT_TYPE' => 'application/json',
        ], $json !== null ? json_encode($json) : null);
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode(), 'set-up call failed: ' . $this->client->getResponse()->getContent());
    }

    /** What the web server would answer to the self-test's four probes if it really enforced the lock. */
    private function scriptPassingSelfTest(): void
    {
        $this->client->disableReboot();   // keep this container (and the scripted probe set in it) for the next request
        $probe = new ScriptedHtaccessProbe();
        $probe->responses = [403, 200, 200, 404];
        self::getContainer()->set(CurlLoopbackProbe::class, $probe);
        is_dir($this->htaccessDir) || mkdir($this->htaccessDir, 0775, true);
    }

    /** Unbalanced markers make the file refuse to be edited — a real, deterministic write failure (works as root too). */
    private function corruptHtaccess(): void
    {
        is_dir($this->htaccessDir) || mkdir($this->htaccessDir, 0775, true);
        file_put_contents($this->htaccessDir . '/.htaccess', HtaccessLockRenderer::BEGIN . "\n");
    }

    private function htaccess(): string
    {
        return is_file($this->htaccessDir . '/.htaccess') ? (string) file_get_contents($this->htaccessDir . '/.htaccess') : '';
    }

    private function config(string $key): ?string
    {
        $v = $this->conn->fetchOne('SELECT config_value FROM config WHERE config_key = ?', [$key]);

        return $v === false ? null : (string) $v;
    }

    private function auditCount(string $action, string $outcome): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND outcome = ?', [$action, $outcome]);
    }

    // ------------------------------------------------------------------ the contract check

    private int $lastStatus = 0;

    /**
     * Perform the documented call for real and hold the answer to the docs.
     *
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $query
     * @param string|null              $token          null = send no Authorization header
     * @param bool                     $requestIsValid true = the body must itself match the documented request schema
     */
    private function call(string $operationId, string $url, ?array $json = null, array $query = [], ?string $token = self::DEFAULT_TOKEN, bool $requestIsValid = true): ?object
    {
        $op = $this->operation($operationId);
        $path = parse_url($url, PHP_URL_PATH);
        $template = '#^' . preg_replace('/\\\\\{\w+\\\\\}/', '[^/]+', preg_quote($op['path'], '#')) . '$#';
        self::assertMatchesRegularExpression($template, (string) $path, sprintf('%s: the scenario hit %s, which is not the documented path %s.', $operationId, $path, $op['path']));

        if ($json !== null && $requestIsValid) {
            $pointer = $this->validator->requestSchemaPointer($op);
            self::assertNotNull($pointer, sprintf('%s sends a JSON body but the docs describe none.', $operationId));
            self::assertSame([], $this->validator->errors(json_decode(json_encode($json)), $pointer), sprintf('%s: the request body does not match the documented request schema.', $operationId));
        }

        if ($token === self::DEFAULT_TOKEN) {
            $token = match (true) {
                str_starts_with($op['path'], '/api/') => $this->userToken,
                ($op['operation']->{'x-audience'} ?? null) === OpenApiSpec::AUDIENCE_TECH_SUPPORT => $this->techSupportToken,
                default => $this->adminToken,
            };
        }
        $server = [];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request($op['method'], $url . ($query !== [] ? '?' . http_build_query($query) : ''), [], [], $server, $json !== null ? json_encode($json) : null);
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $this->lastStatus = $status;
        $this->observed[$operationId][] = $status;
        $body = (string) $response->getContent();

        self::assertArrayHasKey(
            (string) $status,
            (array) $op['operation']->responses,
            sprintf("%s answered %d, which the docs do not list. Body: %s", $operationId, $status, $body),
        );

        $pointer = $this->validator->responseSchemaPointer($op, (string) $status);
        if ($pointer === null) {
            self::assertSame('', $body, sprintf('%s %d is documented without a body, but the app sent one.', $operationId, $status));

            return null;
        }

        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), sprintf('%s %d is documented as application/json.', $operationId, $status));
        $decoded = json_decode($body, false);
        self::assertNotNull($decoded, sprintf('%s %d: body is not valid JSON: %s', $operationId, $status, $body));

        $errors = $this->validator->errors($decoded, $pointer);
        self::assertSame(
            [],
            $errors,
            sprintf("%s %d does not match its documented schema:\n  %s\nActual body: %s", $operationId, $status, implode("\n  ", $errors), $body),
        );

        return \is_object($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{id:string,method:string,path:string,operation:\stdClass,pathItem:\stdClass} */
    private function operation(string $operationId): array
    {
        foreach ($this->spec->operations() as $op) {
            if ($op['id'] === $operationId) {
                return $op;
            }
        }
        self::fail(sprintf('operationId "%s" is not in the spec.', $operationId));
    }

    /** The documented request-body example, as the array the scenario will actually send. */
    private function requestExample(string $operationId): array
    {
        $example = $this->operation($operationId)['operation']->requestBody->content->{'application/json'}->example ?? null;
        self::assertNotNull($example, sprintf('%s documents no request example.', $operationId));

        return json_decode(json_encode($example), true);
    }

    /** @return array<string,string> every documented query parameter, filled with its documented example */
    private function queryFromExamples(string $operationId): array
    {
        $query = [];
        foreach ($this->operation($operationId)['operation']->parameters ?? [] as $parameter) {
            $p = $this->spec->resolve($parameter);
            if ($p->in === 'query') {
                self::assertObjectHasProperty('example', $p, sprintf('%s: query parameter "%s" needs a documented example.', $operationId, $p->name));
                $query[$p->name] = (string) $p->example;
            }
        }

        return $query;
    }

    private function userUrl(int $id, string $suffix = ''): string
    {
        return '/admin-api/users/' . $id . $suffix;
    }

    private function createAdminToken(string $email, string $role): string
    {
        $admin = new Admin();
        $admin->setEmail($email);
        $admin->setName('Contract API ' . $role);
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $admin->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $adminId = (int) $admin->getId();
        $this->em->clear();

        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('admin_access_tokens', [
            'admin_id' => $adminId,
            'name' => 'Contract test token ' . $role,
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createPersonalAccessToken(User $user): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->insert('personal_access_tokens', [
            'user_id' => $user->getId(),
            'name' => 'Contract test PAT',
            'token_hash' => hash('sha256', $plaintext),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plaintext;
    }

    private function createUserFixture(string $email, string $status = 'active'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName('Contract User');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function cleanup(): void
    {
        try {
            $this->conn->executeStatement('DELETE FROM personal_access_tokens');
            $this->conn->executeStatement('DELETE FROM admin_access_tokens');
            $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'contractapi%@example.com'");
            // The spec's own example addresses are what the create/invite scenarios send.
            $this->conn->executeStatement("DELETE FROM \"user\" WHERE email LIKE 'contractapi%@example.com' OR email IN ('jane.doe@example.com')");
            $this->conn->executeStatement("DELETE FROM invitations WHERE email LIKE 'contractapi%@example.com' OR email IN ('new.hire@example.com')");
            $this->conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'htaccess_lock.%'");
            foreach (glob($this->htaccessDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                is_file($file) && @unlink($file);
            }
            @rmdir($this->htaccessDir);
            @unlink(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-htaccess.lock');
            foreach (['audit_log', 'login_history', 'user_sessions', 'password_reset_tokens', 'password_history', 'password_meta'] as $table) {
                $this->conn->executeStatement('DELETE FROM ' . $table);
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }
}
