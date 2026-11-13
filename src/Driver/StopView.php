<?php

declare(strict_types=1);

namespace App\Driver;

/**
 * What the driver pages show of one Safeway stop (docs/driver-api.md section 3), worked out once in PHP so the
 * Figma cards (Upcoming Destinations, Completed Destinations, Destination Details) read the same fields.
 *
 * Mirrors the safeway-dispatch PWA's wording (public/driver/ui.js, complete.js): the company is ship_to.company_name,
 * else the customer's name; a stop is done once its status is `completed`; failed = "Not delivered", partial =
 * "Arrived with Issue".
 */
final class StopView
{
    public const TYPE_LABELS = [
        'deliver' => 'Delivery',
        'pickup_return' => 'Pickup',
        'collect_payment' => 'Collect payment',
        'reload' => 'Reload',
    ];

    /** The outcomes per task type, in the order the Mark Complete page offers them (complete.js TYPES). */
    public const OUTCOMES = [
        'deliver' => ['delivered', 'partially_delivered', 'rejected', 'wrong_item', 'not_delivered'],
        'pickup_return' => ['picked_up', 'picked_up_with_problem', 'nothing_to_pick_up', 'not_picked_up'],
        'collect_payment' => ['collected', 'partially_collected', 'not_collected'],
        'reload' => ['done', 'not_done'],
        'custom' => ['done', 'not_done'],
    ];

    public const OUTCOME_LABELS = [
        'delivered' => 'Delivered', 'partially_delivered' => 'Partially delivered', 'rejected' => 'Rejected', 'wrong_item' => 'Wrong item',
        'not_delivered' => 'Not delivered', 'picked_up' => 'Picked up', 'picked_up_with_problem' => 'Picked up with a problem',
        'nothing_to_pick_up' => 'Nothing to pick up', 'not_picked_up' => 'Not picked up', 'collected' => 'Collected',
        'partially_collected' => 'Partly paid', 'not_collected' => 'Not collected', 'done' => 'Done', 'not_done' => 'Not done',
    ];

    /** The sync API's reason list (driver-api.md section 8); "other" reads "Other issue" as on the Figma frame. */
    public const REASONS = [
        'customer_closed' => 'Customer closed', 'customer_refused' => 'Customer refused', 'no_one_available' => 'No one available',
        'address_not_found' => 'Address not found', 'no_access' => 'No access', 'damaged' => 'Damaged', 'wrong_item' => 'Wrong item',
        'insufficient_funds' => 'Insufficient funds', 'out_of_time' => 'Out of time', 'vehicle_issue' => 'Vehicle issue', 'other' => 'Other issue',
    ];

    private const MAPS_URL = '#^https://www\.google\.com/maps/dir/\?api=1&destination=[A-Za-z0-9%.,_+~\-]+$#';

    /**
     * @param array<string, mixed> $stop
     *
     * @return array<string, mixed>
     */
    public static function of(array $stop, string $timeZone): array
    {
        $warehouse = ($stop['kind'] ?? '') === 'warehouse';
        $ship = \is_array($stop['ship_to'] ?? null) ? $stop['ship_to'] : [];
        $customer = \is_array($stop['customer'] ?? null) ? $stop['customer'] : [];
        $tasks = \is_array($stop['tasks'] ?? null) ? array_values(array_filter($stop['tasks'], 'is_array')) : [];
        $notes = \is_array($stop['notes'] ?? null) ? array_values(array_filter($stop['notes'], 'is_array')) : [];
        $phone = self::str($ship['phone'] ?? null) ?? self::str($customer['phone'] ?? null);
        $mapsUrl = self::str($stop['maps_url'] ?? null);
        $done = ($stop['status'] ?? '') === 'completed';

        return [
            'id' => (string) ($stop['id'] ?? ''),
            'warehouse' => $warehouse,
            'position' => (int) ($stop['position'] ?? 0),
            'route_ref' => (string) ($stop['route_ref'] ?? ''),
            'company' => $warehouse ? 'Warehouse' : (self::str($ship['company_name'] ?? null) ?? self::str($customer['name'] ?? null) ?? 'Stop'),
            'address' => $warehouse ? (string) ($stop['address'] ?? '') : self::address($ship),
            'maps_url' => $mapsUrl !== null && preg_match(self::MAPS_URL, $mapsUrl) === 1 ? $mapsUrl : null,
            'contact' => self::str($ship['contact_name'] ?? null),
            'phone' => $phone,
            'tel' => $phone !== null && \strlen(preg_replace('/[^\d+]/', '', $phone) ?? '') >= 5 ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : null,
            'instructions' => self::str($stop['instructions'] ?? null),
            'dispatch_notes' => array_values(array_filter($notes, static fn (array $n): bool => !($n['mine'] ?? false))),
            'my_notes' => array_values(array_filter($notes, static fn (array $n): bool => (bool) ($n['mine'] ?? false))),
            'types' => implode(', ', array_unique(array_map(static fn (array $t): string => self::typeLabel((string) ($t['type'] ?? '')), $tasks))),
            'products' => self::products($tasks),
            'tasks' => $tasks,
            'reload' => \is_array($stop['reload'] ?? null) ? self::str($stop['reload']['summary'] ?? null) : null,
            'eta' => self::str($stop['eta'] ?? null),
            'window' => self::window($stop['time_window'] ?? null),
            'rush' => ($stop['priority'] ?? null) === 'rush',
            'driver_state' => self::str($stop['driver_state'] ?? null),
            'next_allowed' => \is_array($stop['next_allowed'] ?? null) ? array_values(array_filter($stop['next_allowed'], 'is_string')) : [],
            'can_undo' => (bool) ($stop['can_undo'] ?? false),
            'done' => $done,
            'status_label' => self::statusLabel($stop, $done),
            'completed_at' => self::time($stop['completed_at'] ?? null, $timeZone),
            'photos' => \is_array($stop['photos'] ?? null) ? array_values(array_filter($stop['photos'], 'is_array')) : [],
            'recipient_name' => self::str($stop['recipient_name'] ?? null),
        ];
    }

