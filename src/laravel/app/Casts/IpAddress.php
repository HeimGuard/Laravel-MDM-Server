<?php

namespace App\Casts;

use App\Services\IpAddressService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An IPv4 address stored as a number (`unsignedBigInteger`, what `ip2long` gives: 192.168.1.1 = 3232235777) and used as the dotted text: a range
 * of addresses (a network) is then one `whereBetween` on an index (`IpAddressService::whereInNetwork`), it sorts by the address and it is the same
 * on SQLite and PostgreSQL. IPv4 only: an IPv6 address stays in a text column.
 *
 *     protected $casts = ['ip' => IpAddress::class];
 *     $table->unsignedBigInteger('ip')->index();
 */
class IpAddress implements CastsAttributes
{
    /** The number in the column to the dotted text (null stays null). */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : IpAddressService::toIp((int) $value);
    }

    /** The text (or the number) to the number for the column; anything that is no IPv4 address is refused, not stored as 0. */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value >= 0 && $value <= 0xFFFFFFFF) {
            return $value;
        }
        $number = IpAddressService::toLong((string) $value);
        if ($number === null) {
            throw new InvalidArgumentException('"'.$value.'" is not an IPv4 address ('.$key.').');
        }

        return $number;
    }
}
