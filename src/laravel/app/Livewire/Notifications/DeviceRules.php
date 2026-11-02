<?php

namespace App\Livewire\Notifications;

use App\Models\AlertRule;
use App\Models\Device;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/**
 * The bell of a device (as in Beszel): one switch per alert type with its threshold and minutes,
 * saved as the user's rules for just this device. Rules for all devices or tags that cover the
 * device are listed below.
 */
#[AllowInModal]
class DeviceRules extends Component
{
    public int $deviceId;

    /** @var array<string, array{enabled: bool, threshold: ?int, minutes: ?int, unit: string, limit_gb: ?float}> */
    public array $settings = [];

    public function mount(int $deviceId): void
    {
        $this->deviceId = Device::findOrFail($deviceId)->id;
        foreach ($this->types() as $type => $definition) {
            $rule = $this->ruleFor($type);
            $this->settings[$type] = [
                'enabled' => $rule?->enabled ?? false,
                'threshold' => $rule?->threshold ?? $definition['threshold'],
                'minutes' => $rule?->minutes ?? $definition['minutes'],
                'unit' => $rule?->unit ?: 'percent',
                'limit_gb' => $rule?->limit_gb ?? (AlertRule::DEFAULT_LIMIT_GB[$type] ?? null),
            ];
        }
    }

    /** Any change is saved right away. */
    public function updatedSettings($value, string $key): void
    {
        $type = explode('.', $key)[0];
        if (! isset($this->types()[$type])) {
            return;
        }
        $setting = $this->settings[$type];
        $gb = AlertRule::usesUnit($type) && ($setting['unit'] ?? null) === 'gb';
        $threshold = AlertRule::usesThreshold($type) ? max(1, min(99, (int) ($setting['threshold'] ?: AlertRule::TYPES[$type]['threshold']))) : null;
        $limit = $gb ? max(0.1, min(AlertRule::MAX_LIMIT_GB, round((float) $setting['limit_gb'], 1))) : null;
        $minutes = AlertRule::usesMinutes($type) ? max(1, min(AlertRule::MAX_MINUTES, (int) $setting['minutes'])) : null;
        $this->settings[$type]['threshold'] = $threshold;
        $this->settings[$type]['minutes'] = $minutes;
        $this->settings[$type]['unit'] = $gb ? 'gb' : 'percent';
        if ($gb) {
            $this->settings[$type]['limit_gb'] = $limit;
        }
        $threshold = $gb ? null : $threshold;

        $rule = $this->ruleFor($type);
        $device = \App\Models\Device::find($this->deviceId);
        $label = __($this->types()[$type]['label'] ?? $type);
        if (! $setting['enabled']) {
            if ($rule && $device) {
                \App\Support\Audit::device($device, 'alerts', __('Alert turned off: :alert', ['alert' => $label]), ['type' => $type]);
            }
            $rule?->delete();

            return;
        }
        $rule ??= new AlertRule(['user_id' => auth()->id(), 'type' => $type, 'target' => ['all' => false, 'tags' => [], 'devices' => [$this->deviceId]]]);
        $rule->fill(['threshold' => $threshold, 'unit' => $gb ? 'gb' : 'percent', 'limit_gb' => $limit, 'minutes' => $minutes, 'enabled' => true]);
        if ($rule->exists && $rule->isDirty(['threshold', 'unit', 'limit_gb', 'minutes'])) {
            $rule->events()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        }
        $created = ! $rule->exists;
        $rule->save();
        if ($device && ($created || $rule->wasChanged())) {
            \App\Support\Audit::device($device, 'alerts', ($created ? __('Alert turned on: :alert', ['alert' => $label]) : __('Alert changed: :alert', ['alert' => $label])),
                ['type' => $type, 'threshold' => $threshold, 'unit' => $gb ? 'gb' : 'percent', 'limit_gb' => $limit, 'minutes' => $minutes]);
        }
        $this->dispatch('alertRuleSaved');
    }

    /** A ping-only device only has a status (it reports nothing else). */
    private function types(): array
    {
        // A device's own switches: its states, not fleet events (new device).
        return Device::find($this->deviceId)?->isPingOnly ? ['status' => AlertRule::TYPES['status']] : array_filter(AlertRule::TYPES, fn ($definition) => ! ($definition['event'] ?? false));
    }

    /** The user's rule of this type for exactly this device. */
    private function ruleFor(string $type): ?AlertRule
    {
        return AlertRule::query()->where('user_id', auth()->id())->where('type', $type)->get()
            ->first(function (AlertRule $rule) {
                $target = Device::normalizeTarget($rule->target);

                return ! $target['all'] && $target['tags'] === [] && $target['devices'] === [$this->deviceId];
            });
    }

    public function render()
    {
        $device = Device::findOrFail($this->deviceId);
        $own = collect(array_keys(AlertRule::TYPES))->map(fn ($type) => $this->ruleFor($type)?->id)->filter();
        $broader = AlertRule::query()->where('user_id', auth()->id())->whereNotIn('id', $own)->get()
            ->filter(fn (AlertRule $rule) => $device->matchesTarget($rule->target ?? []));

        return view('livewire.notifications.device-rules', [
            'device' => $device,
            'types' => $this->types(),
            'broader' => $broader,
        ]);
    }
}
