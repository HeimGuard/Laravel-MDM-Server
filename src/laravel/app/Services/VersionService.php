<?php

namespace App\Services;

/**
 * The order of the versions of a project as programs write them ("1.1.1w", "9.8p1", "2.0-rc1", "2:1.2-3ubuntu1"): what the
 * `version_*` operators of the rules and the ranges of the CVEs compare with. (Debian package versions are DpkgVersion.)
 *
 * - the first run of numbers is the release, a missing part is 0 ("8.5" is "8.5.0"); the epoch ("2:"), what a distribution
 *   adds after a dash ("-3ubuntu1") and a build ("+build5") are not part of it;
 * - alpha, beta, pre, rc, dev, snapshot (and "~") make it older than the release: 2.0-rc1 < 2.0, 2.0-rc1 < 2.0-rc2;
 * - letters right behind the numbers (1.1.1w, 1.0.2zk, 9.8p1, 1.3.9b) and sp / patch / hotfix / post with a number make it newer.
 *
 * The same algorithm as `version_tuple` of scripts/feed.py of detection-policy, which builds the ranges: both read
 * tests/fixtures/version-vectors.json (the same file in both repositories, change both).
 */
class VersionService
{
    /** The words that make a version older than its release, in the order of their rank. */
    private const PRE_WORDS = ['dev', 'snapshot', 'nightly', 'canary', 'alpha', 'beta', 'preview', 'pre', 'rc'];

    /** <0, 0 or >0, null when one of them has no number (the version of a program that does not say it). */
    public static function compare(mixed $a, mixed $b): ?int
    {
        $a = self::key($a);
        $b = self::key($b);
        if ($a === null || $b === null) {
            return null;
        }

        return self::compareNumbers($a[0], $b[0]) ?: self::compareTail($a[1], $b[1]);
    }

    /** @return array{0: array<int, string>, 1: array{0: int, 1: int|string, 2: int}}|null the release numbers (no trailing zeros) and how it ends */
    public static function key(mixed $value): ?array
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = preg_replace('/^\d+:/', '', (string) $value);
        if (! preg_match('/\d+(?:\.\d+)*/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $numbers = array_map(fn ($n) => ltrim($n, '0') ?: '0', explode('.', $m[0][0]));
        while ($numbers !== [] && end($numbers) === '0') {
            array_pop($numbers);
        }
        $rest = substr($text, $m[0][1] + strlen($m[0][0]));

        $pre = '/^(?:[-_.~+ ]?(dev|snapshot|nightly|canary|alpha|beta|preview|pre|rc)|([ab])(?=\d))[-_.]?(\d*)/i';
        if (preg_match($pre, $rest, $p)) {
            $word = strtolower($p[1] !== '' ? $p[1] : (strtolower($p[2]) === 'a' ? 'alpha' : 'beta'));
            $tail = [-1, array_search($word, self::PRE_WORDS, true), (int) $p[3]];
        } elseif (preg_match('/^[-_.]?(sp|patch|hotfix|post)[-_.]?(\d+)/i', $rest, $p)) {
            $tail = [1, strtolower($p[1]), (int) $p[2]];
        } elseif (preg_match('/^([a-z]{1,2})(?![a-z])(\d*)/i', $rest, $p)) {
            $tail = [1, strtolower($p[1]), (int) $p[2]];
        } elseif (str_starts_with($rest, '~')) {
            $tail = [-1, array_search('pre', self::PRE_WORDS, true), 0];
        } else {
            $tail = [0, '', 0];
        }

        return [$numbers, $tail];
    }

    /** @param  array<int, string>  $a @param  array<int, string>  $b */
    private static function compareNumbers(array $a, array $b): int
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

    /** @param  array{0: int, 1: int|string, 2: int}  $a @param  array{0: int, 1: int|string, 2: int}  $b */
    private static function compareTail(array $a, array $b): int
    {
        return ($a[0] <=> $b[0]) ?: ($a[1] === $b[1] ? 0 : (is_int($a[1]) ? $a[1] <=> $b[1] : strcmp($a[1], $b[1]))) ?: ($a[2] <=> $b[2]);
    }
}
