<?php

namespace App\Support;

/**
 * The vulnerabilities of a package, one CVE at a time. A CVE rule of the feed lists its CVEs
 * ("cves": [{id, severity, ranges, ubuntu}]) and a package is affected only by the CVEs it is below the fix of
 * (installed < fixed, never equal), so a finding names exactly those:
 *
 *  - "ranges": [[start, end_excl, end_incl]] the versions of the project the NVD says are vulnerable;
 *  - "ubuntu" / "debian": {source package: {release: [status, fixed version]}} what the security tracker of the
 *    distribution says (Ubuntu CVE tracker, Debian Security Tracker), which is what counts for a package of that
 *    distribution (it fixes with a backport and does not change the upstream version); the tracker of the OS of the device
 *    is used, another distribution (Mint, ...) is judged by the NVD ranges. Without a word of the tracker for the package
 *    the ranges decide on the upstream version (epoch and Debian revision taken off).
 *
 * A CVE the changelog of the package names (the agent's FixedCves) is fixed whatever the versions say.
 */
class SecurityCves
{
    /** What can be done about a CVE, the more urgent first (the status of a finding is its most urgent one). */
    public const STATUSES = [
        'no_fix' => 'no fix available yet',
        'no_source' => 'no update source',
        'fix_via_pro' => 'fix available via Ubuntu Pro / ESM',
        'update_available' => 'update available',
        'not_triaged' => 'not yet assessed by Ubuntu',
    ];

    /** The codename of an Ubuntu release by its VERSION_ID, for agents that do not send the codename. */
    private const UBUNTU_RELEASES = [
        '20.04' => 'focal', '22.04' => 'jammy', '24.04' => 'noble', '24.10' => 'oracular',
        '25.04' => 'plucky', '25.10' => 'questing', '26.04' => 'resolute',
    ];

    /** The codename of a Debian release by its VERSION_ID. */
    private const DEBIAN_RELEASES = ['10' => 'buster', '11' => 'bullseye', '12' => 'bookworm', '13' => 'trixie', '14' => 'forky'];

    /** The release of a device, from its os item (the codename, or the version of Ubuntu / Debian). */
    public static function release(?array $os): ?string
    {
        if ($os === null) {
            return null;
        }
        $codename = strtolower(trim((string) ($os['Codename'] ?? '')));
        $version = trim((string) ($os['Version'] ?? ''));

        return $codename !== '' ? $codename : (strtolower((string) ($os['Id'] ?? '')) === 'debian' ? (self::DEBIAN_RELEASES[$version] ?? null) : (self::UBUNTU_RELEASES[$version] ?? null));
    }

    /**
     * A package of a kernel build (linux-image-7.0.0-22-generic, linux-headers-7.0.0-22, linux-tools-7.0.0-22) other than the one
     * the device runs (`uname -r`, 7.0.0-38-generic): it is not loaded, so the CVEs of the kernel do not make it a finding.
     */
    public static function isOtherKernel(array $item, ?string $running): bool
    {
        if ($running === null || ! preg_match('/^(\d+\.\d+\.\d+-\d+)/', trim($running), $now)) {
            return false;
        }
        if (strtolower((string) ($item['Source'] ?? '')) !== 'dpkg'
            || ! preg_match('/^linux-(?:image|image-unsigned|modules|modules-extra|headers|tools|cloud-tools|buildinfo)-(\d+\.\d+\.\d+-\d+)(?:-.+)?$/', (string) ($item['Name'] ?? ''), $built)) {
            return false;
        }

        return $built[1] !== $now[1];
    }

    /** Which tracker of the CVEs speaks for the distribution of the device: debian, ubuntu (also when it is not known), null for another one. */
    private static function trackerOf(?string $osId): ?string
    {
        $osId = strtolower(trim((string) $osId));

        return match (true) {
            $osId === 'debian' => 'debian',
            $osId === '' || $osId === 'ubuntu' => 'ubuntu',
            default => null,
        };
    }

