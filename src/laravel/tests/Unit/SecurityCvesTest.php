<?php

namespace Tests\Unit;

use App\Support\DpkgVersion;
use App\Support\SecurityCves;
use PHPUnit\Framework\TestCase;

/** A1, A2, A4: the CVEs of a package one at a time, from the distribution's word before the project's. */
class SecurityCvesTest extends TestCase
{
    private function cve(string $id, array $ranges = [], array $ubuntu = [], string $severity = 'high'): array
    {
        return ['id' => $id, 'severity' => $severity, 'ranges' => $ranges, 'ubuntu' => $ubuntu];
    }

    private function ids(array $affecting): array
    {
        return array_keys($affecting);
    }

    public function test_dpkg_order_is_the_one_of_dpkg(): void
    {
        $this->assertLessThan(0, DpkgVersion::compare('1.0~rc1-1', '1.0-1'));
        $this->assertGreaterThan(0, DpkgVersion::compare('1:0.9-1', '2.0-1'));
        $this->assertSame(0, DpkgVersion::compare('8:7.1.2.3+dfsg1-1ubuntu0.1', '8:7.1.2.3+dfsg1-1ubuntu0.1'));
        $this->assertLessThan(0, DpkgVersion::compare('2.7.4-1ubuntu0.2', '2.7.4-1ubuntu0.10'));
        $this->assertGreaterThan(0, DpkgVersion::compare('1.0-1', '1.0'));
    }

    public function test_upstream_has_no_epoch_revision_or_repackaging(): void
    {
        $this->assertSame('156.0.8078.47', DpkgVersion::upstream('156.0.8078.47-1'));
        $this->assertSame('7.1.2.3', DpkgVersion::upstream('8:7.1.2.3+dfsg1-1ubuntu0.1'));
        $this->assertSame('3.5.5', DpkgVersion::upstream('3.5.5-1ubuntu3.7'));
        $this->assertSame('2.80.0', DpkgVersion::upstream('2:2.80.0-6ubuntu3.9'));
        $this->assertSame('1.2.3', DpkgVersion::upstream('1.2.3'));
    }

    public function test_a_package_at_the_fixed_version_is_not_vulnerable_only_a_lower_one_is(): void
    {
        $cves = [
            $this->cve('CVE-2026-1', [[null, '156.0.8078.47', null]]),
            $this->cve('CVE-2026-2', [[null, '156.0.8087.47', null]]),
        ];
        $chrome = ['Name' => 'google-chrome-stable', 'Version' => '156.0.8078.47-1', 'Source' => 'dpkg', 'Origin' => 'Google LLC'];

        $this->assertSame(['CVE-2026-2'], $this->ids(SecurityCves::affecting($cves, $chrome, 'noble')), 'equal to the fix: not vulnerable');
        $this->assertSame(['CVE-2026-1', 'CVE-2026-2'], $this->ids(SecurityCves::affecting($cves, ['Version' => '156.0.8078.46-1'] + $chrome, 'noble')));
        $this->assertSame([], $this->ids(SecurityCves::affecting($cves, ['Version' => '156.0.8087.47-1'] + $chrome, 'noble')));
    }

    public function test_every_cve_is_evaluated_with_its_own_range_including_windows_programs(): void
    {
        $cves = [$this->cve('CVE-1', [['1.0', '2.0', null]]), $this->cve('CVE-2', [[null, null, '3.0']])];
        $this->assertSame(['CVE-1', 'CVE-2'], $this->ids(SecurityCves::affecting($cves, ['Name' => 'x', 'Version' => '1.5', 'Source' => 'registry'], null)));
        $this->assertSame(['CVE-2'], $this->ids(SecurityCves::affecting($cves, ['Name' => 'x', 'Version' => '2.0', 'Source' => 'registry'], null)));
        $this->assertSame([], $this->ids(SecurityCves::affecting($cves, ['Name' => 'x', 'Version' => '3.0.1', 'Source' => 'registry'], null)));
    }

