<?php

namespace App\Casts;

use App\Services\IpAddressService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An IPv6 address stored as 32 hexadecimal characters (`char(32)`: 128 bits do not fit in a number column; lower case, no colons, zero-padded)
 * and used as the compressed text (`fe80::1`). The text of equal length sorts like the addresses, so a network is one `whereBetween` on an index
 * (`IpAddressService::whereInNetworkV6`), on SQLite and PostgreSQL alike. IPv4 has its own cast, `IpAddress` (a number).
 *
 *     protected $casts = ['ip' => Ipv6Address::class];
 *     $table->char('ip', 32)->index();
 */
class Ipv6Address implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : IpAddressService::hexToIpv6((string) $value);
    }

    /** The text to the 32 characters; anything that is no IPv6 address is refused (the 32 characters themselves are kept). */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $hex = IpAddressService::ipv6ToHex((string) $value);
        if ($hex === null) {
            throw new InvalidArgumentException('"'.$value.'" is not an IPv6 address ('.$key.').');
        }

        return $hex;
    }
}
