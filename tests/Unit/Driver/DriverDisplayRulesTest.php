<?php

declare(strict_types=1);

namespace App\Tests\Unit\Driver;

use App\Controller\Driver\DriverApiProxyController;
use App\Controller\Driver\DriverStopController;
use App\Driver\RouteMapLayout;
use App\Twig\DriverFormatExtension as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The driver web app's display rules, each the same as the Android app's (ui/util/Format.kt, TaskCard,
 * StopState.isCompleted, StatusStepper, RouteMapView) — FEATURE-195/196.
 */
final class DriverDisplayRulesTest extends TestCase
{
    /** @return iterable<array{?string, string}> */
    public static function times(): iterable
    {
        yield ['09:05', '9:05 AM'];
        yield ['13:00', '1:00 PM'];
        yield ['00:30', '12:30 AM'];
        yield ['12:00', '12:00 PM'];
        yield ['2026-10-14T10:05:00-07:00', '10:05 AM'];
        yield ['soon', 'soon'];
        yield [null, ''];
    }

    #[DataProvider('times')]
    public function testTimeLabels(?string $in, string $out): void
    {
        $this->assertSame($out, F::time($in));
    }

    public function testWindowLabels(): void
    {
        $this->assertSame('9:00 AM – 11:00 AM', F::window(['start' => '09:00', 'end' => '11:00']));
        $this->assertSame('By 11:00 AM', F::window(['start' => null, 'end' => '11:00']));
        $this->assertSame('9:00 AM', F::window(['start' => '09:00', 'end' => null]));
        $this->assertSame('', F::window(null));
    }

    public function testTaskCardTime(): void
    {
        $this->assertSame('By 11:00 AM', F::cardTime(['leg' => 'pickup', 'window' => ['start' => '09:00', 'end' => '11:00']]));
        $this->assertSame('1:30 PM', F::cardTime(['leg' => 'dropoff', 'window' => ['start' => '13:00', 'end' => '13:30']]));
        $this->assertSame('1:00 PM', F::cardTime(['leg' => 'dropoff', 'window' => ['start' => '13:00', 'end' => null]]));
        $this->assertSame('Completed 10:05 AM', F::cardTime(['leg' => 'dropoff', 'window' => ['start' => '13:00'], 'completed_at' => '2026-10-14T10:05:00-07:00']));
    }

    public function testMinutes(): void
    {
        $this->assertSame('3h 34m', F::minutes(214));
        $this->assertSame('0h 05m', F::minutes(5));
        $this->assertSame('0h 00m', F::minutes(null));
    }

    public function testBreakMinutes(): void
    {
        $this->assertSame(30, F::breakMinutes('12:00', '12:30'));
        $this->assertSame(8, F::breakMinutes('2026-10-14T13:26:00-07:00', '2026-10-14T13:34:00-07:00'));
        $this->assertSame(20, F::breakMinutes('23:50', '00:10'), 'a break over midnight');
        $this->assertSame(0, F::breakMinutes('12:00', null));
        $this->assertSame(0, F::breakMinutes('soon', '12:30'));
    }

    public function testDayBarPlacesTheWindowAndNowOnTheDay(): void
    {
        $bar = F::dayBar('12:00', '18:00', new \DateTimeImmutable('2026-10-14 06:00'));

        $this->assertSame(['left' => 50.0, 'width' => 25.0, 'now' => 25.0], $bar);
        $this->assertNull(F::dayBar(null, null));
        $this->assertSame(68.75, F::dayBar(null, '17:00', new \DateTimeImmutable('2026-10-14 00:00'))['left'], 'only an end: a 30 min band before it');
    }

