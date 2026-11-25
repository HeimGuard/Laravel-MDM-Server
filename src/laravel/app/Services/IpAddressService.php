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

    /** "fe80::1" => its 32 hexadecimal characters (lower case, zero-padded), null when it is no IPv6 address (the 32 characters themselves are accepted). */
    public static function ipv6ToHex(string $ip): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $ip) === 1) {
            return $ip;
        }
        $binary = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        return $binary === false ? null : bin2hex($binary);
    }

    /** The 32 characters to the compressed text ("fe80::1"). */
    public static function hexToIpv6(string $hex): string
    {
        return (string) inet_ntop((string) hex2bin(str_pad($hex, 32, '0', STR_PAD_LEFT)));
    }

    /**
     * The first and the last address of "fe80::/64" as 32 characters, null for no IPv6 network.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function cidrToRangeV6(string $cidr): ?array
    {
        $parts = explode('/', $cidr);
        $hex = self::ipv6ToHex($parts[0]);
        $prefix = $parts[1] ?? '128';
        if ($hex === null || count($parts) > 2 || ! ctype_digit($prefix) || (int) $prefix > 128) {
            return null;
        }
        $bytes = str_split($hex, 2);
        $start = '';
        $end = '';
        for ($i = 0; $i < 16; $i++) {
            // How many bits of this byte are in the network: 8 .. 0.
            $bits = max(0, min(8, (int) $prefix - $i * 8));
            $mask = ($bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF);
            $byte = hexdec($bytes[$i]);
            $start .= sprintf('%02x', $byte & $mask);
            $end .= sprintf('%02x', ($byte & $mask) | (~$mask & 0xFF));
        }

        return [$start, $end];
    }

    /** The IPv6 address is in the network. */
    public static function containsV6(string $cidr, string $ip): bool
    {
        $range = self::cidrToRangeV6($cidr);
        $hex = self::ipv6ToHex($ip);

        return $range !== null && $hex !== null && strcmp($hex, $range[0]) >= 0 && strcmp($hex, $range[1]) <= 0;
    }

    /** The rows whose address (a column with the cast `Ipv6Address`) is in the network: one `between`. A bad network matches nothing. */
    public static function whereInNetworkV6(EloquentBuilder|Builder $query, string $column, string $cidr): EloquentBuilder|Builder
    {
        $range = self::cidrToRangeV6($cidr);

        return $range === null ? $query->whereRaw('1 = 0') : $query->whereBetween($column, $range);
    }
}
