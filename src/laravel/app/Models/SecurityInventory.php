<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The security inventory of a device (agents 1.17.0+): what the rules of the scanner look at
 * (App\Support\SecurityRules::SOURCES, without the events). The row holds the state of each
 * source (a hash of its items), the items are SecurityInventoryItem rows: an agent sends only
 * the items that changed since the state the server has, the server checks that the result has
 * the state the agent computed.
 */
class SecurityInventory extends Model
{
    /** The sources an inventory has (events are not part of it). */
    public const SOURCES = ['software', 'processes', 'listening', 'startup', 'admins', 'posture'];

    /** At most this many items per list are kept. */
    public const LIMITS = ['software' => 5000, 'processes' => 1000, 'listening' => 1000, 'startup' => 500, 'admins' => 200, 'posture' => 1];

    /** The key of the one item of the posture source. */
    public const POSTURE_KEY = 'posture';

    /** Texts are cut to this many characters (command lines are long). */
    private const MAX_TEXT = 1000;

    /** Fields that may be longer: the CVE ids of a package changelog. */
    private const MAX_TEXT_OF = ['FixedCves' => 4000];

    protected $fillable = ['device_id', 'states', 'scanned', 'collected_at'];

    protected $casts = [
        'states' => 'array',
        'scanned' => 'array',
        'collected_at' => 'datetime',
    ];

    /** @var array<string, array<int, array>>|null */
    private ?array $loaded = null;

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SecurityInventoryItem::class, 'device_id', 'device_id');
    }

    /** The items of one source as the rules see them (posture: its one item); [] for an unknown one. */
    public function items(string $source): array
    {
        return $source === 'posture' ? [$this->lists()['posture']] : ($this->lists()[$source] ?? []);
    }

    /**
     * Every list, loaded once: ['software' => [item, ...], ..., 'posture' => [field => value]].
     * The posture source is one object, not a list.
     *
     * @return array<string, array>
     */
    public function lists(): array
    {
        if ($this->loaded === null) {
            $this->loaded = array_fill_keys(self::SOURCES, []);
            foreach ($this->rows()->orderBy('id')->get(['source', 'data']) as $row) {
                $item = json_decode($row->data, true);
                if (is_array($item) && isset($this->loaded[$row->source])) {
                    $this->loaded[$row->source][] = $item;
                }
            }
            $this->loaded['posture'] = $this->loaded['posture'][0] ?? [];
        }

        return $this->loaded;
    }

    /** The lists as the views read them (`$inventory->data['software']`). */
    public function getDataAttribute(): array
    {
        return $this->lists();
    }

    /** The key of an item: tells it apart from the other items of its source. */
    public static function itemKey(string $source, array $item): string
    {
        if ($source === 'posture') {
            return self::POSTURE_KEY;
        }
        $parts = [];
        foreach (SecurityRules::SOURCES[$source]['key'] as $field) {
            $parts[] = mb_strtolower(self::canon($item[$field] ?? null));
        }

        return substr(hash('sha256', implode("\x1f", $parts)), 0, 16);
    }

    /**
     * The hash of the content of an item (all its fields, in the order of the source). FixedCves and
     * SourcePackage count only when they are set, so the hashes of the items without them are the ones
     * agents before 1.23.0 / 1.24.0 send.
     */
    public static function itemHash(string $source, array $item): string
    {
        $parts = [];
        foreach (SecurityRules::SOURCES[$source]['fields'] as $field) {
            $value = self::canon($item[$field] ?? null);
            if (in_array($field, ['FixedCves', 'SourcePackage'], true) && $value === '') {
                continue;
            }
            $parts[] = $value;
        }

        return substr(hash('sha256', implode("\x1f", $parts)), 0, 16);
    }

    /**
     * The state of a source from its items' (key => hash): a hash of the sorted "key:hash" lines.
     * The agent computes the same one (Get-SecurityStateHash in app.ps1).
     *
     * @param  array<string, string>  $hashes
     */
    public static function stateOf(array $hashes): string
    {
        $keys = array_map('strval', array_keys($hashes));
        sort($keys, SORT_STRING);
        $lines = array_map(fn ($key) => $key.':'.$hashes[$key], $keys);

        return hash('sha256', implode("\n", $lines));
    }

    /** A value as text for hashing: "" for none, true / false, numbers as they are. */
    private static function canon(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * An item of a source with only its known fields and values: texts cut, Count / Port / days
     * as integers. The posture source keeps booleans, numbers and texts.
     */
    public static function sanitizeItem(string $source, mixed $item): array
    {
        $clean = [];
        $item = is_array($item) ? $item : [];
        foreach (SecurityRules::SOURCES[$source]['fields'] as $field) {
            $value = $item[$field] ?? null;
            $clean[$field] = $source === 'posture' && (is_bool($value) || is_int($value) || is_float($value)) ? $value : self::value($value, $field);
        }

        return $clean;
    }

    private static function value(mixed $value, string $field): mixed
    {
        if (in_array($field, ['Count', 'Port', 'DaysSinceUpdate'], true)) {
            return is_numeric($value) ? (int) $value : null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_strimwidth($text, 0, self::MAX_TEXT_OF[$field] ?? self::MAX_TEXT, '…');
    }

    /**
     * The events of a collection with only the known fields (type of any shape the parsers make).
     *
     * @return array<int, array>
     */
    public static function sanitizeEvents(mixed $events): array
    {
        $clean = [];
        $fields = SecurityRules::SOURCES['events']['fields'];
        if (is_array($events) && ! array_is_list($events)) {
            $events = [$events];
        }
        foreach (is_array($events) ? $events : [] as $event) {
            if (count($clean) >= SecurityEvent::MAX_PER_COLLECTION) {
                break;
            }
            if (! is_array($event)) {
                continue;
            }
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = self::value($event[$field] ?? null, $field);
            }
            // Types come from the parsers (built-in ones in SecurityRules::EVENT_TYPES).
            if (! is_string($row['Type']) || ! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $row['Type'])) {
                continue;
            }
            $row['Count'] = max(1, (int) $row['Count']);
            $row['Last'] = self::time($row['Last']);
            $clean[] = $row;
        }

        return $clean;
    }

    private static function time(mixed $value): string
    {
        try {
            $time = $value ? Carbon::parse((string) $value) : now();
        } catch (Throwable) {
            $time = now();
        }

        // Not from the future (a device clock that is off), not older than the retention.
        return $time->min(now())->max(now()->subDays(SecurityEvent::RETENTION_DAYS))->toDateTimeString();
    }
}
