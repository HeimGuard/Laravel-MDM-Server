<?php

namespace Tests\Unit;

use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use PHPUnit\Framework\TestCase;

/** A3 (grouping), B1, B2, C1 - C4: the bundled policies and the grouping of findings, on items as agents send them. */
class SecurityPoliciesTest extends TestCase
{
    private function rule(string $key): array
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../../resources/security/rules.json'), true) as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }
        $this->fail("no rule $key");
    }

    private function cveRule(array $cves, string $pattern): array
    {
        $select = ['field' => 'Name', 'op' => 'matches', 'value' => $pattern];

        return [
            'key' => 'org.cve-test', 'name' => 'test', 'severity' => 'medium', 'platform' => 'any', 'source' => 'software',
            'when' => $select, 'select' => $select, 'cves' => $cves,
            'message' => '{Packages}: {CveCount} CVEs ({Status}): {Cves}', 'remediation' => 'Update.',
        ];
    }

    private function cves(int $count, array $ubuntu, array $ranges = [[null, '99.0', null]]): array
    {
        return array_map(fn ($i) => ['id' => 'CVE-2026-'.(1000 + $i), 'severity' => 'medium', 'ranges' => $ranges, 'ubuntu' => $ubuntu], range(1, $count));
    }

    private function deb(string $name, string $version, ?string $source = null, array $more = []): array
    {
        return ['Name' => $name, 'Version' => $version, 'Source' => 'dpkg', 'Origin' => 'Ubuntu', 'SourcePackage' => $source] + $more;
    }

    public function test_the_seven_imagemagick_packages_are_one_finding_with_no_fix_available_yet(): void
    {
        $rule = $this->cveRule($this->cves(13, ['imagemagick' => ['resolute' => ['needed', '']]]), '^(lib)?(imagemagick|magick)');
        $names = ['imagemagick', 'imagemagick-7-common', 'imagemagick-7.q16', 'libmagickcore-7.q16-10', 'libmagickwand-7.q16-10', 'libmagick++-7.q16-5', 'imagemagick-7.q16hdri'];
        $items = array_map(fn ($name) => $this->deb($name, '8:7.1.2.18+dfsg1-1', $name === 'imagemagick' ? null : 'imagemagick'), $names);

        $found = SecurityScanner::matches($rule, $items, ['release' => 'resolute']);

        $this->assertCount(1, $found);
        $finding = array_values($found)[0];
        $this->assertSame(13, $finding['item']['CveCount']);
        $this->assertSame('no fix available yet', $finding['item']['Status']);
        $this->assertStringContainsString('imagemagick-7-common', $finding['item']['Packages']);
        $this->assertStringContainsString('no fix available yet', $finding['message']);
        $this->assertCount(6, $finding['legacy'], 'the per-package findings it replaces (all but the one with the same fingerprint)');
    }

    public function test_libsoup_glib_and_expat_are_one_finding_each(): void
    {
        $cases = [
            'libsoup3' => ['^libsoup', ['libsoup-3.0-0', 'libsoup-3.0-common']],
            'glib2.0' => ['glib', ['libglib2.0-bin', 'libglib2.0-0t64', 'libglib2.0-data']],
            'expat' => ['expat', ['libexpat1', 'libexpat1-dev']],
        ];
        foreach ($cases as $source => [$pattern, $names]) {
            $rule = $this->cveRule($this->cves(2, [$source => ['resolute' => ['needed', '']]]), $pattern);
            $items = array_map(fn ($name) => $this->deb($name, '2.0-1', $source), $names);

            $this->assertCount(1, SecurityScanner::matches($rule, $items, ['release' => 'resolute']), $source);
        }
    }

    public function test_a_group_without_a_cve_left_is_no_finding(): void
    {
        $rule = $this->cveRule($this->cves(2, ['foo' => ['resolute' => ['released', '1.0-2']]]), '^foo');
        $items = [$this->deb('foo', '1.0-2', null), $this->deb('foo-data', '1.0-3', 'foo')];

        $this->assertSame([], SecurityScanner::matches($rule, $items, ['release' => 'resolute']));
    }

    public function test_the_signed_kernel_image_and_its_modules_are_one_finding(): void
    {
        $cve = ['id' => 'CVE-2026-1', 'severity' => 'critical', 'ranges' => [], 'ubuntu' => ['linux' => ['jammy' => ['needed', '']]]];
        $rule = $this->cveRule([$cve], '^linux-');
        $rule['cves'] = [$cve];
        $items = [
            $this->deb('linux-image-5.15.0-198-generic', '5.15.0-198.208', 'linux-signed'),
            $this->deb('linux-headers-5.15.0-198', '5.15.0-198.208', 'linux'),
            $this->deb('linux-modules-5.15.0-198-generic', '5.15.0-198.208', 'linux'),
        ];

        $found = SecurityScanner::matches($rule, $items, ['release' => 'jammy', 'os' => 'ubuntu']);

        $this->assertCount(1, $found);
        $this->assertStringContainsString('linux-image-5.15.0-198-generic', array_values($found)[0]['item']['Packages']);
        $this->assertStringContainsString('linux-modules-5.15.0-198-generic', array_values($found)[0]['item']['Packages']);
        $this->assertStringNotContainsString('linux-headers', array_values($found)[0]['item']['Packages'], 'headers are not loaded');
    }

    public function test_the_fingerprint_of_one_package_is_the_one_it_always_had(): void
    {
        $rule = $this->cveRule($this->cves(1, ['foo' => ['resolute' => ['needed', '']]]), '^foo');
        $item = $this->deb('foo', '1.0-1');

        $found = SecurityScanner::matches($rule, [$item], ['release' => 'resolute']);

        $this->assertSame(SecurityRules::fingerprint($rule, $item), array_key_first($found));
        $this->assertSame([], array_values($found)[0]['legacy']);
    }

    public function test_cves_ubuntu_has_not_assessed_are_a_low_finding(): void
    {
        $cves = $this->cves(2, ['python3.10' => ['jammy' => ['needs-triage', '']]]);
        $cves[0]['severity'] = 'high';
        $rule = $this->cveRule($cves, '^python3');
        $found = SecurityScanner::matches($rule, [$this->deb('python3.10', '3.10.12-1', null)], ['release' => 'jammy']);

        $this->assertSame('low', array_values($found)[0]['severity']);
        $this->assertSame('not yet assessed by Ubuntu', array_values($found)[0]['item']['Status']);

        $cves[1]['ubuntu'] = ['python3.10' => ['jammy' => ['needed', '']]];
        $this->assertSame('medium', array_values(SecurityScanner::matches($this->cveRule($cves, '^python3'), [$this->deb('python3.10', '3.10.12-1', null)], ['release' => 'jammy']))[0]['severity'], 'no fix yet: at most medium');
        $cves[1]['ubuntu'] = ['python3.10' => ['jammy' => ['released', '3.10.12-2']]];
        $this->assertSame('medium', array_values(SecurityScanner::matches($this->cveRule($cves, '^python3'), [$this->deb('python3.10', '3.10.12-1', null)], ['release' => 'jammy']))[0]['severity']);
    }

    public function test_the_severity_of_a_cve_finding_is_the_one_of_its_worst_cve(): void
    {
        $cves = $this->cves(2, ['foo' => ['resolute' => ['released', '1.0-2']]]);
        $cves[1]['severity'] = 'critical';
        $found = SecurityScanner::matches($this->cveRule($cves, '^foo'), [$this->deb('foo', '1.0-1')], ['release' => 'resolute']);

        $this->assertSame('critical', array_values($found)[0]['severity']);

        $cves = $this->cves(2, ['foo' => ['resolute' => ['needed', '']]]);
        $cves[1]['severity'] = 'critical';
        $found = SecurityScanner::matches($this->cveRule($cves, '^foo'), [$this->deb('foo', '1.0-1')], ['release' => 'resolute']);

        $this->assertSame('medium', array_values($found)[0]['severity'], 'a CVE without a fix is at most medium: there is nothing to do but wait');
    }

    public function test_nmap_nmap_common_and_zenmap_are_one_finding_and_netcat_openbsd_none(): void
    {
        $rule = $this->rule('software.hacking-tools');
        $this->assertSame('info', $rule['severity']);
        $dpkg = fn ($name) => ['Name' => $name, 'Version' => '7.98', 'Source' => 'dpkg'];

        $found = SecurityScanner::matches($rule, [$dpkg('nmap'), $dpkg('nmap-common'), $dpkg('zenmap'), $dpkg('netcat-openbsd'), $dpkg('wireshark'), $dpkg('wireshark-common')]);

        $this->assertCount(2, $found);
        $groups = array_map(fn ($finding) => $finding['item']['Group'], array_values($found));
        sort($groups);
        $this->assertSame(['nmap', 'wireshark'], $groups);
        $this->assertSame([], SecurityScanner::matches($rule, [$dpkg('netcat-openbsd')]));
        $this->assertNotEmpty(SecurityScanner::matches($rule, [$dpkg('netcat-traditional')]));
        $this->assertContains('developer', $rule['allow_tags']);
    }

    public function test_remote_access_severity_follows_what_it_does(): void
    {
        $tool = ['Name' => 'teamviewer', 'Version' => '15.59.3', 'Source' => 'dpkg'];
        $keys = fn (array $item) => array_values(array_filter(
            ['software.remote-access', 'software.remote-access-running', 'software.remote-access-running-outdated'],
            fn ($key) => SecurityScanner::matches($this->rule($key), [$item]) !== [],
        ));

        $this->assertSame(['software.remote-access'], $keys($tool), 'installed, not running: low');
        $this->assertSame(['software.remote-access'], $keys($tool + ['Running' => false]));
        $this->assertSame(['software.remote-access-running'], $keys($tool + ['Running' => true, 'RunUser' => 'root', 'Listening' => true, 'UpdateAvailable' => false, 'NoUpdateSource' => false]), 'a service: medium');
        $this->assertSame(['software.remote-access-running-outdated'], $keys($tool + ['Running' => true, 'RunUser' => 'root', 'NoUpdateSource' => true]), 'no source of updates: high');
        $this->assertSame(['software.remote-access-running-outdated'], $keys($tool + ['Running' => true, 'UpdateAvailable' => true]));
        $this->assertSame('low', $this->rule('software.remote-access')['severity']);
        $this->assertSame('medium', $this->rule('software.remote-access-running')['severity']);
        $this->assertSame('high', $this->rule('software.remote-access-running-outdated')['severity']);

        $found = array_values(SecurityScanner::matches($this->rule('software.remote-access-running-outdated'), [$tool + ['Running' => true, 'RunUser' => 'root', 'Listening' => true, 'NoUpdateSource' => true, 'Origin' => 'dpkg/status']]));
        $this->assertStringContainsString('user root', $found[0]['message']);
        $this->assertStringContainsString('15.59.3', $found[0]['message']);
    }

    public function test_a_package_without_a_source_of_updates(): void
    {
        $quiet = ['Name' => 'somevendor-tool', 'Version' => '1.0', 'Source' => 'dpkg', 'NoUpdateSource' => true];
        $service = $quiet + ['Running' => true, 'Listening' => true, 'RunUser' => 'root'];

        $this->assertNotEmpty(SecurityScanner::matches($this->rule('software.no-update-source'), [$quiet]));
        $this->assertSame([], SecurityScanner::matches($this->rule('software.no-update-source'), [$service]));
        $this->assertNotEmpty(SecurityScanner::matches($this->rule('software.no-update-source-service'), [$service]));
        $this->assertSame([], SecurityScanner::matches($this->rule('software.no-update-source-service'), [$quiet]));
        $this->assertSame([], SecurityScanner::matches($this->rule('software.no-update-source'), [['Name' => 'teamviewer'] + $quiet]), 'remote access tools have their own rules');
        $this->assertSame('low', $this->rule('software.no-update-source')['severity']);

        // One finding for all of them: a Docker repository that an upgrade switched off leaves a dozen packages at once.
        $many = array_map(fn ($name) => ['Name' => $name] + $quiet, ['docker-ce', 'docker-ce-cli', 'containerd.io']);
        $found = array_values(SecurityScanner::matches($this->rule('software.no-update-source'), $many));
        $this->assertCount(1, $found);
        $this->assertSame('Packages without a source of updates (3): docker-ce, docker-ce-cli, containerd.io', $found[0]['message']);
        $this->assertSame([], SecurityScanner::matches($this->rule('software.no-update-source'), []));
        $this->assertSame('medium', $this->rule('software.no-update-source-service')['severity']);
    }

    public function test_root_is_not_one_of_the_local_administrators(): void
    {
        $rule = $this->rule('admins.many');
        $admin = fn (string $name) => ['Name' => $name, 'Source' => 'group sudo', 'Enabled' => true];
        $root = ['Name' => 'root', 'Source' => 'uid 0', 'Enabled' => true];

        $this->assertSame([], SecurityScanner::matches($rule, [$root, $admin('jakub'), $admin('vasek')]), 'two people and root are fine');
        $found = array_values(SecurityScanner::matches($rule, [$root, $admin('jakub'), $admin('vasek'), $admin('karel')]));
        $this->assertCount(1, $found);
        $this->assertSame('3 enabled administrators: jakub, vasek, karel', $found[0]['message']);
        $this->assertSame('medium', $rule['severity']);
    }

    public function test_the_headers_and_tools_of_the_kernel_are_not_a_finding_of_the_kernel_cves(): void
    {
        $ubuntu = ['linux' => ['resolute' => ['needed', '']]];
        $rule = $this->cveRule($this->cves(2, $ubuntu), '^(linux|bpftool)');
        $kernel = fn (string $name, string $source) => $this->deb($name, '7.0.0-38.38', $source);
        $items = [
            $kernel('linux-image-7.0.0-38-generic', 'linux-signed'), $kernel('linux-modules-7.0.0-38-generic', 'linux'),
            $kernel('linux-headers-7.0.0-38-generic', 'linux'), $kernel('linux-tools-common', 'linux'), $kernel('bpftool', 'linux'), $kernel('linux-libc-dev', 'linux'),
        ];
        $found = array_values(SecurityScanner::matches($rule, $items, ['release' => 'resolute', 'os' => 'ubuntu', 'kernel' => '7.0.0-38-generic']));

        $this->assertCount(1, $found);
        $this->assertSame('linux-image-7.0.0-38-generic 7.0.0-38.38, linux-modules-7.0.0-38-generic 7.0.0-38.38', $found[0]['item']['Packages']);
    }

    public function test_a_failed_apt_update_is_a_high_finding_naming_the_repositories(): void
    {
        $rule = $this->rule('posture.apt-update-failed');
        $posture = ['AptUpdateOk' => false, 'AptUpdateErrors' => "E: The repository 'http://deb.anydesk.com all InRelease' is not signed.\nE: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release' does not have a Release file."];

        $found = array_values(SecurityScanner::matches($rule, [$posture]));

        $this->assertSame('high', $rule['severity']);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('deb.anydesk.com (not signed', $found[0]['message']);
        $this->assertStringContainsString('ondrej/php', $found[0]['message']);
        $this->assertStringContainsString('Signed-By', $found[0]['item']['Remediation']);
        $this->assertSame([], SecurityScanner::matches($rule, [['AptUpdateOk' => true]]));
        $this->assertSame([], SecurityScanner::matches($rule, [[]]), 'an agent that does not report it');
    }

    public function test_firewall_and_encryption_findings_say_what_is_missing(): void
    {
        $firewall = array_values(SecurityScanner::matches($this->rule('posture.firewall-linux'), [['FirewallEnabled' => false, 'FirewallDetail' => 'ufw is installed but inactive: sudo ufw enable']]));
        $this->assertStringContainsString('sudo ufw enable', $firewall[0]['message']);

        $disk = array_values(SecurityScanner::matches($this->rule('posture.disk-encryption'), [['DiskEncrypted' => false, 'UnencryptedMounts' => '/, /home']]));
        $this->assertStringContainsString('/, /home', $disk[0]['message']);
        $this->assertSame([], SecurityScanner::matches($this->rule('posture.disk-encryption'), [['DiskEncrypted' => true]]));
    }

    public function test_a_rule_of_a_tracker_needs_no_when(): void
    {
        $select = ['field' => 'SourcePackage', 'op' => 'in', 'value' => ['foo']];
        $rule = ['key' => 'org.distro-ubuntu-01', 'name' => 'Ubuntu: known vulnerabilities', 'severity' => 'high', 'platform' => 'linux', 'source' => 'software',
            'select' => $select, 'cves' => [['id' => 'CVE-2026-1', 'severity' => 'high', 'ranges' => [], 'ubuntu' => ['foo' => ['noble' => ['released', '1.0-2']]]]]];

        $this->assertSame([], SecurityRules::errors($rule));
        $found = SecurityScanner::matches($rule, [$this->deb('libfoo1', '1.0-1', 'foo'), $this->deb('bar', '1.0-1')], ['release' => 'noble', 'os' => 'ubuntu']);
        $this->assertCount(1, $found);
        $this->assertSame('high', array_values($found)[0]['severity']);
    }

    public function test_every_bundled_rule_is_valid(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../../resources/security/rules.json'), true) as $rule) {
            $this->assertSame([], SecurityRules::errors($rule), $rule['key']);
        }
    }
}