    public function test_a_cve_the_changelog_names_is_fixed(): void
    {
        $cves = [$this->cve('CVE-2026-1', [[null, '9.0', null]]), $this->cve('CVE-2026-2', [[null, '9.0', null]])];
        $item = ['Name' => 'libfoo', 'Version' => '1.0-1ubuntu0.1', 'Source' => 'dpkg', 'FixedCves' => ',CVE-2026-1,'];

        $this->assertSame(['CVE-2026-2'], $this->ids(SecurityCves::affecting($cves, $item, null)));
        $this->assertSame([], $this->ids(SecurityCves::affecting($cves, ['FixedCves' => ',CVE-2026-1,CVE-2026-2,'] + $item, null)));
    }

    public function test_imagemagick_with_backports_has_no_finding_for_the_fixed_cves(): void
    {
        // G6 (Ubuntu): the changelog names the CVEs, the version 8:7.1.2.3+dfsg1-1ubuntu0.1 is below the upstream fix.
        $ranges = [[null, '7.1.2.10', null]];
        $cves = [$this->cve('CVE-2026-100', $ranges), $this->cve('CVE-2026-101', $ranges)];
        $item = ['Name' => 'imagemagick', 'Version' => '8:7.1.2.3+dfsg1-1ubuntu0.1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu', 'FixedCves' => ',CVE-2026-100,CVE-2026-101,'];

        $this->assertSame([], $this->ids(SecurityCves::affecting($cves, $item, 'resolute')));
    }

    public function test_the_ubuntu_tracker_decides_for_a_package_of_ubuntu(): void
    {
        $tracker = fn (array $lines) => ['imagemagick' => $lines];
        $item = ['Name' => 'imagemagick', 'Version' => '8:7.1.2.3+dfsg1-1ubuntu0.1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu', 'Candidate' => '8:7.1.2.3+dfsg1-1ubuntu0.1'];
        $cve = fn (array $lines) => $this->cve('CVE-1', [[null, '99.0', null]], $tracker($lines));

        $this->assertSame([], SecurityCves::affecting([$cve(['resolute' => ['released', '8:7.1.2.3+dfsg1-1ubuntu0.1']])], $item, 'resolute'), 'fixed in the distribution');
        $this->assertSame([], SecurityCves::affecting([$cve(['resolute' => ['not-affected', '']])], $item, 'resolute'));
        $this->assertSame([], SecurityCves::affecting([$cve(['noble' => ['needed', '']])], $item, 'resolute'), 'no line for the release');

        $newer = SecurityCves::affecting([$cve(['resolute' => ['released', '8:7.1.2.3+dfsg1-1ubuntu0.2']])], ['Candidate' => '8:7.1.2.3+dfsg1-1ubuntu0.2'] + $item, 'resolute');
        $this->assertSame('update_available', $newer['CVE-1']['status']);

        $pro = SecurityCves::affecting([$cve(['resolute' => ['released', '8:7.1.2.3+dfsg1-1ubuntu0.2~esm1']])], $item, 'resolute');
        $this->assertSame('fix_via_pro', $pro['CVE-1']['status']);

        $none = SecurityCves::affecting([$cve(['resolute' => ['needed', '']])], $item, 'resolute');
        $this->assertSame('no_fix', $none['CVE-1']['status']);
    }

    public function test_g8_imagemagick_with_13_cves_not_in_the_changelog_is_no_fix_available_yet(): void
    {
        $cves = [];
        for ($i = 1; $i <= 13; $i++) {
            $cves[] = $this->cve("CVE-2026-2{$i}", [[null, '7.1.2.30', null]], ['imagemagick' => ['resolute' => ['needed', '']]], 'medium');
        }
        $item = ['Name' => 'imagemagick', 'Version' => '8:7.1.2.18+dfsg1-1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu', 'Candidate' => '8:7.1.2.18+dfsg1-1'];
        $affecting = SecurityCves::affecting($cves, $item, 'resolute');

        $this->assertCount(13, $affecting);
        $this->assertSame('no_fix', SecurityCves::status($affecting));
        $text = SecurityCves::remediation([$item], $affecting);
        $this->assertStringContainsString('uninstall imagemagick', $text);
        $this->assertStringContainsString('Ubuntu Pro', $text);
        $this->assertStringContainsString('policy.xml', $text);
    }

