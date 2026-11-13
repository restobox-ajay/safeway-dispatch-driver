<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Validates a submitted admin-config value against its field declaration
 * (see ConfigPageProviderInterface::getFields). Returns a human-readable error
 * message when the value violates the field's declared type/constraints, or null
 * when the value is acceptable.
 *
 * Supported field types:
 *  - 'int'  : non-negative integer string; optional 'min'/'max' bounds.
 *  - 'enum' : must be one of the declared 'options'.
 *  - 'bool' : canonical '0' or '1' (the controller coerces checkboxes to these).
 *  - 'ip_list': comma-separated IPv4/IPv6 addresses or CIDR ranges; every entry must parse (issue #17 — a
 *              malformed entry used to be stored and then refuse every login). With 'require_client_ip' set,
 *              a non-empty list must also admit the client IP of the admin saving it (lockout guard, mirroring
 *              the Htaccess Lock's).
 *  - 'text' / anything else: free-form, always accepted.
 */
class ConfigFieldValidator
{
    /**
     * @param array{label?: string, type?: string, min?: int, max?: int, options?: array<int, string>, require_client_ip?: bool} $field
     * @param string|null $clientIp the saving admin's IP, for fields with 'require_client_ip' (null = unknown, guard skipped)
     */
    public function validate(array $field, string $value, ?string $clientIp = null): ?string
    {
        $label = $field['label'] ?? 'value';
        $type  = $field['type'] ?? 'text';

        switch ($type) {
            case 'int':
                if (!ctype_digit($value)) {
                    return sprintf('%s must be a non-negative whole number.', $label);
                }
                $int = (int) $value;
                if (isset($field['min']) && $int < $field['min']) {
                    return sprintf('%s must be at least %d.', $label, $field['min']);
                }
                if (isset($field['max']) && $int > $field['max']) {
                    return sprintf('%s must be at most %d.', $label, $field['max']);
                }
                return null;

            case 'enum':
                $options = $field['options'] ?? [];
                if (!in_array($value, $options, true)) {
                    return sprintf('%s must be one of: %s.', $label, implode(', ', $options));
                }
                return null;

            case 'bool':
                if ($value !== '0' && $value !== '1') {
                    return sprintf('%s must be a boolean.', $label);
                }
                return null;

            case 'ip_list':
                return $this->validateIpList($label, $value, ($field['require_client_ip'] ?? false) ? $clientIp : null);

            default:
                return null;
        }
    }

    /** The same split the IP-whitelist listener uses (commas, entries trimmed, blanks dropped). */
    private function validateIpList(string $label, string $value, ?string $mustAdmit): ?string
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $e): bool => $e !== ''));

        foreach ($entries as $entry) {
            if (!$this->isIpOrCidr($entry)) {
                return sprintf('%s: "%s" is not an IPv4/IPv6 address or CIDR range. Separate entries with commas.', $label, mb_substr($entry, 0, 60));
            }
        }

        if ($mustAdmit !== null && $entries !== [] && !IpUtils::checkIp($mustAdmit, $entries)) {
            return sprintf('%s: your current IP (%s) is not in this list. Add it first, or saving would lock you (and every admin not on the list) out.', $label, $mustAdmit);
        }

        return null;
    }

    private function isIpOrCidr(string $entry): bool
    {
        $address = $entry;
        $prefix = null;
        if (str_contains($entry, '/')) {
            [$address, $prefix] = explode('/', $entry, 2);
            // Canonical prefixes only: IpUtils compares '0' literally, so '::/00' would be stored but match nothing.
            if (preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) !== 1) {
                return false;
            }
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix === null || (int) $prefix <= 32;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix === null || (int) $prefix <= 128;
        }

        return false;
    }
}
