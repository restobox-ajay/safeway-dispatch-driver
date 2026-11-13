<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AuditLogFilterParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuditLogFilterParserTest extends TestCase
{
    private AuditLogFilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AuditLogFilterParser();
    }

    private function parse(array $query): \App\Service\AuditLogFilterResult
    {
        return $this->parser->parse(new Request($query));
    }

    public function testEmptyQueryYieldsNoFiltersNoError(): void
    {
        $result = $this->parse([]);

        $this->assertSame([], $result->filters);
        $this->assertTrue($result->isValid());
        $this->assertNull($result->error);
    }

    public function testActorAndActionArePassedThroughTrimmed(): void
    {
        $result = $this->parse(['actor' => '  alice  ', 'action' => ' login ']);

        $this->assertSame('alice', $result->filters['actor']);
        $this->assertSame('login', $result->filters['action']);
        $this->assertTrue($result->isValid());
    }

    public function testBlankActorAndActionAreOmitted(): void
    {
        $result = $this->parse(['actor' => '   ', 'action' => '']);

        $this->assertArrayNotHasKey('actor', $result->filters);
        $this->assertArrayNotHasKey('action', $result->filters);
        $this->assertTrue($result->isValid());
    }

    public function testValidDatesBecomeDateTimeImmutableAnchoredToDayBounds(): void
    {
        $result = $this->parse(['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);

        $this->assertTrue($result->isValid());

        $from = $result->filters['date_from'];
        $to   = $result->filters['date_to'];
        $this->assertInstanceOf(\DateTimeImmutable::class, $from);
        $this->assertInstanceOf(\DateTimeImmutable::class, $to);
        $this->assertSame('2026-01-01 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 23:59:59', $to->format('Y-m-d H:i:s'));
    }

    public function testNonNumericDateIsRejectedAndOmitted(): void
    {
        $result = $this->parse(['date_from' => 'banana']);

        $this->assertFalse($result->isValid());
        $this->assertArrayNotHasKey('date_from', $result->filters);
        $this->assertStringContainsString('date_from', (string) $result->error);
    }

    public function testImpossibleCalendarDateIsRejected(): void
    {
        // Feb 30 does not exist; strict parsing must reject it (not roll over to March).
        $result = $this->parse(['date_to' => '2026-02-30']);

        $this->assertFalse($result->isValid());
        $this->assertArrayNotHasKey('date_to', $result->filters);
    }

    public function testOutOfRangeMonthIsRejected(): void
    {
        $result = $this->parse(['date_from' => '2026-13-45']);

        $this->assertFalse($result->isValid());
        $this->assertArrayNotHasKey('date_from', $result->filters);
    }

    public function testValidDateWithExtraTimeComponentIsRejectedByStrictFormat(): void
    {
        // Only bare YYYY-MM-DD is accepted; a trailing time is a warning under strict parse.
        $result = $this->parse(['date_from' => '2026-01-01 12:00:00']);

        $this->assertFalse($result->isValid());
        $this->assertArrayNotHasKey('date_from', $result->filters);
    }

    public function testValidActorSurvivesAlongsideInvalidDate(): void
    {
        $result = $this->parse(['actor' => 'alice', 'date_from' => 'banana']);

        // The good filter is still applied; only the bad date is dropped + reported.
        $this->assertSame('alice', $result->filters['actor']);
        $this->assertArrayNotHasKey('date_from', $result->filters);
        $this->assertFalse($result->isValid());
    }
}