    /** Something for the driver to do: every customer stop, and a warehouse stop with a reload task (D-039). */
    public static function isWork(array $view): bool
    {
        return !$view['warehouse'] || $view['tasks'] !== [];
    }

    public static function typeLabel(string $type): string
    {
        if (str_starts_with($type, 'custom:')) {
            return self::humanize(substr($type, 7));
        }

        return self::TYPE_LABELS[$type] ?? self::humanize($type);
    }

    /** @return list<string> the outcomes the Mark Complete page offers for a task type */
    public static function outcomes(string $type): array
    {
        return self::OUTCOMES[$type] ?? self::OUTCOMES['custom'];
    }

    public static function humanize(string $code): string
    {
        return ucfirst(str_replace('_', ' ', $code));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     *
     * @return list<array{name: string, quantity: string}>
     */
    private static function products(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $task) {
            foreach (\is_array($task['items'] ?? null) ? $task['items'] : [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $name = self::str($item['name'] ?? null) ?? self::str($item['sku'] ?? null) ?? (string) ($item['line_ref'] ?? '');
                $out[] = ['name' => $name, 'quantity' => self::qty($item['quantity'] ?? '')];
            }
        }

        return $out;
    }

    private static function qty(mixed $q): string
    {
        $s = (string) $q;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** @param array<string, mixed> $ship */
    private static function address(array $ship): string
    {
        $first = implode(', ', array_filter([self::str($ship['address_1'] ?? null), self::str($ship['address_2'] ?? null)]));

        return implode(', ', array_filter([$first !== '' ? $first : null, self::str($ship['city'] ?? null), self::str($ship['province'] ?? $ship['state'] ?? null)]));
    }

    private static function window(mixed $w): ?string
    {
        if (!\is_array($w) || !\is_string($w['start'] ?? null) || !\is_string($w['end'] ?? null)) {
            return null;
        }

        return self::clock12($w['start']) . ' – ' . self::clock12($w['end']);
    }

    private static function clock12(string $t): string
    {
        if (preg_match('/(\d{1,2}):(\d{2})/', $t, $m) !== 1) {
            return $t;
        }
        $h = (int) $m[1];

        return (($h % 12) ?: 12) . ':' . $m[2] . ' ' . ($h < 12 ? 'AM' : 'PM');
    }

    /** @param array<string, mixed> $stop */
    private static function statusLabel(array $stop, bool $done): string
    {
        if ($done) {
            return match ($stop['result'] ?? null) {
                'failed' => 'Not delivered',
                'partial' => 'Arrived with Issue',
                default => 'Completed',
            };
        }

        return match ($stop['driver_state'] ?? null) {
            'arrived' => 'Arrived',
            'on_my_way' => 'On my way',
            default => '',
        };
    }

    /** "2026-11-13 12:28" in the app's time zone, as the Figma "Date, Time Completed". */
    private static function time(mixed $value, string $timeZone): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            $tz = new \DateTimeZone($timeZone !== '' ? $timeZone : 'UTC');

            return (new \DateTimeImmutable($value))->setTimezone($tz)->format('Y-m-d H:i');
        } catch (\Exception) {
            return null;
        }
    }

    private static function str(mixed $v): ?string
    {
        return \is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
