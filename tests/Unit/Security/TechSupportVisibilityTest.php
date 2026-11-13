<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Admin;
use App\Security\TechSupportVisibility;
use PHPUnit\Framework\TestCase;

/**
 * The ADR-050 visibility rule: tech-support admins see everyone; everyone else never sees
 * a tech-support admin. Also pins that ROLE_TECH_SUPPORT survives the Admin::setRoles()
 * allowlist (it is a real ALLOWED_ROLES member, not a silently-dropped string).
 */
final class TechSupportVisibilityTest extends TestCase
{
    private TechSupportVisibility $visibility;

    protected function setUp(): void
    {
        $this->visibility = new TechSupportVisibility();
    }

    private function admin(array $roles): Admin
    {
        $admin = new Admin();
        $admin->setEmail(uniqid('ts-vis-', true) . '@example.com');
        $admin->setName('Test');
        $admin->setPassword('irrelevant');
        $admin->setRoles($roles);

        return $admin;
    }

    public function testRoleSurvivesTheSetRolesAllowlist(): void
    {
        $tech = $this->admin(['ROLE_TECH_SUPPORT']);
        self::assertContains('ROLE_TECH_SUPPORT', $tech->getRoles());
    }

    public function testIsTechSupport(): void
    {
        self::assertTrue($this->visibility->isTechSupport($this->admin(['ROLE_TECH_SUPPORT'])));
        self::assertFalse($this->visibility->isTechSupport($this->admin(['ROLE_SUPER_ADMIN'])));
        self::assertFalse($this->visibility->isTechSupport($this->admin(['ROLE_ADMIN'])));
    }

    public function testSuperadminCannotSeeTechSupport(): void
    {
        $super = $this->admin(['ROLE_SUPER_ADMIN']);
        $tech  = $this->admin(['ROLE_TECH_SUPPORT']);

        self::assertFalse($this->visibility->canSee($super, $tech));
    }

    public function testTechSupportSeesEveryoneIncludingEachOther(): void
    {
        $tech      = $this->admin(['ROLE_TECH_SUPPORT']);
        $otherTech = $this->admin(['ROLE_TECH_SUPPORT']);
        $super     = $this->admin(['ROLE_SUPER_ADMIN']);
        $plain     = $this->admin(['ROLE_ADMIN']);

        self::assertTrue($this->visibility->canSee($tech, $otherTech));
        self::assertTrue($this->visibility->canSee($tech, $super));
        self::assertTrue($this->visibility->canSee($tech, $plain));
    }

    public function testNonTechAdminsSeeEachOtherNormally(): void
    {
        $super = $this->admin(['ROLE_SUPER_ADMIN']);
        $plain = $this->admin(['ROLE_ADMIN']);

        self::assertTrue($this->visibility->canSee($super, $plain));
        self::assertTrue($this->visibility->canSee($plain, $super));
    }

    public function testFilterVisibleStripsTechSupportForNonTechViewer(): void
    {
        $super = $this->admin(['ROLE_SUPER_ADMIN']);
        $tech  = $this->admin(['ROLE_TECH_SUPPORT']);
        $plain = $this->admin(['ROLE_ADMIN']);

        $forSuper = $this->visibility->filterVisible($super, [$super, $tech, $plain]);
        self::assertSame([$super, $plain], $forSuper);

        $forTech = $this->visibility->filterVisible($tech, [$super, $tech, $plain]);
        self::assertSame([$super, $tech, $plain], $forTech);
    }
}
