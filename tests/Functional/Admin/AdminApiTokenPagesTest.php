<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #39 / ADR-064: admin API tokens can be seen and revoked in the panel.
 *  - every admin: their own tokens at /admin/api-tokens (last 6 chars shown), revoke their own, never another's;
 *  - tech support: any admin's token page, revoke one or all; superadmins and plain admins cannot;
 *  - deactivating or deleting an admin revokes its tokens for good, so reactivation brings none back;
 *  - a revoked or expired token is answered 401 by the admin API.
 */
final class AdminApiTokenPagesTest extends WebTestCase
{
    use AuthenticationTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement('DELETE FROM admin_access_tokens');
        $this->conn->executeStatement("DELETE FROM admin WHERE email LIKE 'apitok-%@example.com'");
        $this->conn->executeStatement("DELETE FROM audit_log WHERE action LIKE 'admin.api_token_%'");
        $this->conn->executeStatement('DELETE FROM endpoint_rate_limits');
        $this->conn->executeStatement('DELETE FROM admin_sessions');
        $this->em->clear();
    }

    /** @return array{id:int,plaintext:string} */
    private function issue(string $ownerEmail, string $name, ?string $expiresAt = null): array
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->conn->executeStatement(
            'INSERT INTO admin_access_tokens (admin_id, name, token_hash, token_hint, expires_at, created_at) SELECT id, ?, ?, ?, ?, ? FROM admin WHERE email = ?',
            [$name, hash('sha256', $plaintext), substr($plaintext, -6), $expiresAt, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $ownerEmail],
        );

        return ['id' => (int) $this->conn->lastInsertId(), 'plaintext' => $plaintext];
    }

    private function apiStatus(string $plaintext): int
    {
        $browser = clone $this->client;
        $browser->getCookieJar()->clear();
        $browser->request('GET', '/admin-api/users', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $plaintext]);

        return $browser->getResponse()->getStatusCode();
    }

    /** The token's revoked_at, or null while live. Fails if the row is gone (so "not null" can never mean "deleted"). */
    private function revokedAt(int $id): ?string
    {
        $row = $this->conn->fetchAssociative('SELECT revoked_at FROM admin_access_tokens WHERE id = ?', [$id]);
        self::assertIsArray($row, "token $id must still exist (revoking never deletes)");

        return $row['revoked_at'] === null ? null : (string) $row['revoked_at'];
    }

    public function testAnAdminSeesOnlyTheirOwnTokensWithTheLastSixCharacters(): void
    {
        $this->createTestAdmin('apitok-me@example.com', roles: ['ROLE_ADMIN']);
        $this->createTestAdmin('apitok-other@example.com', roles: ['ROLE_ADMIN']);
        $mine = $this->issue('apitok-me@example.com', 'My CI token');
        $this->issue('apitok-other@example.com', 'Someone else token');

        $this->loginAsAdmin('apitok-me@example.com');
        $this->client->request('GET', '/admin/api-tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'My API Tokens');
        self::assertSelectorTextContains('.api-token-name', 'My CI token');
        self::assertSelectorTextContains('.api-token-hint', '…' . substr($mine['plaintext'], -6));
        self::assertSelectorTextContains('.api-token-status', 'active');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Someone else token', $html);
        self::assertStringNotContainsString(substr($mine['plaintext'], 0, 20), $html, 'the secret itself is never shown');
        self::assertSelectorExists('aside.sidebar a[href="/admin/api-tokens"]');
    }

    public function testAnAdminRevokesTheirOwnTokenAndTheApiStopsAcceptingIt(): void
    {
        $this->createTestAdmin('apitok-me@example.com', roles: ['ROLE_ADMIN']);
        $mine = $this->issue('apitok-me@example.com', 'Leaked in CI');
        self::assertSame(200, $this->apiStatus($mine['plaintext']));

        $this->loginAsAdmin('apitok-me@example.com');
        $this->client->request('GET', '/admin/api-tokens');
        $this->client->submitForm('Revoke');
        self::assertResponseRedirects('/admin/api-tokens');
        $this->client->followRedirect();

        self::assertNotNull($this->revokedAt($mine['id']));
        self::assertSelectorTextContains('.api-token-status', 'revoked');
        self::assertSelectorNotExists('.api-token-revoke-btn');
        self::assertSame(401, $this->apiStatus($mine['plaintext']));
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_revoke' AND actor = 'apitok-me@example.com'"));
    }

    public function testAnAdminCannotRevokeAnotherAdminsToken(): void
    {
        $this->createTestAdmin('apitok-me@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $this->createTestAdmin('apitok-other@example.com', roles: ['ROLE_ADMIN']);
        $theirs = $this->issue('apitok-other@example.com', 'Theirs');

        $this->loginAsAdmin('apitok-me@example.com');
        $this->client->request('GET', '/admin/api-tokens');
        // Plant a VALID CSRF token for their id in my session, so only the ownership check can stop this.
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/admin_api_token_revoke_' . $theirs['id'], 'planted-valid-token');
        $session->save();
        $this->client->request('POST', '/admin/api-tokens/' . $theirs['id'] . '/revoke', ['_token' => 'planted-valid-token']);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->revokedAt($theirs['id']));
    }

    public function testRevokeRequiresAValidCsrfToken(): void
    {
        $this->createTestAdmin('apitok-me@example.com', roles: ['ROLE_ADMIN']);
        $mine = $this->issue('apitok-me@example.com', 'Mine');

        $this->loginAsAdmin('apitok-me@example.com');
        $this->client->request('POST', '/admin/api-tokens/' . $mine['id'] . '/revoke', ['_token' => 'forged']);

        self::assertResponseRedirects('/admin/api-tokens');
        self::assertNull($this->revokedAt($mine['id']));
    }

    public function testTechSupportCanOpenAnyAdminsTokenPageAndRevokeOneOrAll(): void
    {
        $target = $this->createTestAdmin('apitok-target@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $one = $this->issue('apitok-target@example.com', 'Old laptop');
        $two = $this->issue('apitok-target@example.com', 'CI');
        $three = $this->issue('apitok-target@example.com', 'Zapier');

        $this->loginAsEnrolledTechSupport('apitok-ts@example.com');
        $this->client->request('GET', '/admin/superadmin/admins');
        self::assertSelectorExists(sprintf('a.admin-api-tokens-link[href="/admin/superadmin/admins/%d/api-tokens"]', $target->getId()));

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/api-tokens');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'apitok-target@example.com');
        self::assertSelectorCount(3, 'tr.api-token-active');
        self::assertStringContainsString('…' . substr($one['plaintext'], -6), (string) $this->client->getResponse()->getContent());

        $form = $this->client->getCrawler()->filter(sprintf('tr[data-token-id="%d"] form', $one['id']))->form();
        $this->client->submit($form);
        self::assertNotNull($this->revokedAt($one['id']));
        self::assertNull($this->revokedAt($two['id']));
        $this->client->followRedirect();

        $this->client->submitForm('Revoke all 2 active token(s)');
        self::assertNotNull($this->revokedAt($two['id']));
        self::assertNotNull($this->revokedAt($three['id']));
        self::assertSame(401, $this->apiStatus($three['plaintext']));
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_revoke_all' AND actor = 'apitok-ts@example.com' AND context LIKE '%count=2%'"));
    }

    /** @return iterable<string,array{string}> */
    public static function rolesBelowTechSupport(): iterable
    {
        yield 'plain admin' => ['ROLE_ADMIN'];
        yield 'superadmin' => ['ROLE_SUPER_ADMIN'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolesBelowTechSupport')]
    public function testOnlyTechSupportCanSeeOrRevokeOtherAdminsTokens(string $role): void
    {
        $target = $this->createTestAdmin('apitok-target@example.com', roles: ['ROLE_ADMIN']);
        $token = $this->issue('apitok-target@example.com', 'Theirs');
        $this->createTestAdmin('apitok-viewer@example.com', roles: [$role]);
        $this->loginAsAdmin('apitok-viewer@example.com');

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/api-tokens');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/superadmin/admins/' . $target->getId() . '/api-tokens/' . $token['id'] . '/revoke');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/superadmin/admins/' . $target->getId() . '/api-tokens/revoke-all');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->revokedAt($token['id']));

        if ($role === 'ROLE_SUPER_ADMIN') {
            $this->client->request('GET', '/admin/superadmin/admins');
            self::assertSelectorNotExists('a.admin-api-tokens-link');
        }
    }

    public function testDeactivatingAnAdminRevokesItsTokensAndReactivationBringsNoneBack(): void
    {
        $target = $this->createTestAdmin('apitok-target@example.com', 'Target', roles: ['ROLE_ADMIN']);
        $token = $this->issue('apitok-target@example.com', 'Leaked');
        $this->createTestAdmin('apitok-boss@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('apitok-boss@example.com');

        $edit = fn (string $status) => $this->client->submitForm('Save', ['email' => 'apitok-target@example.com', 'name' => 'Target', 'role' => 'ROLE_ADMIN', 'status' => $status]);
        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/edit');
        $edit('inactive');
        self::assertNotNull($this->revokedAt($token['id']));

        $this->client->request('GET', '/admin/superadmin/admins/' . $target->getId() . '/edit');
        $edit('active');
        self::assertSame('active', $this->conn->fetchOne("SELECT status FROM admin WHERE email = 'apitok-target@example.com'"));
        self::assertSame(401, $this->apiStatus($token['plaintext']), 'a leaked token must not come back with the account');
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.api_token_revoke_all' AND context LIKE '%reason=deactivated%'"));
    }

    public function testDeletingAnAdminRevokesItsTokens(): void
    {
        $target = $this->createTestAdmin('apitok-target@example.com', roles: ['ROLE_ADMIN']);
        $token = $this->issue('apitok-target@example.com', 'Leaked');
        $this->createTestAdmin('apitok-boss@example.com', roles: ['ROLE_SUPER_ADMIN']);
        $this->loginAsAdmin('apitok-boss@example.com');

        $this->client->request('GET', '/admin/superadmin/admins');
        $form = $this->client->getCrawler()->filter(sprintf('form[action="/admin/superadmin/admins/%d/delete"]', $target->getId()))->form();
        $this->client->submit($form);

        self::assertNotNull($this->revokedAt($token['id']));
        $this->conn->executeStatement("UPDATE admin SET status = 'active' WHERE email = 'apitok-target@example.com'");
        self::assertSame(401, $this->apiStatus($token['plaintext']));
    }

    public function testAnExpiredTokenIsRefusedAndShownAsExpired(): void
    {
        $this->createTestAdmin('apitok-me@example.com', roles: ['ROLE_ADMIN']);
        $old = $this->issue('apitok-me@example.com', 'Expired one', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));

        self::assertSame(401, $this->apiStatus($old['plaintext']));

        $this->loginAsAdmin('apitok-me@example.com');
        $this->client->request('GET', '/admin/api-tokens');
        self::assertSelectorTextContains('.api-token-status', 'expired');
    }
}
