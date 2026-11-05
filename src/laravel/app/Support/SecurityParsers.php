<?php

namespace App\Support;

/**
 * Parser rules: turn the raw records the agents send (log lines, Windows events) into security
 * events, which the detection rules of the "events" source then look at. A parser picks records
 * of one log source (when: conditions as in SecurityRules), takes values out of a field with a
 * regular expression with named groups (pattern), and fills the event from them:
 *
 *   {
 *     "key": "linux.sshd-failed",
 *     "kind": "parser",
 *     "name": "sshd: failed sign-in",
 *     "source": "linux.auth",
 *     "when": {"field": "Identifier", "op": "eq", "value": "sshd"},
 *     "pattern": "^Failed (?<method>password|publickey) for (invalid user )?(?<user>\\S+) from (?<ip>\\S+)",
 *     "event": {"type": "failed_logon", "user": "{user}", "source": "{ip}", "message": "ssh: failed {method} for {user}"}
 *   }
 *
 * Placeholders are named groups or fields of the record ({Data.TargetUserName}); {a|b} takes the
 * first one that is set ("-" counts as not set, as in Windows events). The first parser that
 * matches a record makes its event. Events are grouped by type, user and source with how often.
 */
class SecurityParsers
{
    /** What the agents send (agents 1.17.0+; docker.logs and web.log 1.31.0+), with the fields of a record. */
    public const LOG_SOURCES = [
        'linux.auth' => ['label' => 'Linux: auth log (journal or auth.log)', 'fields' => ['Time', 'Identifier', 'Pid', 'Message'], 'key' => 'Identifier'],
        'docker.logs' => ['label' => 'Docker: container logs', 'fields' => ['Time', 'Container', 'Image', 'Message'], 'key' => 'Image'],
        'web.log' => ['label' => 'Web servers and proxies: logs in /var/log (nginx, Apache, ...)', 'fields' => ['Time', 'File', 'Message'], 'key' => 'File'],
        // Not read by the agents: the server receives it (rsyslog in the container, App\Support\Syslog), the policy leaves it out.
        'syslog' => ['label' => 'Syslog: messages received from other servers and devices', 'fields' => ['Time', 'Host', 'Facility', 'Severity', 'App', 'Pid', 'Message'], 'key' => 'App', 'server' => true],
        'windows.security' => ['label' => 'Windows: Security log', 'fields' => ['Time', 'Id', 'Provider', 'Level', 'Data'], 'key' => 'Id'],
        'windows.system' => ['label' => 'Windows: System log', 'fields' => ['Time', 'Id', 'Provider', 'Level', 'Data'], 'key' => 'Id'],
        'windows.defender' => ['label' => 'Windows: Microsoft Defender log', 'fields' => ['Time', 'Id', 'Provider', 'Level', 'Data'], 'key' => 'Id'],
    ];

    /** At most this many records per source and collection are parsed. */
    public const MAX_RECORDS = 20000;

    private const EVENT_FIELDS = ['type', 'user', 'source', 'message', 'count'];

