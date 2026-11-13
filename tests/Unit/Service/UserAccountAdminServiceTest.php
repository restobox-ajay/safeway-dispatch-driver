<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\IpWhitelistManagerInterface;
use App\Security\PasswordPolicyManagerInterface;
use App\Security\RecoveryTokenInvalidator;
use App\Security\UserTokenRevokerInterface;
use App\Service\AuditLogger;
use App\Service\NullMagicLinkTokenMaintainer;
use App\Service\UserAccountAdminService;
use App\Service\UserFieldValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unit test for the single UserAccountAdminService (FEATURE-103 AC1): the one validation path,
 * one audit path, and password-lifecycle bookkeeping shared by the web AdminUserController and the
 * API AdminApiUserController user-write actions.
 *
 * RecoveryTokenInvalidator and UserFieldValidator are final (unmockable); UserFieldValidator is
 * pure so a real instance is used, and the (DB-bound) invalidator is only reached by the
 * delete/deactivate/status-inactive paths, which the functional suite covers — these unit tests
 * exercise the create/update validation, audit, and lifecycle-seed paths that never touch it.
 *
 * Collaborators default to stubs (canned returns); a test upgrades one to a full mock only when it
 * asserts an interaction on it (PHPUnit 12 flags a mock created without expectations).
 */
final class UserAccountAdminServiceTest extends TestCase
{
    /** @var UserRepository&\PHPUnit\Framework\MockObject\Stub */
    private UserRepository $userRepository;
    /** @var UserPasswordHasherInterface&\PHPUnit\Framework\MockObject\Stub */
    private UserPasswordHasherInterface $passwordHasher;
    /** @var PasswordPolicyManagerInterface&\PHPUnit\Framework\MockObject\Stub */
    private PasswordPolicyManagerInterface $passwordPolicy;
    /** @var IpWhitelistManagerInterface&\PHPUnit\Framework\MockObject\Stub */
    private IpWhitelistManagerInterface $ipWhitelist;

    private EntityManagerInterface $em;
    private AuditLogger $auditLogger;
    private UserSessionRepository $sessionRepository;
    private UserTokenRevokerInterface $tokenRevoker;

    protected function setUp(): void
    {
        // Pure stubs by default; individual tests replace the ones they assert on with full mocks.
        $this->userRepository    = $this->createStub(UserRepository::class);
        $this->passwordHasher    = $this->createStub(UserPasswordHasherInterface::class);
        $this->passwordPolicy    = $this->createStub(PasswordPolicyManagerInterface::class);
        $this->ipWhitelist       = $this->createStub(IpWhitelistManagerInterface::class);
        $this->em                = $this->createStub(EntityManagerInterface::class);
        $this->auditLogger       = $this->createStub(AuditLogger::class);
        $this->sessionRepository = $this->createStub(UserSessionRepository::class);
        $this->tokenRevoker      = $this->createStub(UserTokenRevokerInterface::class);
    }

    private function service(): UserAccountAdminService
    {
        return new UserAccountAdminService(
            $this->em,
            $this->userRepository,
            $this->passwordHasher,
            $this->passwordPolicy,
            $this->ipWhitelist,
            new UserFieldValidator(),
            new RecoveryTokenInvalidator($this->createStub(EntityManagerInterface::class), new NullMagicLinkTokenMaintainer()),
            $this->auditLogger,
            $this->sessionRepository,
            $this->tokenRevoker,
        );
    }