    public function test_a_package_that_comes_from_no_repository_has_no_update_source(): void
    {
        $cves = [$this->cve('CVE-1', [[null, '15.60.0', null]])];
        $item = ['Name' => 'teamviewer', 'Version' => '15.59.3', 'Source' => 'dpkg', 'Origin' => 'dpkg/status'];
        $affecting = SecurityCves::affecting($cves, $item, 'resolute');

        $this->assertSame('no_source', $affecting['CVE-1']['status']);
        $this->assertStringContainsString('vendor', SecurityCves::remediation([$item], $affecting));
    }

    public function test_remediation_by_source(): void
    {
        // A4: PowerShell 7.6.6 from snap, CVE-2026-96251 and CVE-2026-96303 fixed in 7.6.7.
        $cves = [$this->cve('CVE-2026-96251', [[null, '7.6.7', null]]), $this->cve('CVE-2026-96303', [[null, '7.6.7', null]])];
        $snap = ['Name' => 'powershell', 'Version' => '7.6.6', 'Source' => 'snap', 'Channel' => 'latest/stable', 'Candidate' => '7.6.7'];
        $affecting = SecurityCves::affecting($cves, $snap, 'resolute');

        $this->assertCount(2, $affecting);
        $this->assertSame('Update: sudo snap refresh powershell', SecurityCves::remediation([$snap], $affecting));
        $this->assertSame([], SecurityCves::affecting($cves, ['Version' => '7.6.7'] + $snap, 'resolute'));

        $deb = ['Name' => 'foo', 'Version' => '1.0-1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu', 'Candidate' => '1.2-1'];
        $update = SecurityCves::affecting([$this->cve('CVE-9', [[null, '1.1', null]])], $deb, 'noble');
        $this->assertSame('Update: sudo apt update && sudo apt full-upgrade', SecurityCves::remediation([$deb], $update));
    }

    public function test_a_snap_whose_channel_has_no_fix_is_told_to_change_the_track(): void
    {
        // LXD 5.0.10 from 5.0/stable: the advisory fixes 5.21.4 and 6.5, the 5.0 track is not patched, so a refresh brings nothing.
        $cves = [$this->cve('CVE-2025-54289', [['4.0.0', '5.21.4', null], ['6.1', '6.5', null]])];
        $lxd = ['Name' => 'lxd', 'Version' => '5.0.10-24fe763', 'Source' => 'snap', 'Channel' => '5.0/stable/…'];

        $affecting = SecurityCves::affecting($cves, $lxd, 'jammy');
        $this->assertSame('no_fix', $affecting['CVE-2025-54289']['status']);
        $text = SecurityCves::remediation([$lxd], $affecting);
        $this->assertStringContainsString('--channel=', $text);
        $this->assertStringNotContainsString('Update: sudo snap refresh', $text);

        // The same snap with a refresh that brings a fixed version, and one that brings another vulnerable one.
        $this->assertSame('update_available', SecurityCves::affecting($cves, ['Candidate' => '5.21.4'] + $lxd, 'jammy')['CVE-2025-54289']['status']);
        $this->assertSame('no_fix', SecurityCves::affecting($cves, ['Candidate' => '5.21.2'] + $lxd, 'jammy')['CVE-2025-54289']['status']);
        // An agent that does not send the channel cannot tell: an update is assumed.
        $this->assertSame('update_available', SecurityCves::affecting($cves, ['Channel' => null] + $lxd, 'jammy')['CVE-2025-54289']['status']);
    }

    public function test_a_cve_ubuntu_has_not_triaged_is_the_least_urgent(): void
    {
        $cve = $this->cve('CVE-2026-0864', [[null, '3.10.21', null]], ['python3.10' => ['jammy' => ['needs-triage', '']]]);
        $item = ['Name' => 'python3.10', 'Version' => '3.10.12-1~22.04.18', 'Source' => 'dpkg', 'Origin' => 'Ubuntu'];

        $affecting = SecurityCves::affecting([$cve], $item, 'jammy');
        $this->assertSame('not_triaged', $affecting['CVE-2026-0864']['status']);
        $this->assertSame('not_triaged', SecurityCves::status($affecting));
        $this->assertStringContainsString('has not assessed', SecurityCves::remediation([$item], $affecting));
        // A CVE with a fix to install is more urgent than one not triaged.
        $mixed = $affecting + ['B' => ['id' => 'B', 'severity' => 'low', 'status' => 'update_available', 'fixed' => null]];
        $this->assertSame('update_available', SecurityCves::status($mixed));
    }

