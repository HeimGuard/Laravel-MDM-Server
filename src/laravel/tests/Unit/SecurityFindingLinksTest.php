<?php

namespace Tests\Unit;

use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use PHPUnit\Framework\TestCase;

/** The links and the title of a finding are of the finding, not of the rule that many devices share. */
class SecurityFindingLinksTest extends TestCase
{
    private function finding(array $definition, array $details, string $message = ''): SecurityFinding
    {
        $rule = (new SecurityRule)->forceFill(['name' => $definition['name'], 'definition' => $definition]);

        return (new SecurityFinding)->forceFill(['message' => $message, 'details' => $details])->setRelation('rule', $rule);
    }

    public function test_the_links_of_a_cve_finding_are_its_own_cves(): void
    {
        $definition = ['name' => 'Ubuntu kernel: known vulnerabilities', 'cves' => [['id' => 'CVE-2025-10263']], 'urls' => ['https://nvd.nist.gov/vuln/detail/CVE-2025-10263']];
        $finding = $this->finding($definition, ['Cves' => 'CVE-2026-89530, CVE-2026-89652 and 3 more']);

        $this->assertSame([
            ['url' => 'https://nvd.nist.gov/vuln/detail/CVE-2026-89530', 'host' => 'CVE-2026-89530'],
            ['url' => 'https://nvd.nist.gov/vuln/detail/CVE-2026-89652', 'host' => 'CVE-2026-89652'],
        ], $finding->urls);
    }

    public function test_a_finding_lists_at_most_ten_cve_links(): void
    {
        $ids = array_map(fn ($i) => 'CVE-2026-'.(1000 + $i), range(1, 25));
        $finding = $this->finding(['name' => 'x', 'cves' => [['id' => 'CVE-1']]], ['Cves' => implode(', ', $ids)]);

        $this->assertCount(SecurityFinding::MAX_CVE_LINKS, $finding->urls);
    }

    public function test_a_finding_of_another_rule_keeps_the_links_of_the_rule(): void
    {
        $finding = $this->finding(['name' => 'Telnet', 'urls' => ['https://attack.mitre.org/techniques/T1219/']], []);

        $this->assertSame([['url' => 'https://attack.mitre.org/techniques/T1219/', 'host' => 'attack.mitre.org']], $finding->urls);
    }

    public function test_the_title_of_a_rule_of_many_packages_is_the_package(): void
    {
        $cves = [['id' => 'CVE-1']];
        $this->assertSame('qemu: known vulnerabilities', $this->finding(['name' => 'Ubuntu packages: known vulnerabilities', 'cves' => $cves], ['SourcePackage' => 'qemu'])->title);
        $this->assertSame('linux: known vulnerabilities', $this->finding(['name' => 'Ubuntu packages: known vulnerabilities', 'cves' => $cves], ['SourcePackage' => 'linux-signed'])->title);
        $this->assertSame('Ubuntu kernel: known vulnerabilities', $this->finding(['name' => 'Ubuntu kernel: known vulnerabilities', 'cves' => $cves], ['SourcePackage' => 'linux-signed'])->title);
        $this->assertSame('libxml2 has known vulnerabilities', $this->finding(['name' => 'libxml2 has known vulnerabilities', 'cves' => $cves], ['SourcePackage' => 'libxml2'])->title);
    }
}
