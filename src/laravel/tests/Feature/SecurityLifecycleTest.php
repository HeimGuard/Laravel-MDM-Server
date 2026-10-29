<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Support\SecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Tests\Concerns\SignsDeviceRequests;
use Tests\Concerns\SimulatesSecurityAgent;
use Tests\TestCase;

/** A3 (the findings of one source package are one, acknowledged ones stay), C4 (allowed tags) and C5 (the life of a finding). */
class SecurityLifecycleTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests, SimulatesSecurityAgent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    private function device(string $token = 'l', array $tags = []): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->tags = $tags;
        $device->data = json_encode(['machine' => ['Hostname' => "pc-$token", 'AgentVersion' => '1.28.0', 'Platform' => 'linux', 'Drives' => [], 'ScriptsEnabled' => true, 'NetworkDiscovery' => 'neighbours', 'Features' => ['scripts' => true, 'network_discovery' => 'neighbours', 'security_logs' => 'off']]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function send(array $inventory, string $token = 'l'): void
    {
        $this->agentSend($token, $inventory)->assertStatus(202);
        $this->artisan('queue:work', ['--queue' => 'security,default', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--quiet' => true]);
    }

    private function rule(array $definition): SecurityRule
    {
        $definition += ['name' => 'Test', 'severity' => 'medium', 'platform' => 'linux', 'source' => 'software'];
        $rule = SecurityRule::query()->where('key', $definition['key'])->first();
        if ($rule === null) {
            return SecurityRule::query()->create(SecurityRule::attributesFor($definition) + ['origin' => SecurityRule::CUSTOM]);
        }
        $rule->update(['definition' => $definition, 'severity' => $definition['severity']]);

        return $rule->fresh();
    }

    private function soup(): array
    {
        return [
            ['Name' => 'libsoup-3.0-0', 'Version' => '3.6.5-1', 'Source' => 'dpkg', 'SourcePackage' => 'libsoup3', 'Origin' => 'Ubuntu'],
            ['Name' => 'libsoup-3.0-common', 'Version' => '3.6.5-1', 'Source' => 'dpkg', 'SourcePackage' => 'libsoup3', 'Origin' => 'Ubuntu'],
        ];
    }

    private function legacyRule(): SecurityRule
    {
        return $this->rule(['key' => 'org.cve-libsoup', 'when' => ['field' => 'Name', 'op' => 'starts_with', 'value' => 'libsoup'], 'message' => '{Name} is affected']);
    }

    private function groupedRule(string $severity = 'medium', array $extra = []): SecurityRule
    {
        $select = ['field' => 'Name', 'op' => 'starts_with', 'value' => 'libsoup'];
        $cves = [['id' => 'CVE-2026-1001', 'severity' => 'medium', 'ranges' => [[null, '9.0', null]], 'ubuntu' => ['libsoup3' => ['resolute' => ['needed', '']]]]];

        return $this->rule(['key' => 'org.cve-libsoup', 'severity' => $severity, 'when' => $select, 'select' => $select, 'cves' => [...$cves, ...$extra], 'message' => 'x']);
    }

    private function open(): \Illuminate\Support\Collection
    {
        return SecurityFinding::query()->open()->get();
    }

    public function test_two_findings_of_one_source_package_become_one_and_the_acknowledged_one_stays(): void
    {
        $device = $this->device();
        $this->legacyRule();
        $this->send(['software' => $this->soup(), 'os' => [['Id' => 'ubuntu', 'Name' => 'Ubuntu', 'Version' => '26.04', 'Codename' => 'resolute']]]);
        $this->assertCount(2, $this->open());
        $acknowledged = $this->open()->firstWhere('details.Name', 'libsoup-3.0-common');
        $acknowledged->acknowledge(null);

        // The feed brings the rule that judges CVEs one by one and groups by source package.
        $this->groupedRule();
        SecurityScanner::scan($device);

        $this->assertCount(1, $this->open(), 'one finding for libsoup3');
        $kept = $this->open()->first();
        $this->assertSame($acknowledged->id, $kept->id, 'the history and the acknowledgement move to it');
        $this->assertNotNull($kept->acknowledged_at);
        $this->assertSame(1, $kept->details['CveCount']);
        $this->assertStringContainsString('libsoup-3.0-0', $kept->details['Packages']);
        $this->assertStringContainsString('libsoup-3.0-common', $kept->details['Packages']);
        $this->assertSame(1, SecurityFinding::query()->whereNotNull('resolved_at')->count(), 'the other one is resolved');
    }

    public function test_an_acknowledged_finding_opens_again_when_a_more_severe_cve_comes(): void
    {
        $device = $this->device();
        $this->groupedRule();
        $this->send(['software' => $this->soup(), 'os' => [['Id' => 'ubuntu', 'Name' => 'Ubuntu', 'Version' => '26.04', 'Codename' => 'resolute']]]);
        $finding = $this->open()->first();
        $this->assertSame('medium', $finding->severity);
        $finding->acknowledge(null);
        $this->assertNull(SecurityFinding::query()->active()->first());

        // The same CVEs: it stays acknowledged. A critical one: it needs attention again.
        SecurityScanner::scan($device);
        $this->assertNotNull($finding->fresh()->acknowledged_at);
        $this->groupedRule('critical', [['id' => 'CVE-2026-1002', 'severity' => 'critical', 'ranges' => [[null, '9.0', null]], 'ubuntu' => ['libsoup3' => ['resolute' => ['released', '99.0-1']]]]]);
        SecurityScanner::scan($device);

        $worse = $finding->fresh();
        $this->assertSame('critical', $worse->severity);
        $this->assertNull($worse->acknowledged_at);
        $this->assertSame(2, $worse->details['CveCount']);
    }

    public function test_a_tag_allows_a_tool_and_the_finding_goes_away(): void
    {
        $this->device('d', ['developer']);
        $other = $this->device('o');
        $rules = collect(json_decode(file_get_contents(resource_path('security/rules.json')), true))->firstWhere('key', 'software.hacking-tools');
        $this->rule($rules);
        $software = [['Name' => 'nmap', 'Version' => '7.98', 'Source' => 'dpkg'], ['Name' => 'nmap-common', 'Version' => '7.98', 'Source' => 'dpkg'], ['Name' => 'zenmap', 'Version' => '7.98', 'Source' => 'dpkg']];

        $this->send(['software' => $software], 'd');
        $this->assertCount(0, $this->open(), 'a developer machine may have nmap');
        $this->send(['software' => $software], 'o');
        $this->assertCount(1, $this->open(), 'nmap, nmap-common and zenmap: one finding');
        $this->assertSame('info', $this->open()->first()->severity);
        $this->assertSame($other->id, $this->open()->first()->device_id);
    }

    public function test_acknowledging_expires_after_the_configured_days(): void
    {
        $device = $this->device();
        $this->groupedRule();
        $this->send(['software' => $this->soup(), 'os' => [['Id' => 'ubuntu', 'Name' => 'Ubuntu', 'Version' => '26.04', 'Codename' => 'resolute']]]);
        $finding = $this->open()->first();
        $finding->acknowledge(null);
        $finding->forceFill(['acknowledged_at' => now()->subDays(100)])->save();

        $this->assertSame(0, SecurityScanner::sweep()['reopened'], 'off by default');
        Setting::query()->updateOrCreate(['index' => SecurityScanner::ACK_DAYS_SETTING], ['value' => '90', 'type' => SettingDataType::INT]);
        $this->assertSame(1, SecurityScanner::sweep()['reopened']);
        $this->assertNull($finding->fresh()->acknowledged_at);
    }

    public function test_the_findings_of_a_device_that_stopped_reporting_are_stale_until_it_reports_again(): void
    {
        $device = $this->device();
        $this->groupedRule();
        $inventory = ['software' => $this->soup(), 'os' => [['Id' => 'ubuntu', 'Name' => 'Ubuntu', 'Version' => '26.04', 'Codename' => 'resolute']]];
        $this->send($inventory);
        $device->securityInventory()->update(['collected_at' => now()->subDays(20)]);

        $this->assertSame(1, SecurityScanner::sweep()['stale']);
        $finding = $this->open()->first();
        $this->assertSame('stale', $finding->status);
        $this->assertNull(SecurityFinding::query()->active()->first(), 'it does not need attention: nobody knows');

        SecurityScanner::scan($device);
        $this->assertNull($finding->fresh()->stale_at, 'a report that finds it again makes it current');
    }

    public function test_the_findings_of_a_rule_that_is_off_are_resolved_instead_of_staying_open(): void
    {
        $this->device();
        $rule = $this->groupedRule();
        $this->send(['software' => $this->soup(), 'os' => [['Id' => 'ubuntu', 'Name' => 'Ubuntu', 'Version' => '26.04', 'Codename' => 'resolute']]]);
        $this->assertCount(1, $this->open());

        $this->assertSame(0, SecurityScanner::sweep()['resolved'], 'a rule that holds keeps its findings');
        $rule->update(['enabled' => false]);
        $this->assertSame(1, SecurityScanner::sweep()['resolved']);
        $this->assertCount(0, $this->open());
        $this->assertNotNull(SecurityFinding::query()->first()->resolved_at);
    }
}
