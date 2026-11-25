<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * IPv4 addresses as numbers (the cast `App\Casts\IpAddress`) and the ranges of a network for the queries.
 */
class IpAddressService
{
    /** "192.168.1.1" => 3232235777, null when it is no IPv4 address (a leading zero or a short form is not one either). */
    public static function toLong(string $ip): ?int
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : (int) ip2long($ip);
    }

    /** 3232235777 => "192.168.1.1". */
    public static function toIp(int $number): string
    {
        return long2ip($number);
    }

    /**
     * The first and the last address of "192.168.1.0/24" as numbers (the network and the broadcast address, both inside), null for no network.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function cidrToRange(string $cidr): ?array
    {
        $parts = explode('/', $cidr);
        $address = self::toLong($parts[0]);
        $prefix = $parts[1] ?? '32';
        if ($address === null || count($parts) > 2 || ! ctype_digit($prefix) || (int) $prefix > 32) {
            return null;
        }
        $mask = (int) $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - (int) $prefix)) & 0xFFFFFFFF;
        $start = $address & $mask;

        return [$start, $start | (~$mask & 0xFFFFFFFF)];
    }

    /** The address is in the network. */
    public static function contains(string $cidr, string $ip): bool
    {
        $range = self::cidrToRange($cidr);
        $number = self::toLong($ip);

        return $range !== null && $number !== null && $number >= $range[0] && $number <= $range[1];
    }

    /** The rows whose address (a column with the cast) is in the network: one `between`, no row of another network is read. A bad network matches nothing. */
    public static function whereInNetwork(EloquentBuilder|Builder $query, string $column, string $cidr): EloquentBuilder|Builder
    {
        $range = self::cidrToRange($cidr);

        return $range === null ? $query->whereRaw('1 = 0') : $query->whereBetween($column, $range);
    }
}
