<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = ['device_id', 'type', 'count', 'user', 'source', 'message', 'occurred_at'];

    protected $casts = [
        'count' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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
            $rows[] = [
                'device_id' => $device->id,
                'type' => $event['Type'],
                'count' => $event['Count'],
                'user' => $event['User'],
                'source' => $event['Source'],
                'message' => $event['Message'],
                'occurred_at' => $event['Last'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            static::query()->insert($chunk);
        }
    }
}