    public function testCreateValidatesPersistsHashesStampsTimeAndSeedsHistory(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->passwordHasher->method('hashPassword')->willReturn('HASHED');

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (object $u) use (&$persisted): bool {
                $persisted = $u;
                return $u instanceof User;
            }));
        $this->em = $em;

        // The 'success' audit row is deferred INSIDE the transaction (atomic with the INSERT).
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logDeferred')
            ->with('admin@example.com', 'admin', '10.0.0.1', 'admin.user_create', 'success');
        $auditLogger->expects($this->never())->method('log');
        $this->auditLogger = $auditLogger;

        // The password change is recorded through the port with the freshly hashed password (AC1 fold-in):
        // this stamps the change time and seeds password history in one call (FEATURE-145).
        $passwordPolicy = $this->createMock(PasswordPolicyManagerInterface::class);
        $passwordPolicy->method('validate')->willReturn([]);
        $passwordPolicy->expects($this->once())
            ->method('recordPasswordChange')
            ->with($this->isInstanceOf(User::class), 'HASHED');
        $this->passwordPolicy = $passwordPolicy;

        $result = $this->service()->create([
            'email'    => 'new@example.com',
            'name'     => 'New User',
            'password' => 'password123',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertTrue($result->isSuccess());
        $this->assertInstanceOf(User::class, $result->user);
        $this->assertSame($persisted, $result->user);
        $this->assertSame('new@example.com', $result->user->getEmail());
        $this->assertSame('New User', $result->user->getName());
        $this->assertSame('HASHED', $result->user->getPassword());
        $this->assertSame('active', $result->user->getStatus());
        // The change-time stamp now lives in the auth-password-policy-bundle satellite (password_meta),
        // recorded via recordPasswordChange() asserted above — no longer a column on User (FEATURE-145).
        // Roles stay fixed to ROLE_USER (ADR-024).
        $this->assertSame(['ROLE_USER'], $result->user->getRoles());
    }

    public function testCreateWithWeakPasswordReturnsErrorsAndPersistsNothing(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('wrapInTransaction');
        $this->em = $em;

        $passwordPolicy = $this->createMock(PasswordPolicyManagerInterface::class);
        $passwordPolicy->method('validate')->willReturn(['Password must be at least 8 characters.']);
        $passwordPolicy->expects($this->never())->method('recordPasswordChange');
        $this->passwordPolicy = $passwordPolicy;

        $result = $this->service()->create([
            'email'    => 'weak@example.com',
            'name'     => 'Weak',
            'password' => 'short',
            'role'     => 'ROLE_USER',
            'status'   => 'active',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->user);
        $this->assertSame('Password must be at least 8 characters.', $result->errors['password']);
    }

    public function testCreateWithDuplicateEmailReturnsErrorAndPersistsNothing(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(new User());
        $this->passwordPolicy->method('validate')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('wrapInTransaction');
        $this->em = $em;

        $result = $this->service()->create([
            'email'    => 'dup@example.com',
            'name'     => 'Dup',
            'password' => 'password123',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('This email address is already registered.', $result->errors['email']);
    }

    public function testCreateWithUnknownStatusReturnsError(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->passwordPolicy->method('validate')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('wrapInTransaction');
        $this->em = $em;

        $result = $this->service()->create([
            'email'    => 'bad-status@example.com',
            'name'     => 'Bad Status',
            'password' => 'password123',
            'status'   => 'banished',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertFalse($result->isSuccess());
        $this->assertArrayHasKey('status', $result->errors);
    }

    public function testUpdateRejectsUnknownRoleAndStatusWithoutAuditing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('wrapInTransaction');
        $this->em = $em;

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->never())->method('logDeferred');
        $this->auditLogger = $auditLogger;

        $user = new User();
        $user->setEmail('u@example.com');
        $user->setName('U');

        $result = $this->service()->update($user, [
            'role'   => 'ROLE_SUPER_ADMIN',
            'status' => 'nonsense',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertFalse($result->isSuccess());
        $this->assertArrayHasKey('role', $result->errors);
        $this->assertArrayHasKey('status', $result->errors);
    }

    public function testUpdateAppliesFieldsAndAuditsWithinTransaction(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        // A stub (not a mock) for the EM: we assert on the audit interaction, not on the EM, and
        // the stub still runs the transaction callback so the deferred audit fires.
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $this->em = $em;

        // Editing a user WITHOUT touching status logs one deferred edit row and does not deactivate. The row names
        // the target and what changed (issue #46).
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logDeferred')
            ->with('admin@example.com', 'admin', '10.0.0.1', 'admin.user_edit', 'success',
                'renamed@example.com; email: old@example.com → renamed@example.com; name changed');
        $this->auditLogger = $auditLogger;

        $user = new User();
        $user->setEmail('old@example.com');
        $user->setName('Old Name');

        $result = $this->service()->update($user, [
            'email' => 'renamed@example.com',
            'name'  => 'New Name',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('renamed@example.com', $user->getEmail());
        $this->assertSame('New Name', $user->getName());
    }

    // The per-user IP-whitelist override is not a User column any more (FEATURE-146): a present allowed_ips
    // key must be routed to the IpWhitelistManagerInterface port (the auth-ip-whitelist-bundle satellite),
    // normalising the blank string the web form submits to null (= clear the override).
    public function testUpdateRoutesAllowedIpsToTheIpWhitelistPort(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $this->em = $em;

        $user = new User();
        $user->setEmail('ips@example.com');
        $user->setName('IPs User');

        $ipWhitelist = $this->createMock(IpWhitelistManagerInterface::class);
        $ipWhitelist->expects($this->once())
            ->method('setAllowedIps')
            ->with($user, '203.0.113.4, 198.51.100.0/24');
        $this->ipWhitelist = $ipWhitelist;

        $result = $this->service()->update($user, [
            'email'       => 'ips@example.com',
            'name'        => 'IPs User',
            'allowed_ips' => '203.0.113.4, 198.51.100.0/24',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertTrue($result->isSuccess());
    }

    // A blank allowed_ips submission (the web form default) is normalised to null before the port call,
    // clearing any existing override.
    public function testUpdateWithBlankAllowedIpsClearsTheOverrideViaThePort(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $this->em = $em;

        $user = new User();
        $user->setEmail('ips@example.com');
        $user->setName('IPs User');

        $ipWhitelist = $this->createMock(IpWhitelistManagerInterface::class);
        $ipWhitelist->expects($this->once())
            ->method('setAllowedIps')
            ->with($user, null);
        $this->ipWhitelist = $ipWhitelist;

        $result = $this->service()->update($user, [
            'email'       => 'ips@example.com',
            'name'        => 'IPs User',
            'allowed_ips' => '   ',
        ], 'admin@example.com', '10.0.0.1');

        $this->assertTrue($result->isSuccess());
    }
}