    /** The name a tracker knows a source package by: the signed kernel packages are built from the unsigned one (linux-signed-aws is linux-aws). */
    public static function trackerSource(string $source): string
    {
        return preg_replace('/^linux-signed(?:-(?:amd64|arm64|i386))?/', 'linux', strtolower(trim($source))) ?? $source;
    }

    /**
     * The CVEs of a rule by what they are about, so that a rule of thousands of CVEs of a tracker (each about a few source
     * packages, no ranges) is not evaluated for every package: the ones with ranges are for every package, the others for the
     * source packages named in their tracker data.
     *
     * @param  array<int, array>  $cves
     * @return array{generic: array<int, array>, by: array<string, array<int, array>>}
     */
    public static function index(array $cves): array
    {
        $index = ['generic' => [], 'by' => []];
        foreach ($cves as $cve) {
            if (! empty($cve['ranges'])) {
                $index['generic'][] = $cve;

                continue;
            }
            foreach (['ubuntu', 'debian'] as $tracker) {
                foreach (array_keys($cve[$tracker] ?? []) as $names) {
                    foreach (explode(',', (string) $names) as $source) {
                        // By id: a CVE that both trackers name the source of is one entry.
                        $index['by'][self::trackerSource($source)][$cve['id'] ?? count($index['by'])] = $cve;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * The CVEs of a rule that affect an item.
     *
     * @param  array<int, array>  $cves
     * @return array<string, array{id: string, severity: string, status: string, fixed: ?string}> by CVE id
     */
    public static function affecting(array $cves, array $item, ?string $release, ?string $osId = null, ?array $index = null): array
    {
        if ($index !== null) {
            $source = self::trackerSource((string) (($item['SourcePackage'] ?? '') !== '' ? $item['SourcePackage'] : ($item['Name'] ?? '')));
            $cves = [...$index['generic'], ...array_values($index['by'][$source] ?? [])];
        }
        $found = [];
        $dpkg = strtolower((string) ($item['Source'] ?? '')) === 'dpkg';
        $changelog = strtolower((string) ($item['FixedCves'] ?? ''));
        foreach ($cves as $cve) {
            if (! isset($cve['id'])) {
                continue;
            }
            if ($dpkg && $changelog !== '' && str_contains($changelog, ','.strtolower($cve['id']).',')) {
                continue;
            }
            $result = $dpkg ? self::dpkg($cve, $item, $release, $osId) : self::upstream($cve, $item);
            if ($result !== null) {
                $found[$cve['id']] = ['id' => $cve['id'], 'severity' => $cve['severity'] ?? 'medium'] + $result;
            }
        }

        return $found;
    }

    /** @return array{status: string, fixed: ?string}|null null when the package is not affected */
    private static function dpkg(array $cve, array $item, ?string $release, ?string $osId): ?array
    {
        $version = trim((string) ($item['Version'] ?? ''));
        $candidate = trim((string) ($item['Candidate'] ?? ''));
        $origin = strtolower(trim((string) ($item['Origin'] ?? '')));
        $source = self::trackerSource((string) (($item['SourcePackage'] ?? '') !== '' ? $item['SourcePackage'] : ($item['Name'] ?? '')));
        $distribution = in_array($origin, ['', 'ubuntu', 'debian'], true);

        $trackerKey = self::trackerOf($osId);
        if ($distribution && $release !== null && $trackerKey !== null) {
            $known = self::tracker($cve[$trackerKey] ?? [], $source);
            if ($known !== null) {
                // The tracker knows the package: it decides (a release it has no line for does not have the package).
                $line = $known[$release] ?? null;
                if ($line === null) {
                    return null;
                }
                [$status, $fixed] = [strtolower((string) ($line[0] ?? '')), trim((string) ($line[1] ?? ''))];
                if ($status === 'not-affected') {
                    return null;
                }
                if ($status === 'released' && $fixed !== '') {
                    if ($version !== '' && DpkgVersion::compare($version, $fixed) >= 0) {
                        return null;
                    }
                    if ($candidate !== '' && DpkgVersion::compare($candidate, $fixed) >= 0) {
                        return ['status' => 'update_available', 'fixed' => $fixed];
                    }

                    return ['status' => stripos($fixed, 'esm') !== false ? 'fix_via_pro' : 'update_available', 'fixed' => $fixed];
                }

                // Ubuntu has not looked at it yet: no fix, but no word that the package is affected either.
                return ['status' => $status === 'needs-triage' ? 'not_triaged' : 'no_fix', 'fixed' => null];
            }
        }

        // No word of the distribution: the project's own versions (NVD), without the epoch and Debian revision.
        $upstream = DpkgVersion::upstream($version);
        if ($upstream === '' || ! self::inRanges($cve['ranges'] ?? [], $upstream, fn ($a, $b) => DpkgVersion::compare($a, $b))) {
            return null;
        }
        $fixed = self::fixedIn($cve['ranges'] ?? [], $upstream, fn ($a, $b) => DpkgVersion::compare($a, $b));
        if ($candidate !== '' && DpkgVersion::compare($candidate, $version) > 0
            && ! self::inRanges($cve['ranges'] ?? [], DpkgVersion::upstream($candidate), fn ($a, $b) => DpkgVersion::compare($a, $b))) {
            return ['status' => 'update_available', 'fixed' => $fixed];
        }

        return ['status' => $origin === 'dpkg/status' ? 'no_source' : 'no_fix', 'fixed' => $fixed];
    }

    /** @return array{status: string, fixed: ?string}|null */
    private static function upstream(array $cve, array $item): ?array
    {
        $version = trim((string) ($item['Version'] ?? ''));
        $order = fn ($a, $b) => SecurityRules::versionOrder($a, $b) ?? 0;
        if ($version === '' || SecurityRules::versionOrder($version, '0') === null || ! self::inRanges($cve['ranges'] ?? [], $version, $order)) {
            return null;
        }

        $fixed = self::fixedIn($cve['ranges'] ?? [], $version, $order);
        // A snap follows a channel (5.0/stable): the agent says which version a refresh brings (Candidate). One that is not
        // fixed, or none, means the channel has no fix - a newer track has (agents 1.28.0+ send the channel).
        if (strtolower((string) ($item['Source'] ?? '')) === 'snap' && trim((string) ($item['Channel'] ?? '')) !== '') {
            $candidate = trim((string) ($item['Candidate'] ?? ''));
            if ($candidate !== '' && ! self::inRanges($cve['ranges'] ?? [], $candidate, $order)) {
                return ['status' => 'update_available', 'fixed' => $fixed];
            }

            return ['status' => 'no_fix', 'fixed' => $fixed];
        }

        return ['status' => 'update_available', 'fixed' => $fixed];
    }

    /**
     * The lines of the tracker for a source package (by name, without case), null when it says nothing of it. A key may name several
     * source packages with commas ("linux,linux-aws,linux-gcp": the same lines for each, as a kernel CVE has them).
     */
    private static function tracker(array $sources, string $source): ?array
    {
        foreach ($sources as $names => $releases) {
            if (! is_array($releases) || $releases === []) {
                continue;
            }
            foreach (explode(',', (string) $names) as $name) {
                if (self::trackerSource($name) === $source) {
                    return array_change_key_case($releases);
                }
            }
        }

        return null;
    }

    /** Whether the version is inside one of the ranges: from start (including) to end_excl (excluding) or end_incl. */
    private static function inRanges(array $ranges, string $version, callable $compare): bool
    {
        foreach ($ranges as $range) {
            [$start, $excluding, $including] = [$range[0] ?? null, $range[1] ?? null, $range[2] ?? null];
            if ($start && $compare($version, (string) $start) < 0) {
                continue;
            }
            if ($excluding ? $compare($version, (string) $excluding) < 0 : ($including && $compare($version, (string) $including) <= 0)) {
                return true;
            }
        }

        return false;
    }

    /** The version that fixes the CVE for this version: the end of the range it is in (null when only an including end is known). */
    private static function fixedIn(array $ranges, string $version, callable $compare): ?string
    {
        foreach ($ranges as $range) {
            $excluding = $range[1] ?? null;
            if ($excluding && ($range[0] ?? null ? $compare($version, (string) $range[0]) >= 0 : true) && $compare($version, (string) $excluding) < 0) {
                return (string) $excluding;
            }
        }

        return null;
    }

    /** The most urgent status of the CVEs (null for none). */
    public static function status(array $affecting): ?string
    {
        foreach (array_keys(self::STATUSES) as $status) {
            foreach ($affecting as $cve) {
                if ($cve['status'] === $status) {
                    return $status;
                }
            }
        }

        return null;
    }

    /** The most severe severity of the CVEs. */
    public static function severity(array $affecting, string $default = 'medium'): string
    {
        $best = null;
        foreach ($affecting as $cve) {
            if (isset(SecurityRules::SEVERITIES[$cve['severity']]) && ($best === null || SecurityRules::SEVERITIES[$cve['severity']] < SecurityRules::SEVERITIES[$best])) {
                $best = $cve['severity'];
            }
        }

        return $best ?? $default;
    }

    /**
     * What to do about it, by where the package comes from: one line per kind of action that is needed.
     *
     * @param  array<int, array>  $items  the binary packages of the finding
     */
    public static function remediation(array $items, array $affecting): string
    {
        $statuses = array_unique(array_column($affecting, 'status'));
        $names = implode(' ', array_unique(array_filter(array_map(fn ($item) => (string) ($item['Name'] ?? ''), $items))));
        $first = $items[0] ?? [];
        $source = strtolower((string) ($first['Source'] ?? ''));
        $lines = [];
        if (in_array('update_available', $statuses, true)) {
            $lines[] = match ($source) {
                'dpkg' => 'Update: sudo apt update && sudo apt full-upgrade',
                'snap' => 'Update: sudo snap refresh '.($first['Name'] ?? ''),
                'flatpak' => 'Update: flatpak update',
                default => 'Update the software to a fixed version, see the vendor advisory.',
            };
        }
        if (in_array('fix_via_pro', $statuses, true)) {
            $lines[] = 'The fix is only in Ubuntu Pro (ESM): attach the machine (sudo pro attach) and run sudo apt update && sudo apt full-upgrade.';
        }
        if (in_array('no_source', $statuses, true)) {
            $lines[] = 'The package is not from any repository: download a newer version from the vendor (or add the vendor\'s repository so that it updates).';
        }
        if (in_array('no_fix', $statuses, true) && $source === 'snap') {
            $name = (string) ($first['Name'] ?? '');
            $lines[] = 'The channel of the snap ('.($first['Channel'] ?? '').') has no fixed version: switch to a newer track that has it (snap info '.$name.' lists them; sudo snap refresh '.$name.' --channel=<track>/stable), or remove the snap if it is not needed.';
        } elseif (in_array('no_fix', $statuses, true)) {
            $line = 'The distribution or vendor has not released a fix yet: uninstall '.($names !== '' ? $names : 'it').' if it is not needed, use Ubuntu Pro if it covers the package, or restrict its use';
            $line .= preg_match('/magick/i', (string) ($first['SourcePackage'] ?? $first['Name'] ?? '')) ? ' (policy.xml of ImageMagick).' : '.';
            $lines[] = $line;
        }

        if ($statuses === ['not_triaged']) {
            $lines[] = 'Ubuntu has not assessed whether this affects the package yet: nothing to do until it does, keep the system updated.';
        }

        return implode("\n", $lines);
    }
}
