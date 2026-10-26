<?php

namespace App\Models;

use App\Support\NetworkMap;
use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule of the scanner that holds on a device. Findings of the inventory are resolved when the
 * next inventory does not match anymore; findings of events (they happened, nothing makes them
 * go away) are resolved by acknowledging them. An acknowledged finding of the inventory stays
 * open, quiet (no alert), until it is gone.
 *
 * A finding from a port scan (source "portscan") is of a scanned host that may not be a managed
 * device: device_id is null then and the host is kept as target_ip / target_mac in its network
 * (target_ip / network / site). Such findings show only in the fleet Security section; findings
 * with a device_id show there and on the device, and keep the device alert flow.
 */
class SecurityFinding extends Model
{
    protected $fillable = [
        'device_id', 'target_ip', 'target_mac', 'network', 'site',
        'security_rule_id', 'fingerprint', 'severity', 'message', 'details', 'occurrences',
        'first_seen_at', 'last_seen_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by', 'stale_at',
    ];

    protected $casts = [
        'details' => 'array',
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    /** Open high and critical findings nobody acknowledged (the badge in the menu), counted once per request. */
    public static function severeCount(): int
    {
        return once(fn () => static::query()->active()->atLeast('high')->count());
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SecurityRule::class, 'security_rule_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** What the finding is about: the device's name, or the scanned host (its IP) when there is no device. */
    public function getTargetLabelAttribute(): string
    {
        return $this->device?->displayName ?? $this->target_ip ?? __('Unknown host');
    }

    /** Where the finding links to: the device's security tab, or the network card of the scanned host. */
    public function getTargetUrlAttribute(): ?string
    {
        if ($this->device_id !== null) {
            return route('devices', ['selectedDeviceId' => $this->device_id, 'tab' => 'security']);
        }

        return $this->site !== null && $this->network !== null
            ? NetworkMap::cardUrl('net:'.$this->site.'|'.$this->network)
            : null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Open, not acknowledged and not stale: what needs attention. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNull('acknowledged_at')->whereNull('stale_at');
    }

    /**
     * The findings of the filters of the Security page and of its export: open (needs attention), acknowledged, resolved
     * or unresolved (open and acknowledged), of this severity, with the text in the message, the device or the rule.
     */
    public function scopeFiltered(Builder $query, string $status = 'open', string $severity = '', string $search = ''): Builder
    {
        match ($status) {
            'acknowledged' => $query->whereNotNull('acknowledged_at'),
            'resolved' => $query->whereNotNull('resolved_at')->whereNull('acknowledged_at'),
            'unresolved' => $query->whereNull('resolved_at'),
            'stale' => $query->whereNull('resolved_at')->whereNotNull('stale_at'),
            default => $query->active(),
        };
        if (isset(SecurityRules::SEVERITIES[$severity])) {
            $query->where('severity', $severity);
        }
        if (($search = trim($search)) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('message', 'like', $like)
                ->orWhereHas('device', fn ($device) => $device->where('name', 'like', $like)->orWhere('friendly_name', 'like', $like))
                ->orWhereHas('rule', fn ($rule) => $rule->where('name', 'like', $like)));
        }

        return $query;
    }

    /** Ordered most severe first, then newest. */
    public function scopeBySeverity(Builder $query): Builder
    {
        $cases = collect(SecurityRules::SEVERITIES)->map(fn ($rank, $severity) => "when '$severity' then $rank")->implode(' ');

        return $query->orderByRaw("case severity $cases else 9 end")->latest('last_seen_at')->orderByDesc('id');
    }

    /** At least this severity (critical is the most severe). */
    public function scopeAtLeast(Builder $query, string $severity): Builder
    {
        $rank = SecurityRules::SEVERITIES[$severity] ?? 4;

        return $query->whereIn('severity', array_keys(array_filter(SecurityRules::SEVERITIES, fn ($value) => $value <= $rank)));
    }

    /** What to do: the finding's own advice (a CVE finding says it by where the package comes from), else the rule's. */
    public function getRemediationAttribute(): ?string
    {
        $own = $this->details['Remediation'] ?? null;

        return is_string($own) && $own !== '' ? $own : ($this->rule?->definition['remediation'] ?? null);
    }

    public function getSeverityColorAttribute(): string
    {
        return SecurityRules::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->resolved_at !== null && $this->acknowledged_at !== null => 'acknowledged',
            $this->resolved_at !== null => 'resolved',
            $this->acknowledged_at !== null => 'acknowledged',
            $this->stale_at !== null => 'stale',
            default => 'open',
        };
    }

    /** Acknowledged: an event is done with it, a finding of the inventory goes quiet until it is gone. */
    public function acknowledge(?User $user): void
    {
        $this->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
            'resolved_at' => $this->rule?->isEvent ? now() : $this->resolved_at,
        ]);
    }

    /** Open again (a finding of the inventory that is still there, or an event to look at again). */
    public function reopen(): void
    {
        $this->update(['stale_at' => null, 'acknowledged_at' => null, 'acknowledged_by' => null, 'resolved_at' => $this->rule?->isEvent ? null : $this->resolved_at]);
    }
}
