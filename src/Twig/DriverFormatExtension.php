<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Display helpers for the driver web app, worded like the Android app's ui/util/Format.kt.
 */
final class DriverFormatExtension
{
    /** "13:05" or an ISO date-time → "1:05 PM"; anything else unchanged. */
    #[AsTwigFilter('driver_time')]
    public static function time(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) === 1) {
            return self::clock((int) $m[1], (int) $m[2]);
        }
        try {
            $t = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        return self::clock((int) $t->format('G'), (int) $t->format('i'));
    }

    /** A stop's window: "9:00 AM – 11:00 AM", "By 11:00 AM", or the start alone. */
    #[AsTwigFilter('driver_window')]
    public static function window(?array $window): string
    {
        $start = $window['start'] ?? null;
        $end = $window['end'] ?? null;

        return match (true) {
            $start !== null && $end !== null => self::time($start) . ' – ' . self::time($end),
            $end !== null => 'By ' . self::time($end),
            default => self::time($start),
        };
    }

    /**
     * The time a task card shows (TasksScreen.TaskCard): "Completed 10:05 AM" when done; a pickup "By <end>";
     * a drop-off its end (or start).
     *
     * @param array<string, mixed> $stop
     */
    #[AsTwigFilter('driver_card_time')]
    public static function cardTime(array $stop): string
    {
        $start = $stop['window']['start'] ?? null;
        $end = $stop['window']['end'] ?? null;
        if (\is_string($stop['completed_at'] ?? null)) {
            return 'Completed ' . self::time($stop['completed_at']);
        }
        if (($stop['leg'] ?? '') === 'pickup') {
            return $end !== null ? 'By ' . self::time($end) : self::time($start);
        }
        // A drop-off is due at its tier deadline, the same time Dispatch shows (GitHub #12); the window is shown apart.
        if (\is_string($stop['deadline'] ?? null)) {
            return 'Due by ' . self::time($stop['deadline']);
        }

        return self::time($end ?? $start);
    }

    /** A break's length in whole minutes, from two times the API sends ("13:05" or ISO); 0 if unreadable. */
    #[AsTwigFunction('driver_break_minutes')]
    public static function breakMinutes(?string $start, ?string $end): int
    {
        $a = self::clockMinute($start);
        $b = self::clockMinute($end);
        if ($a === null || $b === null) {
            return 0;
        }

        return $b >= $a ? $b - $a : $b + 1440 - $a;
    }

    private static function clockMinute(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        try {
            $t = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return (int) $t->format('G') * 60 + (int) $t->format('i');
    }

    /** 214 → "3h 34m". */
    #[AsTwigFilter('driver_minutes')]
    public static function minutes(int|float|null $minutes): string
    {
        $m = (int) ($minutes ?? 0);

        return sprintf('%dh %02dm', intdiv($m, 60), $m % 60);
    }

    /**
     * The day-graph bar under a task card: where the window sits in the 24 h day, and where "now" is, as
     * CSS percentages. Null parts are left out of the bar.
     *
     * @return array{left: float, width: float, now: float}|null
     */
    #[AsTwigFunction('driver_day_bar')]
    public static function dayBar(?string $start, ?string $end, ?\DateTimeInterface $now = null): ?array
    {
        $s = self::minuteOfDay($start);
        $e = self::minuteOfDay($end);
        if ($s === null && $e === null) {
            return null;
        }
        $s ??= max(0, $e - 30);
        $e ??= min(1440, $s + 30);
        $now ??= new \DateTimeImmutable();
        $n = (int) $now->format('G') * 60 + (int) $now->format('i');

        return [
            'left' => round($s / 1440 * 100, 2),
            'width' => round(max(1, $e - $s) / 1440 * 100, 2),
            'now' => round($n / 1440 * 100, 2),
        ];
    }

    private static function minuteOfDay(?string $hm): ?int
    {
        if ($hm === null || preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m) !== 1) {
            return null;
        }

        return min(1440, (int) $m[1] * 60 + (int) $m[2]);
    }

    private static function clock(int $hour, int $minute): string
    {
        return sprintf('%d:%02d %s', $hour % 12 === 0 ? 12 : $hour % 12, $minute, $hour < 12 ? 'AM' : 'PM');
    }
}
