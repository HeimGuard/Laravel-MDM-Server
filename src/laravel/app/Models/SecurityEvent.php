<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * What an agent saw happen since its previous collection (failed sign-ins, accounts created,
 * logs cleared, ...), grouped by type, user and source with how often: the timeline of the
 * Security page. The rules of the "events" source turn them into findings.
 */
class SecurityEvent extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 30;

    /** At most this many (grouped) events are taken from one collection. */
    public const MAX_PER_COLLECTION = 500;

    /** The colour (Bootstrap) of an event type's badge: red for attacks, orange for changes of accounts, grey for the rest. */
    public const TONES = [
        'failed_logon' => 'warning', 'sudo_failed' => 'warning', 'sudo_auth_failure' => 'warning', 'su_failed' => 'warning',
        'account_locked' => 'warning', 'unexpected_shutdown' => 'warning',
        'log_cleared' => 'danger', 'malware_detected' => 'danger', 'protection_disabled' => 'danger', 'admin_added' => 'danger',
        'account_created' => 'info', 'account_deleted' => 'info', 'account_disabled' => 'info', 'admin_removed' => 'info',
        'password_reset' => 'info', 'password_changed' => 'info', 'service_installed' => 'info', 'task_created' => 'info',
        'logon' => 'success',
    ];

    protected $table = 'events';

    /**
     * device_id, user and source are what the pages and the rules know an event by: the device is the eventable, the user and
     * the address the event came from (source) are in data. log_source is where the event came from (linux.auth, syslog, ...).
     */
    protected $fillable = ['eventable_type', 'eventable_id', 'type', 'log_source', 'count', 'message', 'occurred_at', 'data', 'device_id', 'user', 'source', 'origin', 'parser'];

    protected $casts = [
        'count' => 'integer',
        'occurred_at' => 'datetime',
        'data' => 'array',
    ];

    public function eventable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The device of the event (read the events with eventable to have it loaded). */
    public function getDeviceAttribute(): ?Device
    {
        return $this->eventable instanceof Device ? $this->eventable : null;
    }

    public function getDeviceIdAttribute(): ?int
    {
        return $this->eventable_type === (new Device)->getMorphClass() ? (int) $this->eventable_id : null;
    }

    public function setDeviceIdAttribute(int|string|null $id): void
    {
        $this->attributes['eventable_type'] = (new Device)->getMorphClass();
        $this->attributes['eventable_id'] = $id;
    }

    public function scopeForDevice(Builder $query, int $deviceId): Builder
    {
        return $query->where('eventable_type', (new Device)->getMorphClass())->where('eventable_id', $deviceId);
    }

    private function detail(string $key): ?string
    {
        $value = ($this->data ?? [])[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function setDetail(string $key, mixed $value): void
    {
        $data = $this->data ?? [];
        if ($value === null || $value === '') {
            unset($data[$key]);
        } else {
            $data[$key] = (string) $value;
        }
        $this->data = $data === [] ? null : $data;
    }

    public function getUserAttribute(): ?string
    {
        return $this->detail('user');
    }

    public function setUserAttribute(?string $value): void
    {
        $this->setDetail('user', $value);
    }

    /** The address the event came from (data.origin); called source by the pages and the rules. */
    public function getSourceAttribute(): ?string
    {
        return $this->detail('origin');
    }

    public function setSourceAttribute(?string $value): void
    {
        $this->setDetail('origin', $value);
    }

    public function getOriginAttribute(): ?string
    {
        return $this->detail('origin');
    }

    public function setOriginAttribute(?string $value): void
    {
        $this->setDetail('origin', $value);
    }

    /** The key of the parser that made the event (data.parser). */
    public function getParserAttribute(): ?string
    {
        return $this->detail('parser');
    }

    public function setParserAttribute(?string $value): void
    {
        $this->setDetail('parser', $value);
    }

    public function prunable(): Builder
    {
        return static::where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function getLabelAttribute(): string
    {
        return __(SecurityRules::EVENT_TYPES[$this->type] ?? Str::headline($this->type));
    }

    /**
     * Stores the events of a collection (SecurityInventory::sanitize cleaned them).
     *
     * @param  array<int, array>  $events
     */
    public static function record(Device $device, array $events): void
    {
        $rows = [];
        foreach (array_slice($events, 0, self::MAX_PER_COLLECTION) as $event) {
            $data = array_filter(['user' => $event['User'], 'origin' => $event['Source'], 'parser' => $event['Parser'] ?? null], fn ($value) => $value !== null && $value !== '');
            $rows[] = [
                'eventable_type' => $device->getMorphClass(),
                'eventable_id' => $device->id,
                'type' => $event['Type'],
                'log_source' => (string) ($event['LogSource'] ?? ''),
                'count' => $event['Count'],
                'message' => $event['Message'],
                'occurred_at' => $event['Last'] ?? now(),
                'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            static::query()->insert($chunk);
        }
    }
}