    /** @return array<int, string> */
    public static function errors(mixed $parser): array
    {
        if (! is_array($parser) || array_is_list($parser)) {
            return [__('A rule is a JSON object.')];
        }
        $errors = [];
        if (! is_string($parser['key'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $parser['key'])) {
            $errors[] = __('key: 2 to 64 lower-case letters, digits, dots, dashes or underscores.');
        }
        if (! is_string($parser['name'] ?? null) || trim($parser['name']) === '' || mb_strlen($parser['name']) > 120) {
            $errors[] = __('name: up to 120 characters.');
        }
        if (! isset(self::LOG_SOURCES[$parser['source'] ?? null])) {
            $errors[] = __('source: one of :values.', ['values' => implode(', ', array_keys(self::LOG_SOURCES))]);
        }
        if (isset($parser['description']) && (! is_string($parser['description']) || mb_strlen($parser['description']) > 1000)) {
            $errors[] = __(':field: text of up to 1000 characters.', ['field' => 'description']);
        }
        if (isset($parser['enabled']) && ! is_bool($parser['enabled'])) {
            $errors[] = __('enabled: true or false.');
        }
        if (! isset($parser['when']) && ! isset($parser['pattern'])) {
            $errors[] = __('when or pattern: a parser needs at least one of them.');
        }
        if (isset($parser['when'])) {
            // The same conditions as detection rules.
            $probe = ['key' => 'probe', 'name' => 'probe', 'severity' => 'info', 'source' => 'events', 'when' => $parser['when']];
            $errors = array_merge($errors, SecurityRules::errors($probe));
        }
        if (isset($parser['field']) && (! is_string($parser['field']) || ! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $parser['field']))) {
            $errors[] = __('field: the name of a field.');
        }
        if (isset($parser['pattern']) && (! is_string($parser['pattern']) || mb_strlen($parser['pattern']) > 1000 || SecurityRules::regex($parser['pattern']) === null)) {
            $errors[] = __('pattern: not a valid regular expression.');
        }
        $event = $parser['event'] ?? null;
        if (! is_array($event) || array_is_list($event)) {
            $errors[] = __('event: an object with type, user, source, message and count.');
        } else {
            if (! is_string($event['type'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $event['type'])) {
                $errors[] = __('event.type: 2 to 32 lower-case letters, digits or underscores.');
            }
            foreach ($event as $field => $value) {
                if (! in_array($field, self::EVENT_FIELDS, true)) {
                    $errors[] = __('event: unknown key :key.', ['key' => $field]);
                } elseif (! is_string($value) || mb_strlen($value) > 500) {
                    $errors[] = __('event.:key: text of up to 500 characters.', ['key' => $field]);
                }
            }
        }

        return $errors;
    }

    /**
     * The event a parser makes of a record, null when it does not match.
     *
     * @return array{Type: string, Count: int, User: ?string, Source: ?string, Message: ?string, Last: ?string}|null
     */
    public static function apply(array $parser, array $record): ?array
    {
        if (isset($parser['when']) && ! SecurityRules::test($parser['when'], $record)) {
            return null;
        }
        $values = $record;
        if (isset($parser['pattern'])) {
            $text = SecurityRules::field($record, $parser['field'] ?? 'Message');
            if (! is_scalar($text) || ! preg_match(SecurityRules::regex($parser['pattern']) ?? '/(?!)/', (string) $text, $matches)) {
                return null;
            }
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $values[$name] = $value;
                }
            }
        }
        $event = $parser['event'];
        $fill = fn (?string $template) => $template === null ? null : (self::render($template, $values) ?: null);
        $count = $fill($event['count'] ?? null);

