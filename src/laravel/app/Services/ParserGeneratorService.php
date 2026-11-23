<?php

namespace App\Services;

use App\Support\SecurityParsers;
use App\Support\SecurityRules;
use RuntimeException;

/**
 * Writes a parser of syslog messages from one real message with the AI of the server (AiService): the pattern that reads the part that
 * varies, and the event it makes. Only the text of the message, the program and the facility and severity leave the server, not the host
 * name or anything about the devices. The parser is checked here: it has to be valid and to read the message it was written for.
 */
class ParserGeneratorService
{
    private const SYSTEM = <<<'TEXT'
        You write parsers of syslog messages for a security monitoring server. A parser reads ONE kind of message and makes an event of it.
        You get one real message with the program that sent it (the field App), its facility and severity. Write:
        - name: short, in the language of the request (English when it names none), what the message is, e.g. "Synology: sign-in to DSM".
        - description: one sentence.
        - app: the program (App) the parser applies to, exactly as given, or "" when the message does not tell it.
        - pattern: a regular expression (PCRE, case insensitive, WITHOUT delimiters, a dollar sign and a caret are fine) that matches the
          text of the Message. Keep the fixed words, and read what varies (user names, addresses, numbers, paths, names of devices,
          session ids) with NAMED groups: (?<user>[^\]]+) or (?<ip>\S+) or (?<n>\d+). Never repeat the exact values of the sample: the
          parser has to match every other message of the same kind. Brackets, dots and other special characters of the text are escaped.
          Do not anchor with $ at the end when the message may continue; do anchor with ^.
        - event.type: the kind of event, one of these when it fits (the rules of the server know them): logon (a successful sign-in),
          failed_logon (a failed sign-in or an attack), account_created, account_deleted, account_disabled, account_locked,
          password_changed, password_reset, admin_added, admin_removed, sudo_failed, su_failed, log_cleared, service_installed,
          task_created, malware_detected, protection_disabled, system_start, unexpected_shutdown. Otherwise "syslog_" and a lower case
          word for the kind (lower case letters, digits and underscores, 2 to 32 characters).
        - event.user: who it is about, as a template of the groups ({user}), or "" when there is none.
        - event.source: where it came from (an IP address), as a template ({ip}), or "" when there is none.
        - event.message: a readable one-line summary as a template, e.g. "{user} signed in to {service} from {ip}".
        Templates may use the named groups, and {App} and {Message}. A message that is only noise (a status line, a heartbeat) still gets a
        parser with the type "syslog_" and its kind. Never invent a group the pattern does not have.
        TEXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'app' => ['type' => 'string'],
            'pattern' => ['type' => 'string'],
            'event' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                    'user' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['type', 'user', 'source', 'message'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['name', 'description', 'app', 'pattern', 'event'],
        'additionalProperties' => false,
    ];

    /**
     * The parser for a message, valid and reading that message. Throws a RuntimeException with a message for the user when the AI cannot or its
     * parser is not one.
     *
     * @param  array<string, string>  $record  the record of the message (App, Host, Facility, Severity, Message): the host is not sent
     * @param  string  $key  the key of the parser (custom.syslog-...)
     * @return array<string, mixed> the definition
     */
    public static function generate(array $record, string $key): array
    {
        $content = "The message to write a parser for:\n".json_encode([
            'App' => (string) ($record['App'] ?? ''), 'Facility' => (string) ($record['Facility'] ?? ''),
            'Severity' => (string) ($record['Severity'] ?? ''), 'Message' => (string) ($record['Message'] ?? ''),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $text = AiService::ask(self::SYSTEM, $content, self::SCHEMA, 'parser', 4000, 'low');
        $answer = $text !== '' ? json_decode($text, true) : null;
        if (! is_array($answer) || ! is_string($answer['pattern'] ?? null) || ! is_array($answer['event'] ?? null)) {
            throw new RuntimeException(__('The AI did not return a parser, try again.'));
        }

        return self::checked(self::definition($answer, $key), $record);
    }

    /**
     * The definition of the parser of an answer of the AI.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    public static function definition(array $answer, string $key): array
    {
        $event = (array) $answer['event'];
        $type = strtolower(trim((string) ($event['type'] ?? '')));
        $definition = [
            'kind' => 'parser',
            'key' => $key,
            'name' => mb_strimwidth(trim((string) ($answer['name'] ?? '')) ?: 'Syslog', 0, 100, '…'),
            'description' => mb_substr(trim((string) ($answer['description'] ?? '')), 0, 500),
            'source' => 'syslog',
            'when' => trim((string) ($answer['app'] ?? '')) !== '' ? ['field' => 'App', 'op' => 'eq', 'value' => trim((string) $answer['app'])] : null,
            'pattern' => (string) $answer['pattern'],
            'event' => array_filter([
                'type' => $type,
                'user' => trim((string) ($event['user'] ?? '')),
                'source' => trim((string) ($event['source'] ?? '')),
                'message' => trim((string) ($event['message'] ?? '')) ?: '{Message}',
            ], fn ($value) => $value !== ''),
        ];

        return array_filter($definition, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The definition when it can be saved and it reads the message it was written for; a RuntimeException otherwise.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $record
     * @return array<string, mixed>
     */
    public static function checked(array $definition, array $record): array
    {
        $type = (string) ($definition['event']['type'] ?? '');
        $known = isset(SecurityRules::EVENT_TYPES[$type]) || $type === 'logon' || preg_match('/^syslog_[a-z0-9_]{1,24}$/', $type);
        if (SecurityParsers::errors($definition) !== [] || ! $known || SecurityParsers::apply($definition, $record) === null) {
            throw new RuntimeException(__('The AI did not return a parser that reads this message, try again.'));
        }

        return $definition;
    }
}
