<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminPassword;

use App\AdminPassword\AdminPasswordChange;
use App\AdminPassword\AdminPasswordGate;
use App\AdminPassword\PasswordChangeOutcome;
use App\Entity\Admin;
use App\Entity\AdminPasswordResetToken;
use App\Tests\Support\AuthenticationTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AdminPasswordGate (ADR-073): the one place an existing admin's password changes. For all three ways in
 * (reset link, own, tech support) the gate checks permission, validates, writes, audits and revokes — proven
 * here once per source, not per controller.
 */
final class AdminPasswordGateTest extends KernelTestCase
{
    use AuthenticationTestTrait;

    private const PREFIX = 'apg-';
    private const NEW = 'a-Brand-new-passphrase-9';

    private EntityManagerInterface $em;
    private Connection $db;
    private AdminPasswordGate $gate;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->gate = $c->get(AdminPasswordGate::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%@example.com';
        $this->db->executeStatement('DELETE FROM admin_sessions WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE ?)', [$like]);
        $this->db->executeStatement('DELETE FROM db_console_session WHERE admin_id IN (SELECT id FROM admin WHERE email LIKE ?)', [$like]);
        $this->db->executeStatement('DELETE FROM admin_password_reset_tokens WHERE email LIKE ?', [$like]);
        $this->db->executeStatement('DELETE FROM admin WHERE email LIKE ?', [$like]);
        $this->db->executeStatement('DELETE FROM audit_log');
        $this->em->clear();
    }

    private function admin(string $name, array $roles = [], string $status = 'active', string $password = 'adminpass'): Admin
    {
        return $this->createTestAdmin(self::PREFIX . $name . '@example.com', ucfirst($name), $password, $status, $roles);
    }

    private function fresh(Admin $admin): Admin
    {
        $this->em->clear();

        return $this->em->getRepository(Admin::class)->find($admin->getId());
    }

    private function passwordIs(Admin $admin, string $plain): bool
    {
        return password_verify($plain, $this->fresh($admin)->getPassword());
    }

