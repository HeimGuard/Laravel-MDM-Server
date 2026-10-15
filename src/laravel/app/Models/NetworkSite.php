<?php

namespace App\Models;

use App\Support\NetworkMap;
use Illuminate\Database\Eloquent\Model;

/**
 * A public address devices were seen behind (a site of the network map): the address the server
 * sees them from, or the one an agent finds for an interface. Kept when nobody is there anymore,
 * so the map shows where devices were (faint, with when).
 */
class NetworkSite extends Model
{
    protected $fillable = ['address', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    /** The public addresses a device is seen behind now. */
    public static function of(Device $device): array
    {
        return collect($device->networks)->where('Connected', true)->whereNotIn('Type', ['vpn', ...NetworkMap::SKIPPED_TYPES])->pluck('PublicAddress')
            ->push($device->public_ip)
            ->filter(fn ($address) => Device::isPublicIp($address))->unique()->values()->all();
    }

    /** Notes the addresses as seen now. */
    public static function record(array $addresses): void
    {
        if ($addresses === []) {
            return;
        }
        $now = now();
        static::query()->upsert(
            array_map(fn ($address) => ['address' => $address, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now], array_values($addresses)),
            ['address'],
            ['last_seen_at', 'updated_at'],
        );
    }
}
