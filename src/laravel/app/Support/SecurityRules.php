<?php

namespace App\Support;

use App\Models\SecurityEvent;

/**
 * The rule language of the security scanner: a rule (JSON) says which list of the device's
 * security inventory it looks at (source), when an item of it is a finding (when: conditions,
 * nested with all / any / not) and how the finding reads (message with {Field} placeholders).
 *
 *   {
 *     "key": "remote-access-anydesk",
 *     "name": "AnyDesk is installed",
 *     "severity": "medium",
 *     "platform": "windows",
 *     "source": "software",
 *     "when": {"any": [{"field": "Name", "op": "contains", "value": "anydesk"}]},
 *     "message": "{Name} {Version} is installed",
 *     "remediation": "Uninstall it unless it is used on purpose.",
 *     "urls": ["https://attack.mitre.org/techniques/T1219/"]
 *   }
 *
 * Every matching item is its own finding (identified by the key fields of its source), unless
 * "threshold": N makes one finding of the device when at least N items match. On events,
 * "unseen_days": N takes only the ones whose type with the same User and Source ("unseen_by")
 * no device had in the last N days (a sign-in from somewhere new). Comparisons of
 * text ignore case; "matches" is a regular expression (without delimiters, case-insensitive).
 */
class SecurityRules
{
    public const SEVERITIES = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    /** Bootstrap colors of the severities. */
    public const SEVERITY_COLORS = ['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'info', 'info' => 'secondary'];

    public const PLATFORMS = ['any', 'windows', 'linux'];

    /**
     * What the agent reports (agents 1.17.0+), with the fields of an item and the ones that tell
     * two items apart (one finding each). Posture is one item: settings of the device.
     * Events are what happened since the previous collection (a SIEM in small): their findings
     * stay open until someone acknowledges them, they are not resolved by the next report.
     */
    public const SOURCES = [
        'software' => [
            'label' => 'Installed software',
            // FixedCves (agents 1.23.0+, dpkg): ",CVE-2026-1,CVE-2026-2," the changelog of the package names, see itemHash.
            'fields' => ['Name', 'Version', 'Publisher', 'Source', 'FixedCves'],
            'key' => ['Name', 'Source'],
        ],
        'processes' => [
            'label' => 'Running processes',
            'fields' => ['Name', 'Path', 'CommandLine', 'User', 'Count'],
            'key' => ['Name', 'Path', 'CommandLine'],
        ],
        'listening' => [
            'label' => 'Listening ports',
            'fields' => ['Protocol', 'Address', 'Port', 'Process', 'Path'],
            'key' => ['Protocol', 'Address', 'Port'],
        ],
        'startup' => [
            'label' => 'Startup items',
            'fields' => ['Name', 'Command', 'Location', 'User'],
            'key' => ['Name', 'Location'],
        ],
        'admins' => [
            'label' => 'Administrators',
            'fields' => ['Name', 'Source', 'Enabled'],
            'key' => ['Name'],
        ],
        'posture' => [
            'label' => 'Security settings',
            'fields' => [
                'FirewallEnabled', 'AntivirusName', 'AntivirusEnabled', 'AntivirusUpToDate', 'RealTimeProtection',
                'DiskEncrypted', 'SecureBoot', 'RdpEnabled', 'RdpNla', 'Smb1Enabled', 'UacEnabled', 'GuestEnabled',
                'AutoLogon', 'SshRootLogin', 'SshPasswordAuthentication', 'AutomaticUpdates', 'DaysSinceUpdate',
            ],
            'key' => [],
        ],
        'events' => [
            'label' => 'Security events',
            'fields' => ['Type', 'Count', 'User', 'Source', 'Message', 'Last'],
            'key' => ['Type', 'User', 'Source'],
            'event' => true,
        ],
        // The open ports an agent found on an address in its network (App\Models\PortScanResult):
        // one item per open port with the passive HTTP/TLS facts. Not from the device inventory —
        // evaluated per scanned host (which may be an unknown neighbour, not a managed device).
        'portscan' => [
            'label' => 'Open ports',
            'fields' => [
                'Ip', 'Network', 'Port', 'Service', 'Banner', 'Server', 'Title',
                'Tls', 'Http', 'Hsts', 'Csp', 'XFrameOptions', 'CertSelfSigned', 'CertExpired', 'TlsFailed',
            ],
            'key' => ['Ip', 'Port'],
        ],
    ];

    /** Labels of the event types the built-in parsers make (parsers may add their own). */
    public const EVENT_TYPES = [
        'failed_logon' => 'Failed sign-ins',
        'account_created' => 'Account created',
        'account_locked' => 'Account locked out',
        'admin_added' => 'Added to administrators',
        'log_cleared' => 'Audit log cleared',
        'service_installed' => 'Service installed',
        'task_created' => 'Scheduled task created',
        'malware_detected' => 'Malware detected',
        'protection_disabled' => 'Protection turned off',
        'sudo_failed' => 'Failed sudo',
    ];

    public const OPERATORS = [
        'eq', 'ne', 'contains', 'not_contains', 'starts_with', 'ends_with', 'matches', 'not_matches',
        'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'version_lt', 'version_lte', 'version_gt', 'version_gte',
        'dpkg_lt', 'dpkg_lte', 'dpkg_gt', 'dpkg_gte', 'exists', 'empty', 'true', 'false',
    ];

    /** @var array<string, ?string> the delimited form of every pattern seen, null for one that does not compile */
    private static array $regexes = [];

    /** Operators without a value. */
    private const UNARY = ['exists', 'empty', 'true', 'false'];

    /** At most this many links (urls) of a rule: documentation, ATT&CK, CVE. */
    public const MAX_URLS = 10;

    /** The urls of a rule when they are a list of http(s) addresses (shown as links), else null. */
    public static function urlList(mixed $urls): ?array
    {
        if (! is_array($urls) || ! array_is_list($urls) || count($urls) > self::MAX_URLS) {
            return null;
        }
        foreach ($urls as $url) {
            if (! is_string($url) || strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://[^/\s]+#i', $url)) {
                return null;
            }
        }

        return $urls;
    }

    /** Nesting deeper than this is refused (a rule is read on every report). */
    private const MAX_DEPTH = 6;

    private const MAX_CONDITIONS = 64;

    /**
     * What is wrong with a rule, empty when it can be used.
     *
     * @return array<int, string>
     */
    public static function errors(mixed $rule): array
    {
        if (! is_array($rule) || array_is_list($rule)) {
            return [__('A rule is a JSON object.')];
        }
        $errors = [];
        if (! is_string($rule['key'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $rule['key'])) {
            $errors[] = __('key: 2 to 64 lower-case letters, digits, dots, dashes or underscores.');
        }
        if (! is_string($rule['name'] ?? null) || trim($rule['name']) === '' || mb_strlen($rule['name']) > 120) {
            $errors[] = __('name: up to 120 characters.');
        }
        if (! isset(self::SEVERITIES[$rule['severity'] ?? null])) {
            $errors[] = __('severity: one of :values.', ['values' => implode(', ', array_keys(self::SEVERITIES))]);
        }
        if (isset($rule['platform']) && ! in_array($rule['platform'], self::PLATFORMS, true)) {
            $errors[] = __('platform: one of :values.', ['values' => implode(', ', self::PLATFORMS)]);
        }
        if (! isset(self::SOURCES[$rule['source'] ?? null])) {
            $errors[] = __('source: one of :values.', ['values' => implode(', ', array_keys(self::SOURCES))]);
        }
        foreach (['description', 'message', 'remediation'] as $field) {
            if (isset($rule[$field]) && (! is_string($rule[$field]) || mb_strlen($rule[$field]) > 1000)) {
                $errors[] = __(':field: text of up to 1000 characters.', ['field' => $field]);
            }
        }
        if (isset($rule['enabled']) && ! is_bool($rule['enabled'])) {
            $errors[] = __('enabled: true or false.');
        }
        if (isset($rule['threshold']) &&(! is_int($rule['threshold']) || $rule['threshold'] < 1 || $rule['threshold'] > 10000)) {
            $errors[] = __('threshold: a whole number from 1 to 10000.');
        }
        if (isset($rule['urls']) && self::urlList($rule['urls']) === null) {
            $errors[] = __('urls: a list of up to :count http(s) addresses.', ['count' => self::MAX_URLS]);
        }
        if (isset($rule['unseen_days']) && (($rule['source'] ?? null) !== 'events' || ! is_int($rule['unseen_days']) || $rule['unseen_days'] < 1 || $rule['unseen_days'] > SecurityEvent::RETENTION_DAYS)) {
            $errors[] = __('unseen_days: on rules of the events source, a whole number from 1 to :max.', ['max' => SecurityEvent::RETENTION_DAYS]);
        }
        if (isset($rule['unseen_by']) && (! isset($rule['unseen_days']) || ! is_array($rule['unseen_by']) || ! array_is_list($rule['unseen_by']) || $rule['unseen_by'] === []
            || array_diff($rule['unseen_by'], ['User', 'Source']) !== [] || count(array_unique($rule['unseen_by'])) !== count($rule['unseen_by']))) {
            $errors[] = __('unseen_by: a list of User and / or Source, with unseen_days.');
        }
        if (! array_key_exists('when', $rule)) {
            $errors[] = __('when: the conditions are missing.');
        } else {
            $count = 0;
            self::conditionErrors($rule['when'], 'when', 1, $count, $errors);
        }

        return $errors;
    }

    private static function conditionErrors(mixed $condition, string $path, int $depth, int &$count, array &$errors): void
    {
        if (++$count > self::MAX_CONDITIONS) {
            if ($count === self::MAX_CONDITIONS + 1) {
                $errors[] = __('At most :count conditions in a rule.', ['count' => self::MAX_CONDITIONS]);
            }

            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[] = __(':path: nested too deep.', ['path' => $path]);

            return;
        }
        if (! is_array($condition) || array_is_list($condition)) {
            $errors[] = __(':path: a condition is an object.', ['path' => $path]);

            return;
        }
        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $condition)) {
                if (count($condition) !== 1 || ! is_array($condition[$group]) || ! array_is_list($condition[$group]) || $condition[$group] === []) {
                    $errors[] = __(':path: ":group" is a list of conditions and nothing else.', ['path' => $path, 'group' => $group]);

                    return;
                }
                foreach ($condition[$group] as $index => $child) {
                    self::conditionErrors($child, "$path.$group.$index", $depth + 1, $count, $errors);
                }

                return;
            }
        }
        if (array_key_exists('not', $condition)) {
            if (count($condition) !== 1) {
                $errors[] = __(':path: "not" is one condition and nothing else.', ['path' => $path]);

                return;
            }
            self::conditionErrors($condition['not'], "$path.not", $depth + 1, $count, $errors);

            return;
        }