    public function test_a_debian_host_is_judged_by_the_debian_tracker_not_the_ubuntu_one(): void
    {
        $cve = ['id' => 'CVE-1', 'severity' => 'high', 'ranges' => [[null, '9.0', null]],
            'ubuntu' => ['foo' => ['noble' => ['needed', '']]],
            'debian' => ['foo' => ['bookworm' => ['released', '1.0-2+deb12u1'], 'trixie' => ['needed', '']]]];
        $debian = ['Name' => 'foo', 'Version' => '1.0-1+deb12u1', 'Source' => 'dpkg', 'Origin' => 'Debian'];

        // bookworm has the fix in a later revision: update available (before: the Ubuntu data had no line for bookworm and the CVE was lost).
        $this->assertSame('update_available', SecurityCves::affecting([$cve], ['Candidate' => '1.0-2+deb12u1'] + $debian, 'bookworm', 'debian')['CVE-1']['status']);
        $this->assertSame([], SecurityCves::affecting([$cve], ['Version' => '1.0-2+deb12u1'] + $debian, 'bookworm', 'debian'));
        $this->assertSame('no_fix', SecurityCves::affecting([$cve], $debian, 'trixie', 'debian')['CVE-1']['status']);
        // The same package on Ubuntu noble is judged by the Ubuntu data.
        $this->assertSame('no_fix', SecurityCves::affecting([$cve], ['Origin' => 'Ubuntu'] + $debian, 'noble', 'ubuntu')['CVE-1']['status']);
    }

    public function test_a_derivative_or_a_debian_without_tracker_data_is_judged_by_the_nvd_ranges(): void
    {
        $cve = ['id' => 'CVE-1', 'severity' => 'high', 'ranges' => [[null, '9.0', null]], 'ubuntu' => ['foo' => ['noble' => ['needed', '']]]];
        $item = ['Name' => 'foo', 'Version' => '1.0-1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu'];

        $this->assertCount(1, SecurityCves::affecting([$cve], $item, 'wilma', 'linuxmint'), 'Mint: the Ubuntu data is not its');
        $this->assertCount(1, SecurityCves::affecting([$cve], ['Origin' => 'Debian'] + $item, 'bookworm', 'debian'), 'no Debian data: the NVD decides');
        $this->assertSame([], SecurityCves::affecting([$cve], ['Version' => '9.1-1'] + $item, 'wilma', 'linuxmint'));
        $this->assertSame('bookworm', SecurityCves::release(['Id' => 'debian', 'Version' => '12']));
        $this->assertSame('trixie', SecurityCves::release(['Id' => 'debian', 'Version' => '13', 'Codename' => null]));
    }

    public function test_a_kernel_that_is_installed_but_not_running_is_not_judged(): void
    {
        $item = fn (string $name) => ['Name' => $name, 'Version' => '7.0.0-22.22', 'Source' => 'dpkg'];
        $running = '7.0.0-38-generic';
        foreach (['linux-image-7.0.0-22-generic', 'linux-headers-7.0.0-22', 'linux-tools-7.0.0-22-generic', 'linux-modules-7.0.0-22-generic'] as $name) {
            $this->assertTrue(SecurityCves::isOtherKernel($item($name), $running), $name);
        }
        foreach (['linux-image-7.0.0-38-generic', 'linux-headers-7.0.0-38', 'linux-libc-dev', 'linux-tools-common', 'bpftool', 'openssl'] as $name) {
            $this->assertFalse(SecurityCves::isOtherKernel($item($name), $running), $name);
        }
        $this->assertFalse(SecurityCves::isOtherKernel($item('linux-image-7.0.0-22-generic'), null), 'the running kernel is not known');
    }

