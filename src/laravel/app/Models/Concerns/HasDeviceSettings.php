<?php

namespace App\Models\Concerns;

use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Traits\HasSettings;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;

/**
 * The settings of a device that used to be columns (the address, prefix and MAC a ping-only device is pinged at, the
 * agent to ping it through, the Wake-on-LAN target of an agent) are rows of the settings table (HasSettings), under the
 * keys lan.address, lan.prefix, lan.mac and lan.via_device_id. The model still has them as attributes
 * ($device->ping_address, $device->wake_mac, ...): read from the settings, written with the device (a changed one is
 * dirty until it is saved, so getDirty() tells what changed). ping_* is of a ping-only device, wake_* of an agent.
 */
trait HasDeviceSettings
{
    use HasSettings;

    /** attribute => [settings key, type, kind of device it belongs to] */
    public const SETTING_ATTRIBUTES = [
        'ping_address' => ['lan.address', SettingDataType::STRING, 'ping'],
        'ping_prefix' => ['lan.prefix', SettingDataType::INT, 'ping'],
        'ping_mac' => ['lan.mac', SettingDataType::STRING, 'ping'],
        'ping_via_id' => ['lan.via_device_id', SettingDataType::INT, 'ping'],
        // A switch the agent reads the ports of (App\Services\SwitchService; the SNMP and TP-Link windows of the device menu): the kind (tplink, snmp), the user and the password or the community (encrypted).
        // Where a device (of any kind) is plugged in by a cable: the switch (a ping-only device) and its port, set by hand.
        'switch_id' => ['lan.switch_device_id', SettingDataType::INT, 'any'],
        'switch_port' => ['lan.switch_port', SettingDataType::INT, 'any'],
        // How it got there: 'auto' (found in the MAC table of the switch), 'off' (taken off by hand: not paired by the MAC again), none (set by hand).
        'switch_auto' => ['lan.switch_auto', SettingDataType::STRING, 'any'],
        // The port of the device itself the cable goes into (optional, set by hand with the port of the switch): the WAN of a router, the uplink of a switch.
        'switch_remote_port' => ['lan.switch_remote_port', SettingDataType::INT, 'any'],
        // The interfaces of an SNMP device the user switched off (hidden) or on (shown) against the default (names; SwitchService::parseSnmp).
        'switch_hidden' => ['switch.hidden', SettingDataType::STRING, 'ping'],
        'switch_shown' => ['switch.shown', SettingDataType::STRING, 'ping'],
        // The ports of a device that is not read, set by hand (how many; the Ports window of the device menu).
        'switch_ports' => ['switch.ports', SettingDataType::INT, 'ping'],
        'switch_type' => ['switch.type', SettingDataType::STRING, 'ping'],
        'switch_user' => ['switch.user', SettingDataType::STRING, 'ping'],
        'switch_password' => ['switch.password', SettingDataType::STRING, 'ping'],
        'wake_mac' => ['lan.mac', SettingDataType::STRING, 'agent'],
        'wake_address' => ['lan.address', SettingDataType::STRING, 'agent'],
        'wake_prefix' => ['lan.prefix', SettingDataType::INT, 'agent'],
    ];

    /** @var array<string, mixed> the settings that changed and are stored with the device */
    private array $pendingSettings = [];

    public static function bootHasDeviceSettings(): void
    {
        static::saving(function ($device) {
            // The columns are gone: what is set is stored in the settings once the device is saved.
            foreach (array_keys(self::SETTING_ATTRIBUTES) as $attribute) {
                if (array_key_exists($attribute, $device->attributes)) {
                    $device->pendingSettings[$attribute] = $device->attributes[$attribute];
                    unset($device->attributes[$attribute], $device->original[$attribute]);
                }
            }
        });
        // A polymorphic relation has no foreign key to cascade.
        static::deleted(function ($device) {
            $device->settings()->delete();
            // A switch that is gone: the devices plugged into it are not any more.
            if (($device->attributes['kind'] ?? 'agent') === 'ping') {
                \App\Services\SwitchService::forget($device);
            }
        });
        static::saved(function ($device) {
            if ($device->pendingSettings !== []) {
                $device->storeSettings();
            }
        });
    }

    /** The kind of device an attribute is of: the settings of the other kind are not its own. */
    private function ownsSetting(string $attribute): bool
    {
        $kind = self::SETTING_ATTRIBUTES[$attribute][2];

        return $kind === 'any' || ($kind === 'ping') === (($this->attributes['kind'] ?? 'agent') === 'ping');
    }

    public function getAttribute($key)
    {
        if (isset(self::SETTING_ATTRIBUTES[$key])) {
            return $this->settingAttribute($key);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (isset(self::SETTING_ATTRIBUTES[$key])) {
            // What is stored is the original, so the attribute is dirty only when it differs.
            if (! array_key_exists($key, $this->original)) {
                $this->original[$key] = $this->storedSetting($key);
            }
            $this->attributes[$key] = $value === '' ? null : $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    private function settingAttribute(string $attribute): mixed
    {
        if (array_key_exists($attribute, $this->attributes)) {
            return $this->attributes[$attribute];
        }
        if (array_key_exists($attribute, $this->pendingSettings)) {
            return $this->pendingSettings[$attribute];
        }

        return $this->ownsSetting($attribute) ? $this->storedSetting($attribute) : null;
    }

    /** The stored value of a setting attribute (the type of the setting), null when there is none. */
    private function storedSetting(string $attribute): mixed
    {
        [$index, $type] = self::SETTING_ATTRIBUTES[$attribute];
        if (! $this->exists) {
            return null;
        }
        $value = $this->settings->firstWhere('index', $index)?->value;

        return $value === null || $value === '' ? null : ($type === SettingDataType::INT ? (int) $value : (string) $value);
    }

    /** Writes the settings that were set; one that was emptied is deleted. */
    private function storeSettings(): void
    {
        $pending = $this->pendingSettings;
        $this->pendingSettings = [];
        foreach ($pending as $attribute => $value) {
            if (! $this->ownsSetting($attribute)) {
                continue;
            }
            [$index, $type] = self::SETTING_ATTRIBUTES[$attribute];
            if ($value === null || $value === '') {
                $this->settings()->where('index', $index)->delete();
            } else {
                $this->settings()->updateOrCreate(['index' => $index], ['value' => (string) $value, 'type' => $type]);
            }
        }
        $this->unsetRelation('settings');
    }

    /** Devices that have a setting (a non-empty one). */
    public function scopeWhereSettingIn($query, string $index, array $values)
    {
        return $query->whereHas('settings', fn ($settings) => $settings->where('index', $index)->whereIn('value', array_map('strval', $values)));
    }

    public function scopeWhereHasSetting($query, string $index)
    {
        return $query->whereHas('settings', fn ($settings) => $settings->where('index', $index)->whereNotNull('value')->where('value', '!=', ''));
    }
}
