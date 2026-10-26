<?php

namespace App\Support;

/**
 * Debian / Ubuntu package versions ("[epoch:]upstream[-revision]") in the order of
 * `dpkg --compare-versions`, and the upstream part of one (for a comparison with a version a
 * project, the NVD or a vendor names).
 */
class DpkgVersion
{
    /** <0, 0 or >0 like `dpkg --compare-versions`: the epoch, then the upstream version, then the revision. */
    public static function compare(string $a, string $b): int
    {
        [$epochA, $upstreamA, $revisionA] = self::parts(trim($a));
        [$epochB, $upstreamB, $revisionB] = self::parts(trim($b));

        return self::compareNumbers([$epochA], [$epochB]) ?: self::compareString($upstreamA, $upstreamB) ?: self::compareString($revisionA, $revisionB);
    }

    /**
     * The version of the project behind a package version: no epoch, no Debian revision (-1, -1ubuntu0.1) and no
     * repackaging suffix (+dfsg1, +ds, ~dfsg): "2:8.0.1+dfsg1-1ubuntu0.1" is "8.0.1", "156.0.8078.47-1" is "156.0.8078.47".
     */
    public static function upstream(string $version): string
    {
        $upstream = self::parts(trim($version))[1];

        return preg_replace('/[+~](dfsg|ds|repack|really|git|svn|bzr)[^-]*$/i', '', $upstream) ?? $upstream;
    }

    /** @return array{0: string, 1: string, 2: string} epoch, upstream version and Debian revision */
    private static function parts(string $version): array
    {
        $epoch = '0';
        if (preg_match('/^(\d+):(.*)$/s', $version, $m)) {
            [$epoch, $version] = [$m[1], $m[2]];
        }
        $dash = strrpos($version, '-');

        return $dash === false ? [$epoch, $version, ''] : [$epoch, substr($version, 0, $dash), substr($version, $dash + 1)];
    }

    /** @param  array<int, string>  $a @param  array<int, string>  $b */
    public static function compareNumbers(array $a, array $b): int
    {
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $x = ltrim($a[$i] ?? '0', '0');
            $y = ltrim($b[$i] ?? '0', '0');
            if ($x !== $y) {
                return strlen($x) <=> strlen($y) ?: strcmp($x, $y);
            }
        }

        return 0;
    }

    /** dpkg's verrevcmp: letters sort before other characters, "~" before everything, digit runs by number. */
    private static function compareString(string $a, string $b): int
    {
        $order = function (string $s, int $i): int {
            $c = $s[$i] ?? '';
            if ($c === '' || ctype_digit($c)) {
                return 0;
            }

            return ctype_alpha($c) ? ord($c) : ($c === '~' ? -1 : ord($c) + 256);
        };
        $i = $j = 0;
        while ($i < strlen($a) || $j < strlen($b)) {
            while (($i < strlen($a) && ! ctype_digit($a[$i])) || ($j < strlen($b) && ! ctype_digit($b[$j]))) {
                $diff = $order($a, $i) <=> $order($b, $j);
                if ($diff !== 0) {
                    return $diff;
                }
                $i++;
                $j++;
            }
            $startA = $i;
            $startB = $j;
            while ($i < strlen($a) && ctype_digit($a[$i])) {
                $i++;
            }
            while ($j < strlen($b) && ctype_digit($b[$j])) {
                $j++;
            }
            $diff = self::compareNumbers([substr($a, $startA, $i - $startA)], [substr($b, $startB, $j - $startB)]);
            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }
}
