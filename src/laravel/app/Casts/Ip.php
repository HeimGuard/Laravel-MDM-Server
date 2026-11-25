<?php

namespace App\Casts;

use App\Services\IpAddressService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An IP address of either family in one column: `char(32)`, the 128 bits as 32 hexadecimal characters (lower case, zero-padded). An IPv4 address is stored
 * as the IPv4-mapped IPv6 one (`::ffff:192.168.1.1`, the prefix `00000000000000000000ffff` and its 4 bytes), so both sort in one order and a network of either
 * family is one `whereBetween` on an index (`IpAddressService::whereWithin`), on SQLite and PostgreSQL alike. The model reads and writes the text
 * (`192.168.1.1`, `fe80::1`). Where it is known that a column holds only one family, `IpAddress` (IPv4 as a number) and `Ipv6Address` are the smaller ones.
 *
 *     protected $casts = ['ip' => Ip::class];
 *     $table->char('ip', 32)->index();
 */
class Ip implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : IpAddressService::fromHex((string) $value);
    }

    /** The text to the 32 characters; what is no IP address is refused (the 32 characters themselves are kept). */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $hex = IpAddressService::toHex((string) $value);
        if ($hex === null) {
            throw new InvalidArgumentException('"'.$value.'" is not an IP address ('.$key.').');
        }

        return $hex;
    }
}