    public function test_the_signed_kernel_is_judged_by_the_tracker_of_the_unsigned_one(): void
    {
        $this->assertSame('linux', SecurityCves::trackerSource('linux-signed'));
        $this->assertSame('linux', SecurityCves::trackerSource('linux-signed-amd64'));
        $this->assertSame('linux-aws', SecurityCves::trackerSource('linux-signed-aws'));
        $this->assertSame('linux-aws', SecurityCves::trackerSource('Linux-AWS'));
        $this->assertSame('openssl', SecurityCves::trackerSource('openssl'));

        $cve = ['id' => 'CVE-1', 'severity' => 'high', 'ranges' => [], 'ubuntu' => ['linux' => ['jammy' => ['released', '5.15.0-130.140']]]];
        $kernel = ['Name' => 'linux-image-5.15.0-120-generic', 'Version' => '5.15.0-120.130', 'SourcePackage' => 'linux-signed', 'Source' => 'dpkg', 'Origin' => 'Ubuntu'];
        $this->assertCount(1, SecurityCves::affecting([$cve], $kernel, 'jammy', 'ubuntu'));
        $this->assertSame([], SecurityCves::affecting([$cve], ['Version' => '5.15.0-130.140'] + $kernel, 'jammy', 'ubuntu'));
    }

    public function test_a_key_may_name_several_source_packages(): void
    {
        $cve = ['id' => 'CVE-1', 'severity' => 'high', 'ranges' => [], 'ubuntu' => ['linux,linux-aws,linux-azure' => ['jammy' => ['needed', '']]]];
        $kernel = fn (string $source) => ['Name' => 'linux-image-x', 'Version' => '5.15.0-1.1', 'SourcePackage' => $source, 'Source' => 'dpkg', 'Origin' => 'Ubuntu'];

        foreach (['linux', 'linux-aws', 'linux-signed-azure'] as $source) {
            $this->assertCount(1, SecurityCves::affecting([$cve], $kernel($source), 'jammy', 'ubuntu'), $source);
            $this->assertCount(1, SecurityCves::affecting([$cve], $kernel($source), 'jammy', 'ubuntu', SecurityCves::index([$cve])), $source.' by the index');
        }
        $this->assertSame([], SecurityCves::affecting([$cve], $kernel('linux-gcp'), 'jammy', 'ubuntu'));
    }

    public function test_the_index_gives_a_package_only_the_cves_of_its_source_and_the_ones_with_ranges(): void
    {
        $cves = [
            ['id' => 'CVE-A', 'severity' => 'high', 'ranges' => [], 'ubuntu' => ['foo' => ['noble' => ['needed', '']]]],
            ['id' => 'CVE-B', 'severity' => 'high', 'ranges' => [], 'ubuntu' => ['bar' => ['noble' => ['needed', '']]], 'debian' => ['foo' => ['bookworm' => ['needed', '']]]],
            ['id' => 'CVE-C', 'severity' => 'low', 'ranges' => [[null, '9.0', null]]],
        ];
        $index = SecurityCves::index($cves);
        $foo = ['Name' => 'libfoo1', 'SourcePackage' => 'foo', 'Version' => '1.0-1', 'Source' => 'dpkg', 'Origin' => 'Ubuntu'];

        $ids = function (array $found) { $keys = array_keys($found); sort($keys); return $keys; };
        $this->assertSame(['CVE-A', 'CVE-C'], $ids(SecurityCves::affecting($cves, $foo, 'noble', 'ubuntu', $index)), 'CVE-B is about bar on Ubuntu');
        $this->assertSame($ids(SecurityCves::affecting($cves, $foo, 'noble', 'ubuntu')), $ids(SecurityCves::affecting($cves, $foo, 'noble', 'ubuntu', $index)), 'the index changes nothing but the cost');
        $this->assertSame(['CVE-B', 'CVE-C'], $ids(SecurityCves::affecting($cves, ['Origin' => 'Debian'] + $foo, 'bookworm', 'debian', $index)));
    }

    public function test_status_and_severity_of_a_finding_are_the_most_urgent_of_its_cves(): void
    {
        $affecting = [
            'A' => ['id' => 'A', 'severity' => 'low', 'status' => 'update_available', 'fixed' => null],
            'B' => ['id' => 'B', 'severity' => 'critical', 'status' => 'no_fix', 'fixed' => null],
        ];
        $this->assertSame('no_fix', SecurityCves::status($affecting));
        $this->assertSame('critical', SecurityCves::severity($affecting));
        $this->assertNull(SecurityCves::status([]));
    }

    public function test_release_of_a_device(): void
    {
        $this->assertSame('resolute', SecurityCves::release(['Id' => 'ubuntu', 'Version' => '26.04']));
        $this->assertSame('noble', SecurityCves::release(['Codename' => 'Noble', 'Version' => '99']));
        $this->assertNull(SecurityCves::release(null));
    }
}