    public function testAStopIsFinishedAsOnAndroid(): void
    {
        $this->assertFalse(DriverStopController::isCompleted(true, 'picking_up'));
        $this->assertTrue(DriverStopController::isCompleted(true, 'picked_up'), 'a pickup is done once picked up');
        $this->assertFalse(DriverStopController::isCompleted(false, 'picked_up'), 'a drop-off is not');
        $this->assertTrue(DriverStopController::isCompleted(false, 'delivered'));
        $this->assertTrue(DriverStopController::isCompleted(false, 'failed'));
        $this->assertTrue(DriverStopController::isCompleted(true, 'cancelled'));
    }

    public function testTheStepperMarksDoneCurrentAndAhead(): void
    {
        $states = static fn (array $steps): array => array_map(static fn (array $s): string => $s['status'] . ':' . $s['state'], $steps);

        $this->assertSame(['assigned:done', 'picking_up:ahead', 'picked_up:ahead'], $states(DriverStopController::steps(true, 'assigned')));
        $this->assertSame(['assigned:done', 'picking_up:current', 'picked_up:ahead'], $states(DriverStopController::steps(true, 'picking_up')));
        $this->assertSame(['picked_up:done', 'in_transit:current', 'delivered:ahead'], $states(DriverStopController::steps(false, 'in_transit')));
        $this->assertSame(['picked_up:done', 'in_transit:done', 'failed:done'], $states(DriverStopController::steps(false, 'failed')));
        // A drop-off whose orders are not picked up yet: nothing on its stepper is done.
        $this->assertSame(['picked_up:ahead', 'in_transit:ahead', 'delivered:ahead'], $states(DriverStopController::steps(false, 'assigned')));
        $this->assertSame(['picked_up:ahead', 'in_transit:ahead', 'delivered:ahead'], $states(DriverStopController::steps(false, 'picking_up')));
    }

    public function testTheRouteMapFitsPinsAndTheDriverIntoThePanel(): void
    {
        $map = RouteMapLayout::layout([
            ['seq' => 1, 'latitude' => 49.0, 'longitude' => -123.0],
            ['seq' => 2, 'latitude' => 50.0, 'longitude' => -122.0],
            ['seq' => 3, 'latitude' => null, 'longitude' => null],
        ], ['latitude' => 49.5, 'longitude' => -122.5]);

        $this->assertSame([10.0, 90.0], [$map['pins'][0]['left'], $map['pins'][0]['top']], 'south-west corner');
        $this->assertSame([90.0, 10.0], [$map['pins'][1]['left'], $map['pins'][1]['top']], 'north-east corner (north up)');
        $this->assertSame(['left' => 50.0, 'top' => 50.0], $map['me']);
        $this->assertSame(3, $map['unplaced'][0]['seq']);
    }

    public function testWithoutCoordinatesEverythingIsListed(): void
    {
        $map = RouteMapLayout::layout([['seq' => 1, 'latitude' => null, 'longitude' => null]], ['latitude' => 49.0, 'longitude' => -123.0]);

        $this->assertSame([], $map['pins']);
        $this->assertNull($map['me']);
        $this->assertCount(1, $map['unplaced']);
    }

    public function testThePassThroughAllowsTheAndroidCallsOnly(): void
    {
        $this->assertTrue(DriverApiProxyController::allowed('POST', 'stops/P-118/picked-up'));
        $this->assertTrue(DriverApiProxyController::allowed('POST', 'presence/history'));
        $this->assertTrue(DriverApiProxyController::allowed('PUT', 'messages/31/star'));
        $this->assertTrue(DriverApiProxyController::allowed('DELETE', 'photos/pho_a1b2c3'));
        $this->assertFalse(DriverApiProxyController::allowed('POST', 'auth/refresh'), 'tokens never reach the browser');
        $this->assertFalse(DriverApiProxyController::allowed('GET', 'stops/P-118/../../auth'));
        $this->assertFalse(DriverApiProxyController::allowed('POST', 'stops/X-1/delivered'));
        $this->assertFalse(DriverApiProxyController::allowed('PATCH', 'messages/1/star'));
        $this->assertFalse(DriverApiProxyController::allowed('GET', "me\n"), 'a trailing newline is not the same path');
    }
}