        $field = $condition['field'] ?? null;
        $op = $condition['op'] ?? null;
        if (! is_string($field) || ! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $field)) {
            $errors[] = __(':path: "field" is the name of a field.', ['path' => $path]);
        }
        if (! in_array($op, self::OPERATORS, true)) {
            $errors[] = __(':path: "op" is one of :values.', ['path' => $path, 'values' => implode(', ', self::OPERATORS)]);

            return;
        }
        $unknown = array_diff(array_keys($condition), ['field', 'op', 'value']);
        if ($unknown !== []) {
            $errors[] = __(':path: unknown keys :keys.', ['path' => $path, 'keys' => implode(', ', $unknown)]);
        }
        if (in_array($op, self::UNARY, true)) {
            return;
        }
        if (! array_key_exists('value', $condition)) {
            $errors[] = __(':path: "value" is missing.', ['path' => $path]);

            return;
        }
        $value = $condition['value'];
        $ok = match ($op) {
            'in', 'not_in' => is_array($value) && array_is_list($value) && $value !== [] && count(array_filter($value, fn ($item) => ! is_scalar($item))) === 0,
            'gt', 'gte', 'lt', 'lte' => is_int($value) || is_float($value),
            default => is_string($value) || is_int($value) || is_float($value) || is_bool($value),
        };
        if (! $ok) {
            $errors[] = __(':path: unsuitable value for ":op".', ['path' => $path, 'op' => $op]);

            return;
        }
        if (in_array($op, ['matches', 'not_matches'], true) && self::regex((string) $value) === null) {
            $errors[] = __(':path: not a valid regular expression.', ['path' => $path]);
        }
    }

    /** Whether an item fulfils a condition (a valid one, see errors). */
    public static function test(array $condition, array $item): bool
    {
        if (isset($condition['all'])) {
            foreach ($condition['all'] as $child) {
                if (! self::test($child, $item)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condition['any'])) {
            foreach ($condition['any'] as $child) {
                if (self::test($child, $item)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists('not', $condition)) {
            return ! self::test($condition['not'], $item);
        }

        $actual = self::field($item, $condition['field']);
        $expected = $condition['value'] ?? null;

        return match ($condition['op']) {
            'exists' => $actual !== null && $actual !== '',
            'empty' => $actual === null || $actual === '' || $actual === [],
            'true' => self::bool($actual) === true,
            'false' => self::bool($actual) === false,
            'eq' => self::equals($actual, $expected),
            'ne' => ! self::equals($actual, $expected),
            'in' => self::anyEquals($actual, $expected),
            'not_in' => ! self::anyEquals($actual, $expected),
            'contains' => self::text($actual) !== null && str_contains(self::text($actual), self::lower($expected)),
            'not_contains' => self::text($actual) === null || ! str_contains(self::text($actual), self::lower($expected)),
            'starts_with' => self::text($actual) !== null && str_starts_with(self::text($actual), self::lower($expected)),
            'ends_with' => self::text($actual) !== null && str_ends_with(self::text($actual), self::lower($expected)),
            'matches' => self::text($actual) !== null && (bool) @preg_match(self::regex((string) $expected) ?? '/(?!)/', (string) self::scalar($actual)),
            'not_matches' => self::text($actual) === null || ! @preg_match(self::regex((string) $expected) ?? '/(?!)/', (string) self::scalar($actual)),
            'gt' => is_numeric($actual) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            'version_lt' => self::compareVersions($actual, $expected, '<'),
            'version_lte' => self::compareVersions($actual, $expected, '<='),
            'version_gt' => self::compareVersions($actual, $expected, '>'),
            'version_gte' => self::compareVersions($actual, $expected, '>='),
            'dpkg_lt' => self::compareDpkg($actual, $expected, '<'),
            'dpkg_lte' => self::compareDpkg($actual, $expected, '<='),
            'dpkg_gt' => self::compareDpkg($actual, $expected, '>'),
            'dpkg_gte' => self::compareDpkg($actual, $expected, '>='),
            default => false,
        };
    }

    /**
     * The items of the list that are findings of the rule.
     *
     * @param  array<int, array>  $items
     * @return array<int, array>
     */
    public static function matching(array $rule, array $items): array
    {
        return array_values(array_filter($items, fn ($item) => is_array($item) && self::test($rule['when'], $item)));
    }

    /** What tells the item apart from the other items of its source (one finding per item). */
    public static function fingerprint(array $rule, ?array $item): string
    {
        $parts = [$rule['key']];
        foreach ($item === null ? [] : (self::SOURCES[$rule['source']]['key'] ?? []) as $field) {
            $parts[] = mb_strtolower((string) self::scalar(self::field($item, $field)));
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /** The message with {Field} placeholders filled from the item ("" for missing fields). */
    public static function render(string $template, array $item): string
    {
        $text = preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', fn ($m) => (string) self::scalar(self::field($item, $m[1])), $template);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    /** A field of an item, dots go deeper ("Signature.Status"). */
    public static function field(array $item, string $path): mixed
    {
        $value = $item;
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** "anydesk|teamviewer" as a case-insensitive pattern, null when it does not compile. */
    public static function regex(string $pattern): ?string
    {
        // A scan tests every item against every rule, so a pattern is compiled and checked once.
        if (! array_key_exists($pattern, self::$regexes)) {
            if (count(self::$regexes) >= 4096) {
                self::$regexes = [];
            }
            $regex = '~'.str_replace('~', '\~', $pattern).'~iu';
            self::$regexes[$pattern] = @preg_match($regex, '') === false ? null : $regex;
        }

        return self::$regexes[$pattern];
    }

    private static function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value));
        }

        return $value;
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : self::lower(self::scalar($value));
    }

    private static function lower(mixed $value): string
    {
        return mb_strtolower((string) self::scalar($value));
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return self::bool($actual) === $expected;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return $actual !== null && self::lower($actual) === self::lower($expected);
    }

    private static function anyEquals(mixed $actual, array $expected): bool
    {
        foreach ($expected as $value) {
            if (self::equals($actual, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versions as programs write them ("1.2.3", "2:1.2-3ubuntu1", "v10.0.19045"): only the leading numbers count,
     * a missing part is 0 ("8.5" is "8.5.0"), so "7.1.2-31" is "7.1.2" - write such a bound as "7.1.2.31".
     */
    private static function compareVersions(mixed $actual, mixed $expected, string $operator): bool
    {
        $a = self::version($actual);
        $b = self::version($expected);

        return $a !== null && $b !== null && self::fulfils(self::compareNumbers(explode('.', $a), explode('.', $b)), $operator);
    }

    /**
     * Debian package versions ("[epoch:]upstream[-revision]") in the order of dpkg, so "8.2512.0-1ubuntu4.2" is
     * after "8.2512.0-1ubuntu4.1" and "1.0~rc1" before "1.0".
     */
    private static function compareDpkg(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! is_scalar($actual) || ! is_scalar($expected) || trim((string) $actual) === '' || trim((string) $expected) === '') {
            return false;
        }
        [$epochA, $upstreamA, $revisionA] = self::dpkgParts(trim((string) $actual));
        [$epochB, $upstreamB, $revisionB] = self::dpkgParts(trim((string) $expected));

        return self::fulfils(
            self::compareNumbers([$epochA], [$epochB]) ?: self::dpkgCompareString($upstreamA, $upstreamB) ?: self::dpkgCompareString($revisionA, $revisionB),
            $operator,
        );
    }

    private static function fulfils(int $order, string $operator): bool
    {
        return match ($operator) {
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            default => $order >= 0,
        };
    }

    /** @param  array<int, string>  $a @param  array<int, string>  $b */
    private static function compareNumbers(array $a, array $b): int
    {
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $x = ltrim($a[$i] ?? '0', '0');
            $y = ltrim($b[$i] ?? '0', '0');
            if ($x !== $y) {
                return strlen($x) <=> strlen($y) ?: strcmp($x, $y);
            }
        }

        return 0;
    }

    /** @return array{0: string, 1: string, 2: string} epoch, upstream version and Debian revision */
    private static function dpkgParts(string $version): array
    {
        $epoch = '0';
        if (preg_match('/^(\d+):(.*)$/s', $version, $m)) {
            [$epoch, $version] = [$m[1], $m[2]];
        }
        $dash = strrpos($version, '-');

        return $dash === false ? [$epoch, $version, ''] : [$epoch, substr($version, 0, $dash), substr($version, $dash + 1)];
    }

    /** dpkg's verrevcmp: letters sort before other characters, "~" before everything, digit runs by number. */
    private static function dpkgCompareString(string $a, string $b): int
    {
        $order = function (string $s, int $i): int {
            $c = $s[$i] ?? '';
            if ($c === '' || ctype_digit($c)) {
                return 0;
            }

            return ctype_alpha($c) ? ord($c) : ($c === '~' ? -1 : ord($c) + 256);
        };
        $i = $j = 0;
        while ($i < strlen($a) || $j < strlen($b)) {
            while (($i < strlen($a) && ! ctype_digit($a[$i])) || ($j < strlen($b) && ! ctype_digit($b[$j]))) {
                $diff = $order($a, $i) <=> $order($b, $j);
                if ($diff !== 0) {
                    return $diff;
                }
                $i++;
                $j++;
            }
            $startA = $i;
            $startB = $j;
            while ($i < strlen($a) && ctype_digit($a[$i])) {
                $i++;
            }
            while ($j < strlen($b) && ctype_digit($b[$j])) {
                $j++;
            }
            $diff = self::compareNumbers([substr($a, $startA, $i - $startA)], [substr($b, $startB, $j - $startB)]);
            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }

    private static function version(mixed $value): ?string
    {
        if (! is_scalar($value) || ! preg_match('/(\d+(?:\.\d+)*)/', preg_replace('/^\d+:/', '', (string) $value), $m)) {
            return null;
        }

        return $m[1];
    }
}
