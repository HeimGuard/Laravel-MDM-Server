<?php

namespace App\Livewire\SecurityScan;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * A rule of the scanner in JSON (system admins): checked as it is typed, tried before it is saved
 * (a detection rule on the last inventory of a device, a parser on a sample record). Built-in
 * ones are shown, not edited (a copy can be).
 */
#[AllowInModal]
class RuleForm extends Component
{
    public ?int $ruleId = null;

    public string $json = '';

    public ?int $previewDeviceId = null;

    public bool $readOnly = false;

    /** Parsers: a record to try it on, a log line or a record in JSON. */
    public string $sample = '';

    private const TEMPLATE = [
        'key' => 'custom.example',
        'name' => 'Example: Chrome older than 120',
        'description' => 'What the rule is about and why it matters.',
        'severity' => 'medium',
        'platform' => 'any',
        'source' => 'software',
        'when' => ['all' => [
            ['field' => 'Name', 'op' => 'starts_with', 'value' => 'google chrome'],
            ['field' => 'Version', 'op' => 'version_lt', 'value' => '120'],
        ]],
        'message' => '{Name} {Version} is outdated',
        'remediation' => 'Update it.',
    ];

    private const PARSER_TEMPLATE = [
        'kind' => 'parser',
        'key' => 'custom.su-failed',
        'name' => 'su: wrong password',
        'source' => 'linux.auth',
        'when' => ['field' => 'Identifier', 'op' => 'eq', 'value' => 'su'],
        'pattern' => '^FAILED SU \\(to (?<target>\\S+)\\) (?<user>\\S+) on',
        'event' => ['type' => 'su_failed', 'user' => '{user}', 'source' => '{target}', 'message' => '{user} failed su to {target}'],
    ];

    private const PARSER_SAMPLE = '{"Time": "2026-10-05T10:00:00Z", "Identifier": "su", "Pid": 123, "Message": "FAILED SU (to root) alice on pts/0"}';

    public function mount(?int $ruleId = null, ?int $copyOf = null, string $kind = 'detection', ?int $eventId = null): void
    {
        Gate::authorize('is-system-admin');
        $definition = $kind === 'parser' ? self::PARSER_TEMPLATE : self::TEMPLATE;
        $record = null;
        if ($eventId) {
            // A parser for a syslog message nothing reads (an event of the type "syslog"): it matches this message.
            $event = SecurityEvent::query()->where('type', 'syslog')->findOrFail($eventId);
            [$definition, $record] = self::parserFor($event);
        } elseif ($ruleId) {
            $rule = SecurityRule::query()->findOrFail($ruleId);
            $this->ruleId = $rule->id;
            $this->readOnly = $rule->built_in;
            $definition = $rule->definition;
        } elseif ($copyOf) {
            $definition = SecurityRule::query()->findOrFail($copyOf)->definition;
            $definition['key'] = mb_substr('custom.'.preg_replace('/^custom\./', '', $definition['key']), 0, 57).'-copy';
            $definition['name'] = mb_strimwidth($definition['name'], 0, 112).' (copy)';
            unset($definition['enabled']);
        }
        $this->json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->previewDeviceId = SecurityInventory::query()->latest('collected_at')->value('device_id');
        $this->sample = $record !== null ? json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (($definition['kind'] ?? null) === 'parser' ? self::PARSER_SAMPLE : '');
    }

    /**
     * The parser that reads the message of an event of the type "syslog" (the program in User, the host name in Source),
     * and the record to try it on. The pattern is the whole message: to read the part that changes, replace it with a
     * group (for example "(?<user>\\S+)") and use it in the event.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    public static function parserFor(SecurityEvent $event): array
    {
        $app = (string) $event->user;
        $name = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($app)), '_');
        $message = (string) $event->message;
        $definition = [
            'kind' => 'parser',
            'key' => 'custom.syslog-'.Str::limit(str_replace('_', '-', $name ?: 'message'), 30, '').'-'.$event->id,
            'name' => mb_strimwidth('Syslog: '.($app !== '' ? $app.': ' : '').$message, 0, 100, '…'),
            'source' => 'syslog',
            'when' => $app !== '' ? ['field' => 'App', 'op' => 'eq', 'value' => $app] : null,
            'pattern' => '^'.preg_quote($message).'$',
            'event' => [
                'type' => 'syslog_'.Str::limit($name ?: 'event', 20, ''),
                'user' => '{App}',
                'source' => '{Host}',
                'message' => '{Message}',
            ],
        ];
        $definition = array_filter($definition, fn ($value) => $value !== null);
        $record = ['Time' => $event->occurred_at?->toIso8601String() ?? now()->toIso8601String(), 'Host' => (string) $event->source, 'App' => $app, 'Message' => $message];

        return [$definition, $record];
    }

    /** @return array{definition: ?array, errors: array<int, string>} */
    private function parse(): array
    {
        $definition = json_decode($this->json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['definition' => null, 'errors' => [__('Not valid JSON: :error.', ['error' => json_last_error_msg()])]];
        }
        $errors = SecurityRule::errorsOf($definition);
        if ($errors === []) {
            $taken = SecurityRule::query()->where('key', $definition['key'])->when($this->ruleId, fn ($q) => $q->whereKeyNot($this->ruleId))->exists();
            if ($taken) {
                $errors[] = __('key: another rule has this key.');
            }
        }

        return ['definition' => $errors === [] ? $definition : null, 'errors' => $errors];
    }

    public function save(): void
    {
        Gate::authorize('is-system-admin');
        if ($this->readOnly) {
            return;
        }
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        if ($definition === null) {
            $this->addError('json', $errors[0]);

            return;
        }
        $rule = $this->ruleId ? SecurityRule::query()->custom()->findOrFail($this->ruleId) : new SecurityRule(['origin' => SecurityRule::CUSTOM, 'enabled' => $definition['enabled'] ?? true]);
        $rule->fill(SecurityRule::attributesFor($definition))->save();
        if (! $rule->isParser) {
            SecurityScanner::scanAll();
        }

        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    public function delete(): void
    {
        Gate::authorize('is-system-admin');
        SecurityRule::query()->custom()->whereKey($this->ruleId)->delete();
        $this->dispatch('securityRuleSaved');
        $this->dispatch('closeModal');
    }

    /** The event the parser makes of the sample: ['event' => ?array] or ['error' => string]. */
    private function trySample(array $parser): array
    {
        $sample = trim($this->sample);
        if ($sample === '') {
            return ['error' => __('Enter a sample record.')];
        }
        $record = str_starts_with($sample, '{') ? json_decode($sample, true) : ['Message' => $sample];
        if (! is_array($record)) {
            return ['error' => __('The sample is not valid JSON.')];
        }

        return ['event' => SecurityParsers::apply($parser, $record)];
    }

    public function render()
    {
        ['definition' => $definition, 'errors' => $errors] = $this->parse();
        $isParser = is_array($json = json_decode($this->json, true)) && ($json['kind'] ?? null) === 'parser';
        $device = $this->previewDeviceId ? Device::find($this->previewDeviceId) : null;

        return view('livewire.security-scan.rule-form', [
            'problems' => $errors,
            'isParser' => $isParser,
            'parsed' => $isParser && $definition ? $this->trySample($definition) : null,
            'preview' => ! $isParser && $definition && $device ? SecurityScanner::preview($definition, $device) : null,
            'previewDevice' => $device,
            'devices' => Device::query()->whereIn('id', SecurityInventory::query()->select('device_id'))->get()->sortBy('displayName'),
        ]);
    }
}
