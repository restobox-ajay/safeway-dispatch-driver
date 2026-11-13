<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Single source of truth for turning an audit-log filter request into a
 * validated filter array. Shared by the web (AdminAuditLogController) and the
 * API (Api\AdminApiAuditLogController) so the parsing/validation logic lives in
 * exactly one place.
 *
 * Date inputs are validated strictly (YYYY-MM-DD). An invalid date is dropped
 * from the filter set and reported via AuditLogFilterResult::$error, so the
 * repository never receives an unparseable string (which previously threw and
 * produced an HTTP 500 / DateTimeImmutable::__construct failure).
 */
final class AuditLogFilterParser
{
    private const DATE_FORMAT = 'Y-m-d';

    public function parse(Request $request): AuditLogFilterResult
    {
        $filters = [];
        $errors  = [];

        $actor = trim((string) $request->query->get('actor', ''));
        if ($actor !== '') {
            $filters['actor'] = $actor;
        }

        $action = trim((string) $request->query->get('action', ''));
        if ($action !== '') {
            $filters['action'] = $action;
        }

        $dateFrom = trim((string) $request->query->get('date_from', ''));
        if ($dateFrom !== '') {
            $parsed = $this->parseDate($dateFrom, 0, 0, 0);
            if ($parsed === null) {
                $errors[] = \sprintf('Invalid "date_from" value "%s"; expected format YYYY-MM-DD.', $dateFrom);
            } else {
                $filters['date_from'] = $parsed;
            }
        }

        $dateTo = trim((string) $request->query->get('date_to', ''));
        if ($dateTo !== '') {
            $parsed = $this->parseDate($dateTo, 23, 59, 59);
            if ($parsed === null) {
                $errors[] = \sprintf('Invalid "date_to" value "%s"; expected format YYYY-MM-DD.', $dateTo);
            } else {
                $filters['date_to'] = $parsed;
            }
        }

        return new AuditLogFilterResult($filters, $errors === [] ? null : implode(' ', $errors));
    }

    /**
     * Strictly parse a YYYY-MM-DD string, anchoring it to the given time of day.
     * Returns null for any malformed or non-existent date (e.g. "banana",
     * "2026-13-45", "2026-02-30").
     */
    private function parseDate(string $value, int $hour, int $minute, int $second): ?\DateTimeImmutable
    {
        $date   = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false) {
            return null;
        }

        if (\is_array($errors) && ($errors['error_count'] > 0 || $errors['warning_count'] > 0)) {
            return null;
        }

        return $date->setTime($hour, $minute, $second);
    }
}
