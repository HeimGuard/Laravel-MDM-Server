<?php

namespace App\Support;

/**
 * What `apt-get update` complained about (the agent sends its error lines): the repositories that are the problem and
 * the kind of problem, with what to do about each. Notices that are not errors (a repository that does not have an
 * architecture such as i386) are left out.
 */
class AptUpdate
{
    public const UNSIGNED = 'unsigned';

    public const NO_RELEASE = 'no_release';

    public const UNREACHABLE = 'unreachable';

    public const OTHER = 'other';

    public const LABELS = [
        self::UNSIGNED => 'not signed / key missing or invalid',
        self::NO_RELEASE => 'no Release file (does not support this release)',
        self::UNREACHABLE => 'not reachable',
        self::OTHER => 'error',
    ];

    /**
     * @return array<int, array{repository: string, type: string}> one per repository and kind of problem, in the order they came
     */
    public static function problems(?string $output): array
    {
        $found = [];
        foreach (preg_split('/\R/', (string) $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'Notice:') === 0 || stripos($line, "doesn't support architecture") !== false || stripos($line, 'N: ') === 0) {
                continue;
            }
            // Only lines of errors and warnings: "E: ...", "W: ...", "Err:3 ...".
            if (! preg_match('/^(E:|W:|Err:\d*|Error:|Warning:)\s*(.*)$/i', $line, $m)) {
                continue;
            }
            $text = $m[2];
            $type = match (true) {
                (bool) preg_match('/does not have a Release file/i', $text) => self::NO_RELEASE,
                (bool) preg_match('/is not signed|NO_PUBKEY|signatures (couldn\'t|could not) be verified|signatures were invalid|EXPKEYSIG|KEYEXPIRED|REVKEYSIG|invalid signature|public key is not available/i', $text) => self::UNSIGNED,
                (bool) preg_match('/Could not resolve|Temporary failure|Connection (failed|timed out|refused|reset)|Could not connect|Unable to connect|Failed to fetch|404\s+Not Found|Cannot initiate the connection|Network is unreachable/i', $text) => self::UNREACHABLE,
                default => null,
            };
            // A warning that is none of these (a deprecated key, a duplicate source) is not a failure of the update.
            if ($type === null && stripos($line, 'E:') !== 0 && stripos($line, 'Err') !== 0) {
                continue;
            }
            $repository = self::repository($text);
            if ($repository === null) {
                continue;
            }
            $key = $repository.'|'.($type ?? self::OTHER);
            $found[$key] ??= ['repository' => $repository, 'type' => $type ?? self::OTHER];
        }

        // "W: GPG error: x InRelease: not signed" and "E: The repository 'x InRelease' is not signed" are one problem of x.
        return array_values($found);
    }

    /** The repository (URL, without the suite and the file) a line is about, null when it names none. */
    private static function repository(string $text): ?string
    {
        $patterns = [
            "/The repository '([^']+)'/i",
            '/GPG error:\s*(\S+)/i',
            '/^\d*\s*(https?:\/\/\S+)/i',
            '/Failed to fetch\s+(\S+)/i',
            '/(https?:\/\/[^\s\'"]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $url = $m[1];
                if (preg_match('#^(https?://[^\s]+?)(?:/dists/\S*)?(?:\s+(\S+))?(?:\s+(?:In)?Release)?$#i', $url, $parts)) {
                    $url = $parts[1];
                }

                return rtrim(preg_replace('#\s+.*$#', '', $url), '/:');
            }
        }

        return null;
    }

    /** "ppa:user/name" of the address of a Launchpad PPA, else null. */
    public static function ppa(string $repository): ?string
    {
        return preg_match('#ppa\.launchpad(?:content)?\.net/([^/]+)/([^/]+)#i', $repository, $m) ? "ppa:{$m[1]}/{$m[2]}" : null;
    }

    /** What to do about one problem. */
    public static function advice(array $problem): string
    {
        $ppa = self::ppa($problem['repository']);
        $remove = $ppa !== null ? "sudo add-apt-repository --remove {$ppa}" : "remove its entry from /etc/apt/sources.list.d/ (or sources.list)";

        return match ($problem['type']) {
            self::UNSIGNED => "{$problem['repository']}: get the repository key again (into /etc/apt/keyrings/) and name it with Signed-By in the repository's entry; if the repository is not needed, {$remove}.",
            self::NO_RELEASE => "{$problem['repository']}: it does not support this version of the distribution; {$remove}, or wait until its publisher supports it.",
            self::UNREACHABLE => "{$problem['repository']}: check the network, DNS and proxy of the device and the address of the repository; if it is gone, {$remove}.",
            default => "{$problem['repository']}: see the output of sudo apt-get update.",
        };
    }

    /**
     * The fields of a finding of a failed update: AptUpdateSummary (the repositories and the problem) and Remediation.
     *
     * @return array{AptUpdateSummary: string, Remediation: string}
     */
    public static function fields(?string $output): array
    {
        $problems = self::problems($output);
        $summary = $problems === []
            ? 'see the output of sudo apt-get update'
            : implode('; ', array_map(fn ($p) => $p['repository'].' ('.self::LABELS[$p['type']].')', $problems));

        return [
            'AptUpdateSummary' => mb_strimwidth($summary, 0, 600, '…'),
            'Remediation' => implode("\n", array_map([self::class, 'advice'], $problems))
                .($problems === [] ? '' : "\n")
                .'Until it works, the vulnerabilities of this device are judged on package lists that may be out of date.',
        ];
    }
}