        return [
            'Type' => $event['type'],
            'Count' => is_numeric($count) ? max(1, (int) $count) : 1,
            'User' => $fill($event['user'] ?? null),
            'Source' => $fill($event['source'] ?? null),
            'Message' => $fill($event['message'] ?? null),
            'Last' => is_scalar($record['Time'] ?? null) ? (string) $record['Time'] : null,
        ];
    }

    /**
     * The events of the logs of a collection: each record by the first parser of its source that
     * matches, grouped by type, user and source (Count summed, Last and Message the newest).
     *
     * @param  mixed  $logs  [{source, records: [...]}, ...] as the agent sends them
     * @param  iterable<int, array>  $parsers  definitions of the enabled parsers
     * @return array<int, array>
     */
    public static function run(mixed $logs, iterable $parsers): array
    {
        $bySource = [];
        foreach ($parsers as $parser) {
            $bySource[$parser['source']][] = $parser;
        }
        $groups = [];
        foreach (is_array($logs) ? $logs : [] as $log) {
            $source = is_array($log) ? ($log['source'] ?? null) : null;
            $records = is_array($log) ? self::records($log) : [];
            if (! is_string($source) || ! isset($bySource[$source])) {
                continue;
            }
            foreach (array_slice($records, 0, self::MAX_RECORDS) as $record) {
                if (! is_array($record)) {
                    continue;
                }
                foreach ($bySource[$source] as $parser) {
                    $event = self::apply($parser, $record);
                    if ($event === null) {
                        continue;
                    }
                    $key = $event['Type'].'|'.mb_strtolower((string) $event['User']).'|'.$event['Source'];
                    if (! isset($groups[$key])) {
                        $groups[$key] = $event;
                    } else {
                        $groups[$key]['Count'] += $event['Count'];
                        if ((string) $event['Last'] >= (string) $groups[$key]['Last']) {
                            $groups[$key]['Last'] = $event['Last'];
                            $groups[$key]['Message'] = $event['Message'] ?? $groups[$key]['Message'];
                        }
                    }
                    break;
                }
            }
        }

        return array_values($groups);
    }

    /**
     * The records of a log of a collection: {source, fields: [...], rows: [[...], ...]} (columnar, field
     * names once) or {source, records: [{...}, ...]}.
     *
     * @return array<int, array>
     */
    public static function records(array $log): array
    {
        if (is_array($log['records'] ?? null)) {
            return array_values(array_filter($log['records'], 'is_array'));
        }
        $fields = $log['fields'] ?? null;
        if (! is_array($fields) || ! is_array($log['rows'] ?? null)) {
            return [];
        }
        $width = count($fields);
        $records = [];
        foreach ($log['rows'] as $row) {
            if (is_array($row) && array_is_list($row) && count($row) === $width) {
                $records[] = array_combine($fields, $row);
            }
        }

        return $records;
    }

    /**
     * Whether the log of a container is read for the parsers: the policy of docker.logs (null for every
     * container, else the short names of the images) against its image ("ghcr.io/acme/nginx:1.27" is nginx).
     *
     * @param  array<int, string|int>|null  $images
     */
    public static function readsImage(?array $images, string $image): bool
    {
        if ($images === null) {
            return true;
        }
        $short = strtolower(preg_replace('/:[^:\/]*$/', '', explode('@', $image)[0]) ?? '');
        $short = substr(strrchr('/'.$short, '/'), 1);

        return in_array($short, array_map('strtolower', array_map('strval', $images)), true);
    }

    /**
     * What the agents have to send for the enabled parsers, so they filter at the source instead of
     * sending whole logs: per log the values of the field the parsers tell records apart by
     * (Identifier on Linux, the event Id on Windows), null when a parser takes any record of it, and an
     * empty list for a log no parser reads. A version (a hash) says when it changed.
     *
     * @param  iterable<int, array>  $parsers  definitions of the enabled parsers
     * @return array{version: string, sources: array<string, array<int, string|int>|null>}
     */
    public static function policy(iterable $parsers): array
    {
        $sources = array_fill_keys(array_keys(array_filter(self::LOG_SOURCES, fn (array $source) => empty($source['server']))), []);
        foreach ($parsers as $parser) {
            $source = $parser['source'];
            if (! array_key_exists($source, $sources)) {
                continue;
            }
            $values = self::constraint($parser['when'] ?? null, self::LOG_SOURCES[$source]['key'] ?? 'Id');
            if ($values === null || $sources[$source] === null) {
                $sources[$source] = null;
            } else {
                $sources[$source] = array_values(array_unique([...$sources[$source], ...$values], SORT_REGULAR));
            }
        }
        ksort($sources);
        foreach ($sources as &$values) {
            if (is_array($values)) {
                sort($values);
            }
        }
        unset($values);

        return ['version' => substr(hash('sha256', json_encode($sources)), 0, 16), 'sources' => $sources];
    }

    /**
     * The values a condition limits a field to ("field eq / in values", also under all / any), null when it
     * does not limit it.
     *
     * @return array<int, string|int>|null
     */
    private static function constraint(mixed $condition, string $field): ?array
    {
        if (! is_array($condition)) {
            return null;
        }
        if (isset($condition['all'])) {
            // Any one limiting condition limits the whole group.
            foreach ($condition['all'] as $child) {
                if (($values = self::constraint($child, $field)) !== null) {
                    return $values;
                }
            }

            return null;
        }
        if (isset($condition['any'])) {
            $all = [];
            foreach ($condition['any'] as $child) {
                $values = self::constraint($child, $field);
                if ($values === null) {
                    return null;
                }
                $all = [...$all, ...$values];
            }

            return $all;
        }
        if (($condition['field'] ?? null) !== $field) {
            return null;
        }

        return match ($condition['op'] ?? null) {
            'eq' => [$condition['value']],
            'in' => array_values($condition['value']),
            default => null,
        };
    }

    /** {a} and {a|b} (the first that is set; "-" is not set). */
    private static function render(string $template, array $values): string
    {
        $text = preg_replace_callback('/\{([A-Za-z0-9_.|]+)\}/', function ($m) use ($values) {
            foreach (explode('|', $m[1]) as $name) {
                $value = SecurityRules::field($values, $name);
                if (is_scalar($value) && trim((string) $value) !== '' && trim((string) $value) !== '-') {
                    return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
            }

            return '';
        }, $template);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
