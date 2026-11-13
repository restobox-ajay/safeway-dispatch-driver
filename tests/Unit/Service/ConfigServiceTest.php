<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase
{
    private ConfigRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $em;
    private ConfigService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ConfigRepository::class);
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->service    = new ConfigService($this->repository, $this->em);
    }

    // --- getString ---

    public function testGetStringReturnsDefaultWhenKeyNotFound(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertSame('my-default', $this->service->getString('missing.key', 'my-default'));
    }

    public function testGetStringReturnsEmptyStringDefaultByDefault(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertSame('', $this->service->getString('missing.key'));
    }

    public function testGetStringReturnsStoredValue(): void
    {
        $config = (new Config())->setKey('some.key')->setValue('stored-value');
        $this->repository->method('findByKey')->with('some.key')->willReturn($config);
        $this->assertSame('stored-value', $this->service->getString('some.key', 'default'));
    }

    // --- getBool ---

    public function testGetBoolReturnsFalseByDefault(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertFalse($this->service->getBool('missing.key'));
    }

    public function testGetBoolReturnsTrueDefaultWhenPassedTrue(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertTrue($this->service->getBool('missing.key', true));
    }

    public function testGetBoolReturnsTrueForStoredOne(): void
    {
        $config = (new Config())->setKey('bool.key')->setValue('1');
        $this->repository->method('findByKey')->willReturn($config);
        $this->assertTrue($this->service->getBool('bool.key'));
    }

    public function testGetBoolReturnsFalseForStoredZero(): void
    {
        $config = (new Config())->setKey('bool.key')->setValue('0');
        $this->repository->method('findByKey')->willReturn($config);
        $this->assertFalse($this->service->getBool('bool.key', true));
    }

    // --- getInt ---

    public function testGetIntReturnsDefaultWhenKeyNotFound(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertSame(42, $this->service->getInt('missing.key', 42));
    }

    public function testGetIntReturnsZeroByDefault(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->assertSame(0, $this->service->getInt('missing.key'));
    }

    public function testGetIntReturnsStoredValue(): void
    {
        $config = (new Config())->setKey('int.key')->setValue('100');
        $this->repository->method('findByKey')->willReturn($config);
        $this->assertSame(100, $this->service->getInt('int.key'));
    }

    // --- set ---

    public function testSetPersistsNewConfigEntry(): void
    {
        $this->repository->method('findByKey')->willReturn(null);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Config::class));
        $this->em->expects($this->once())->method('flush');
        $this->service->set('new.key', 'new-value');
    }

    public function testSetUpdatesExistingConfigEntryWithoutPersist(): void
    {
        $config = (new Config())->setKey('existing.key')->setValue('old-value');
        $this->repository->method('findByKey')->willReturn($config);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');
        $this->service->set('existing.key', 'new-value');
        $this->assertSame('new-value', $config->getValue());
    }
}