    /** Seed one live session, one open DB console and one pending reset link for the admin. */
    private function seedRevocables(Admin $admin): string
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert('admin_sessions', ['session_id' => 's-' . $admin->getId(), 'admin_id' => $admin->getId(), 'ip' => '1.2.3.4', 'user_agent' => 'ua', 'created_at' => $now, 'last_active_at' => $now]);
        $this->db->insert('db_console_session', ['token_hash' => str_repeat('a', 64), 'admin_id' => $admin->getId(), 'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'), 'ip_address' => '1.2.3.4', 'created_at' => $now]);

        return $this->pendingLink($admin->getEmail());
    }

    private function pendingLink(string $email): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new AdminPasswordResetToken($email, hash('sha256', $plaintext), new \DateTimeImmutable('+1 hour')));
        $this->em->flush();

        return $plaintext;
    }

    private function assertRevoked(Admin $admin, string $otherPendingLink): void
    {
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ?', [$admin->getId()]), 'live sessions closed');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM db_console_session WHERE admin_id = ?', [$admin->getId()]), 'DB console closed');
        self::assertNotNull($this->gate->resetLinkProblem($otherPendingLink), 'other pending reset links retired');
    }

    /** @return list<array{actor:string,action:string,outcome:string,context:?string}> */
    private function audit(): array
    {
        return $this->db->fetchAllAssociative('SELECT actor, action, outcome, context FROM audit_log ORDER BY id');
    }

    // ------------------------------------------------------------------ reset link

    public function testResetLinkChangesThePasswordConsumesTheLinkAuditsAndRevokes(): void
    {
        $target = $this->admin('rl');
        $link = $this->pendingLink($target->getEmail());
        $other = $this->seedRevocables($target);

        $result = $this->gate->change(AdminPasswordChange::viaResetLink($link, self::NEW, '9.9.9.9'));

        self::assertTrue($result->isChanged());
        self::assertTrue($this->passwordIs($target, self::NEW));
        self::assertNotNull($this->gate->resetLinkProblem($link), 'the used link is dead');
        $this->assertRevoked($target, $other);
        self::assertSame([['actor' => $target->getEmail(), 'action' => 'admin.password_reset', 'outcome' => 'success', 'context' => null]], $this->audit());
    }

    public function testResetLinkIsRefusedWhenUnknownUsedExpiredOrTheAccountIsInactive(): void
    {
        $inactive = $this->admin('gone', status: 'inactive');
        $inactiveLink = $this->pendingLink($inactive->getEmail());

        $expiredOwner = $this->admin('exp');
        $expired = bin2hex(random_bytes(32));
        $this->em->persist(new AdminPasswordResetToken($expiredOwner->getEmail(), hash('sha256', $expired), new \DateTimeImmutable('-1 minute')));
        $this->em->flush();
        $this->em->clear();

        // Replaying a link that was already spent is the classic attack on a reset token: consume one through the gate first.
        $usedOwner = $this->admin('used');
        $used = $this->pendingLink($usedOwner->getEmail());
        self::assertTrue($this->gate->change(AdminPasswordChange::viaResetLink($used, self::NEW, '9.9.9.9'))->isChanged());
        $this->db->executeStatement('DELETE FROM audit_log');

        foreach ([
            'unknown' => ['nope', 'invalid or has already been used'],
            'used' => [$used, 'invalid or has already been used'],
            'expired' => [$expired, 'has expired'],
            'inactive account' => [$inactiveLink, 'no longer valid'],
        ] as $why => [$token, $message]) {
            $result = $this->gate->change(AdminPasswordChange::viaResetLink($token, 'another-Passphrase-77', '9.9.9.9'));
            self::assertSame(PasswordChangeOutcome::Invalid, $result->outcome, $why);
            self::assertStringContainsString($message, (string) $result->message, $why);
        }

        self::assertTrue($this->passwordIs($usedOwner, self::NEW), 'a replayed link changes nothing');
        self::assertFalse($this->passwordIs($inactive, 'another-Passphrase-77'));
        self::assertFalse($this->passwordIs($expiredOwner, 'another-Passphrase-77'));
        self::assertSame([], $this->audit(), 'a refused link writes no audit row');
    }

    // ------------------------------------------------------------------ own password

    public function testOwnChangeWithTheRightCurrentPasswordChangesAuditsAndRevokes(): void
    {
        $me = $this->admin('own');
        $other = $this->seedRevocables($me);

        $result = $this->gate->change(AdminPasswordChange::own($me, 'adminpass', self::NEW, self::NEW, '9.9.9.9'));

        self::assertTrue($result->isChanged());
        self::assertTrue($this->passwordIs($me, self::NEW));
        $this->assertRevoked($me, $other);
        self::assertSame([['actor' => $me->getEmail(), 'action' => 'admin.password_change', 'outcome' => 'success', 'context' => 'self-service']], $this->audit());
    }

    public function testOwnChangeWithTheWrongCurrentPasswordChangesNothingAndAuditsTheFailure(): void
    {
        $me = $this->admin('own');
        $other = $this->seedRevocables($me);

        $result = $this->gate->change(AdminPasswordChange::own($me, 'not-my-password', self::NEW, self::NEW, '9.9.9.9'));

        self::assertSame(PasswordChangeOutcome::Rejected, $result->outcome);
        self::assertStringContainsString('current password is incorrect', (string) $result->message);
        self::assertTrue($this->passwordIs($me, 'adminpass'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ?', [$me->getId()]), 'nothing revoked');
        self::assertNull($this->gate->resetLinkProblem($other), 'the pending link still works');
        self::assertSame('failure', $this->audit()[0]['outcome']);
        self::assertSame('admin.password_change', $this->audit()[0]['action']);
    }

    public function testValidationFailuresAreRejectedWithoutChangingAnything(): void
    {
        $me = $this->admin('own');

        $empty = $this->gate->change(AdminPasswordChange::own($me, 'adminpass', '', '', '9.9.9.9'));
        $mismatch = $this->gate->change(AdminPasswordChange::own($me, 'adminpass', self::NEW, 'something-else', '9.9.9.9'));
        $weak = $this->gate->change(AdminPasswordChange::own($me, 'adminpass', 'x', 'x', '9.9.9.9'));

        self::assertSame(PasswordChangeOutcome::Rejected, $empty->outcome);
        self::assertSame(PasswordChangeOutcome::Rejected, $mismatch->outcome);
        self::assertStringContainsString('do not match', (string) $mismatch->message);
        self::assertSame(PasswordChangeOutcome::Rejected, $weak->outcome, 'the password policy floor applies');
        self::assertTrue($this->passwordIs($me, 'adminpass'));
        self::assertSame([], $this->audit());
    }

    // ------------------------------------------------------------------ tech support

    public function testTechSupportCanSetAnotherAdminsPassword(): void
    {
        $ts = $this->admin('ts', ['ROLE_TECH_SUPPORT']);
        $target = $this->admin('victim');
        $other = $this->seedRevocables($target);

        $result = $this->gate->change(AdminPasswordChange::bySupport($ts, $target, self::NEW, self::NEW, '9.9.9.9'));

        self::assertTrue($result->isChanged());
        self::assertTrue($this->passwordIs($target, self::NEW));
        $this->assertRevoked($target, $other);
        self::assertSame([['actor' => $ts->getEmail(), 'action' => 'admin.password_set', 'outcome' => 'success', 'context' => $target->getEmail()]], $this->audit());
    }

    public function testTechSupportCanSetAnotherTechSupportsPassword(): void
    {
        $ts = $this->admin('ts', ['ROLE_TECH_SUPPORT']);
        $otherTs = $this->admin('ts2', ['ROLE_TECH_SUPPORT']);

        self::assertTrue($this->gate->change(AdminPasswordChange::bySupport($ts, $otherTs, self::NEW, self::NEW, '9.9.9.9'))->isChanged());
    }

    public function testASuperadminCannotSetAnotherAdminsPassword(): void
    {
        $super = $this->admin('super', ['ROLE_SUPER_ADMIN']);
        $target = $this->admin('victim');
        $other = $this->seedRevocables($target);

        $result = $this->gate->change(AdminPasswordChange::bySupport($super, $target, self::NEW, self::NEW, '9.9.9.9'));

        self::assertSame(PasswordChangeOutcome::Forbidden, $result->outcome);
        self::assertTrue($this->passwordIs($target, 'adminpass'));
        self::assertNull($this->gate->resetLinkProblem($other), 'nothing revoked');
        self::assertSame('failure', $this->audit()[0]['outcome']);
        self::assertSame($super->getEmail(), $this->audit()[0]['actor']);
    }

    public function testTechSupportCannotUseTheSupportPathOnTheirOwnAccount(): void
    {
        // Setting your own password this way would skip the current-password proof the own path demands.
        $ts = $this->admin('ts', ['ROLE_TECH_SUPPORT']);

        $result = $this->gate->change(AdminPasswordChange::bySupport($ts, $ts, self::NEW, self::NEW, '9.9.9.9'));

        self::assertSame(PasswordChangeOutcome::Rejected, $result->outcome);
        self::assertTrue($this->passwordIs($ts, 'adminpass'));
    }

    public function testTechSupportCannotSetAnInactiveAdminsPassword(): void
    {
        $ts = $this->admin('ts', ['ROLE_TECH_SUPPORT']);
        $inactive = $this->admin('gone', status: 'inactive');

        $result = $this->gate->change(AdminPasswordChange::bySupport($ts, $inactive, self::NEW, self::NEW, '9.9.9.9'));

        self::assertSame(PasswordChangeOutcome::Invalid, $result->outcome);
        self::assertTrue($this->passwordIs($inactive, 'adminpass'));
    }
}
