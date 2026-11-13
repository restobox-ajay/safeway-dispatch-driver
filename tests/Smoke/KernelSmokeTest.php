<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelSmokeTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();

        $this->assertNotNull(self::$kernel);
        $this->assertSame('test', self::$kernel->getEnvironment());
    }

    public function testRequiredBundlesAreRegistered(): void
    {
        self::bootKernel();

        $bundles = self::$kernel->getBundles();
        $bundleClasses = array_map(
            static fn ($b) => $b::class,
            $bundles
        );

        $this->assertContains('Symfony\Bundle\FrameworkBundle\FrameworkBundle', $bundleClasses);
        $this->assertContains('Doctrine\Bundle\DoctrineBundle\DoctrineBundle', $bundleClasses);
        $this->assertContains('Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle', $bundleClasses);
        $this->assertContains('Symfony\Bundle\SecurityBundle\SecurityBundle', $bundleClasses);
        $this->assertContains('SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle', $bundleClasses);
    }
}
