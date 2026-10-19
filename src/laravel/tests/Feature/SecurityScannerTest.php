<?php

namespace Tests\Feature;

use App\Jobs\ProcessSecurityCollection;
use App\Livewire\DeviceDetail;
use App\Livewire\DeviceSecurity;
use App\Livewire\SecurityScan\Page;
use App\Livewire\SecurityScan\RuleForm;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\NotificationSetting;
use App\Models\Script;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityInventoryItem;
use App\Models\SecurityRule;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\PowerShellCheck;
use App\Support\SecurityInbox;
use App\Support\SecurityParsers;
use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use App\Support\SecurityStateMismatch;
use App\Support\SmartAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\Concerns\SimulatesSecurityAgent;
use Tests\TestCase;

/** The security scanner: its rule language, the inventory from the agent, findings and events. */
class SecurityScannerTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests, SimulatesSecurityAgent;

    protected function setUp(): void
    {
        parent::setUp();
        // The jobs wait in the database queue until a test runs the worker, as in the Docker image.
        config(['queue.default' => 'database']);
    }

    /** Runs the queue worker until the security queue is empty. */
    private function runWorker(): void
    {
        $this->artisan('queue:work', ['--queue' => 'security,default', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--quiet' => true]);
    }

    private function device(string $token = 'a', string $platform = 'windows', string $logs = 'on'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->data = json_encode(['machine' => ['Hostname' => "pc-$token", 'AgentVersion' => '1.17.0', 'Platform' => $platform, 'Drives' => [], 'ScriptsEnabled' => true, 'NetworkDiscovery' => 'neighbours', 'Features' => ['scripts' => true, 'network_discovery' => 'neighbours', 'security_logs' => $logs]]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function inventory(array $overrides = []): array
    {
        return $overrides + [
            'software' => [
                ['Name' => 'AnyDesk', 'Version' => '8.0.9', 'Publisher' => 'AnyDesk Software GmbH', 'Source' => 'registry'],
                ['Name' => 'WinRAR 6.02 (64-bit)', 'Version' => '6.02.0', 'Publisher' => 'win.rar GmbH', 'Source' => 'registry'],
                ['Name' => '7-Zip 24.09 (x64)', 'Version' => '24.09', 'Publisher' => 'Igor Pavlov', 'Source' => 'registry'],
                ['Name' => 'Age of Empires II', 'Version' => '1.0', 'Publisher' => 'Microsoft', 'Source' => 'registry'],
            ],
            'processes' => [
                ['Name' => 'powershell.exe', 'Path' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', 'CommandLine' => 'powershell.exe -nop -w hidden -enc SQBFAFgAIAAoAE4AZQB3AC0ATwBiAGoA', 'User' => 'CORP\\eve', 'Count' => 1],
                ['Name' => 'explorer.exe', 'Path' => 'C:\\Windows\\explorer.exe', 'CommandLine' => 'C:\\Windows\\Explorer.EXE', 'User' => 'CORP\\eve', 'Count' => 1],
            ],
            'listening' => [
                ['Protocol' => 'tcp', 'Address' => '0.0.0.0', 'Port' => 23, 'Process' => 'tlntsvr.exe'],
                ['Protocol' => 'tcp', 'Address' => '127.0.0.1', 'Port' => 3306, 'Process' => 'mysqld.exe'],
            ],
            'admins' => [['Name' => 'Administrator', 'Source' => 'local', 'Enabled' => false], ['Name' => 'eve', 'Source' => 'local', 'Enabled' => true]],
            'startup' => [],
            'posture' => ['FirewallEnabled' => false, 'AntivirusName' => 'Microsoft Defender', 'AntivirusEnabled' => true, 'RealTimeProtection' => true, 'Smb1Enabled' => false],
            // Raw Windows events: 25 failed sign-ins of administrator from outside, 2 of eve, one
            // successful sign-in no parser reads.
            'logs' => [['source' => 'windows.security', 'records' => [
                ...$this->failedLogons(25, 'administrator', '203.0.113.7'),
                ...$this->failedLogons(2, 'eve', '-'),
                ['Time' => now()->toIso8601String(), 'Id' => 4624, 'Provider' => 'Microsoft-Windows-Security-Auditing', 'Data' => ['TargetUserName' => 'eve']],
            ]]],
        ];
    }

    private function failedLogons(int $count, string $user, string $ip): array
    {
        return array_fill(0, $count, [
            'Time' => now()->subMinutes(5)->toIso8601String(), 'Id' => 4625, 'Provider' => 'Microsoft-Windows-Security-Auditing', 'Level' => 0,
            'Data' => ['TargetUserName' => $user, 'IpAddress' => $ip, 'WorkstationName' => 'EVE-PC', 'LogonType' => '10'],
        ]);
    }

    /**
     * Sends a collection as the agent does (deltas against what it knows the server has, raw logs);
     * the job runs after the response. What it opened and resolved.
     */
    private function send(Device $device, array $inventory, string $token = 'a'): array
    {
        $logs = $inventory['logs'] ?? [];
        unset($inventory['logs']);
        $open = SecurityFinding::query()->open()->pluck('id');
        $this->agentSend($token, $inventory, $logs)->assertStatus(202)->assertJson(['queued' => true]);
        $this->runWorker();

        return [
            'opened' => SecurityFinding::query()->open()->whereNotIn('id', $open)->count(),
            'resolved' => SecurityFinding::query()->whereIn('id', $open)->whereNotNull('resolved_at')->count(),
        ];
    }

    private function assertCounts(array $expected, array $actual): void
    {
        $this->assertSame($expected, array_intersect_key($actual, $expected));
    }

    private function openKeys(Device $device): array
    {
        return SecurityFinding::query()->open()->where('device_id', $device->id)->with('rule')->get()->map(fn ($finding) => $finding->rule->key)->sort()->values()->all();
    }

    public function test_the_built_in_rules_are_valid(): void
    {
        $rules = json_decode(file_get_contents(resource_path('security/rules.json')), true);
        $this->assertNotEmpty($rules);
        $keys = [];
        foreach ($rules as $rule) {
            $this->assertSame([], SecurityRules::errors($rule), 'Rule '.($rule['key'] ?? '?'));
            $keys[] = $rule['key'];
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Rule keys are unique');

        $parsers = json_decode(file_get_contents(resource_path('security/parsers.json')), true);
        foreach ($parsers as $parser) {
            $this->assertSame([], SecurityParsers::errors($parser), 'Parser '.($parser['key'] ?? '?'));
            $keys[] = $parser['key'];
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Rule and parser keys are unique');

        SecurityRule::syncBuiltIn();
        $this->assertSame(count($rules), SecurityRule::query()->detection()->where('origin', 'bundled')->count());
        $this->assertSame(count($parsers), SecurityRule::query()->parsers()->where('origin', 'bundled')->count());
        $this->assertFalse(SecurityRule::query()->where('key', 'events.service-installed')->value('enabled'));
    }

    public function test_the_rule_language(): void
    {
        $item = ['Name' => 'WinRAR 6.02', 'Version' => '6.02.0', 'Port' => 23, 'On' => false, 'Nested' => ['Value' => 'Deep']];
        $test = fn (array $condition) => SecurityRules::test($condition, $item);

        $this->assertTrue($test(['field' => 'Name', 'op' => 'contains', 'value' => 'winrar']));
        $this->assertTrue($test(['field' => 'Name', 'op' => 'starts_with', 'value' => 'WINRAR']));
        $this->assertTrue($test(['field' => 'Name', 'op' => 'matches', 'value' => '^win(rar|zip)\b']));
        $this->assertFalse($test(['field' => 'Name', 'op' => 'not_matches', 'value' => 'rar']));
        $this->assertTrue($test(['field' => 'Version', 'op' => 'version_lt', 'value' => '6.23']));
        $this->assertFalse($test(['field' => 'Version', 'op' => 'version_gte', 'value' => '6.23']));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'in', 'value' => [21, 23]]));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'eq', 'value' => '23']));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'lte', 'value' => 23]));
        $this->assertTrue($test(['field' => 'On', 'op' => 'false']));
        $this->assertTrue($test(['field' => 'On', 'op' => 'eq', 'value' => false]));
        // Unknown is neither true nor false.
        $this->assertFalse($test(['field' => 'Missing', 'op' => 'false']));
        $this->assertTrue($test(['field' => 'Missing', 'op' => 'empty']));
        $this->assertTrue($test(['field' => 'Nested.Value', 'op' => 'eq', 'value' => 'deep']));
        $this->assertTrue($test(['all' => [
            ['field' => 'Name', 'op' => 'exists'],
            ['any' => [['field' => 'Port', 'op' => 'eq', 'value' => 80], ['not' => ['field' => 'Port', 'op' => 'gt', 'value' => 100]]]],
        ]]));
        $this->assertSame('WinRAR 6.02 on 23', SecurityRules::render('{Name} on {Port} {Unknown}', $item));
        $this->assertTrue(SecurityRules::test(['field' => 'Version', 'op' => 'version_lt', 'value' => '1:2.0'], ['Version' => '2:1.9-1ubuntu1']));

        $valid = ['key' => 'custom.x', 'name' => 'X', 'severity' => 'low', 'source' => 'software', 'when' => ['field' => 'Name', 'op' => 'eq', 'value' => 'x']];
        $this->assertSame([], SecurityRules::errors($valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => []]));
        $this->assertNotEmpty(SecurityRules::errors(['source' => 'files'] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'matches', 'value' => '(']] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'in', 'value' => 'x']] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['all' => []]] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'eq']] + $valid));
        $deep = ['field' => 'Name', 'op' => 'exists'];
        for ($i = 0; $i < 8; $i++) {
            $deep = ['not' => $deep];
        }
        $this->assertNotEmpty(SecurityRules::errors(['when' => $deep] + $valid));
    }

    public function test_parsers_turn_raw_logs_into_events(): void
    {
        SecurityRule::syncBuiltIn();
        $parsers = SecurityRule::query()->parsers()->enabled()->pluck('definition');
        $line = fn (string $identifier, string $message, string $time = '2026-10-05T10:00:00Z') => ['Time' => $time, 'Identifier' => $identifier, 'Pid' => 1, 'Message' => $message];
        $events = SecurityParsers::run([
            ['source' => 'linux.auth', 'records' => [
                $line('sshd', 'Failed password for invalid user admin from 203.0.113.7 port 4711 ssh2'),
                $line('sshd', 'Failed password for invalid user ADMIN from 203.0.113.7 port 4712 ssh2', '2026-10-05T10:05:00Z'),
                $line('sshd', 'Accepted publickey for alice from 192.0.2.1 port 22 ssh2'),
                $line('sudo', '   alice : 3 incorrect password attempts ; TTY=pts/0 ; PWD=/home/alice ; USER=root ; COMMAND=/bin/bash'),
                $line('useradd', 'new user: name=mallory, UID=1001, GID=1001, home=/home/mallory'),
                $line('usermod', "add 'mallory' to group 'sudo'"),
                $line('usermod', "add 'mallory' to shadow group 'sudo'"),
                'not a record',
            ]],
            ['source' => 'windows.security', 'records' => [
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4625, 'Data' => ['TargetUserName' => 'bob', 'IpAddress' => '-', 'WorkstationName' => 'KIOSK', 'LogonType' => '2']],
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4732, 'Data' => ['TargetSid' => 'S-1-5-32-555', 'MemberName' => '-', 'MemberSid' => 'S-1-5-21-1-2-3-1001']],
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4732, 'Data' => ['TargetSid' => 'S-1-5-32-544', 'MemberName' => '-', 'MemberSid' => 'S-1-5-21-1-2-3-1001', 'SubjectUserName' => 'helpdesk']],
            ]],
            // A log no parser reads.
            ['source' => 'windows.powershell', 'records' => [['Id' => 4104]]],
        ], $parsers);

        $byType = collect($events)->groupBy('Type');
        $this->assertSame(['failed_logon', 'sudo_failed', 'account_created', 'admin_added'], $byType->keys()->all());
        // Two attempts of the same user (case ignored) from the same address are one event.
        $ssh = $byType['failed_logon']->firstWhere('Source', '203.0.113.7');
        $this->assertSame(2, $ssh['Count']);
        $this->assertSame('2026-10-05T10:05:00Z', $ssh['Last']);
        $this->assertSame('ssh: failed password for ADMIN', $ssh['Message']);
        // {IpAddress|WorkstationName}: "-" is not set.
        $this->assertSame('KIOSK', $byType['failed_logon']->firstWhere('User', 'bob')['Source']);
        $this->assertSame(3, $byType['sudo_failed'][0]['Count']);
        $this->assertSame('mallory', $byType['account_created'][0]['User']);
        // Only the Administrators group, and usermod's shadow line is not counted twice.
        $this->assertSame(['mallory', 'S-1-5-21-1-2-3-1001'], $byType['admin_added']->pluck('User')->all());
        $this->assertSame('helpdesk added S-1-5-21-1-2-3-1001 to Administrators', $byType['admin_added'][1]['Message']);

        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'event' => ['type' => 'x_y']]));
        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'pattern' => '(', 'event' => ['type' => 'x_y']]));
        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'pattern' => 'x', 'event' => ['type' => 'X Y', 'extra' => '']]));
    }

    public function test_an_events_rule_can_take_only_combinations_not_seen_for_days(): void
    {
        $definition = ['key' => 'custom.logon-new', 'name' => 'New sign-in', 'severity' => 'low', 'platform' => 'any', 'source' => 'events',
            'when' => ['field' => 'Type', 'op' => 'eq', 'value' => 'logon'], 'unseen_days' => 10, 'message' => '{User} signed in from {Source}'];
        $this->assertSame([], SecurityRules::errors($definition));
        $this->assertSame([], SecurityRules::errors($definition + ['unseen_by' => ['Source']]));
        $this->assertNotEmpty(SecurityRules::errors(['source' => 'software'] + $definition));
        $this->assertNotEmpty(SecurityRules::errors(['unseen_days' => 31] + $definition));
        $this->assertNotEmpty(SecurityRules::errors($definition + ['unseen_by' => ['Message']]));
        $this->assertNotEmpty(SecurityRules::errors(array_diff_key($definition, ['unseen_days' => 1]) + ['unseen_by' => ['User']]));

        $rule = new SecurityRule(['origin' => SecurityRule::CUSTOM, 'enabled' => true]);
        $rule->fill(SecurityRule::attributesFor($definition))->save();
        $device = $this->device('a', 'linux');
        $other = $this->device('b', 'linux');
        SecurityInventory::query()->create(['device_id' => $device->id, 'states' => [], 'scanned' => [], 'collected_at' => now()]);
        // Seen 5 days ago on another device (the whole fleet counts), and 20 days ago.
        SecurityEvent::query()->create(['device_id' => $other->id, 'type' => 'logon', 'user' => 'alice', 'source' => '192.0.2.1', 'occurred_at' => now()->subDays(5)]);
        SecurityEvent::query()->create(['device_id' => $device->id, 'type' => 'logon', 'user' => 'bob', 'source' => '198.51.100.1', 'occurred_at' => now()->subDays(20)]);
        $logon = fn (string $user, string $source) => ['Type' => 'logon', 'Count' => 1, 'User' => $user, 'Source' => $source, 'Message' => 'ssh', 'Last' => now()->toIso8601String()];
        $findings = fn () => SecurityFinding::query()->where('security_rule_id', $rule->id)->orderBy('message')->pluck('message')->all();

        SecurityScanner::ingest($device, [], [$logon('alice', '192.0.2.1'), $logon('bob', '198.51.100.1'), $logon('carol', '203.0.113.9'), $logon('alice', '203.0.113.9')]);
        $this->assertSame(['alice signed in from 203.0.113.9', 'bob signed in from 198.51.100.1', 'carol signed in from 203.0.113.9'], $findings());
        // Again a day later: seen now, nothing new.
        $this->travel(1)->days();
        SecurityScanner::ingest($device, [], [$logon('carol', '203.0.113.9')]);
        $this->assertCount(3, $findings());
        $this->assertSame(1, SecurityFinding::query()->where('security_rule_id', $rule->id)->where('message', 'carol signed in from 203.0.113.9')->value('occurrences'), 'Not seen again by this rule');
    }

    public function test_a_rule_can_have_links(): void
    {
        $definition = ['key' => 'custom.anydesk', 'name' => 'AnyDesk', 'severity' => 'medium', 'source' => 'software',
            'when' => ['field' => 'Name', 'op' => 'contains', 'value' => 'anydesk'], 'urls' => ['https://attack.mitre.org/techniques/T1219/', 'http://www.example.com/a?b=1']];
        $this->assertSame([], SecurityRules::errors($definition));
        foreach ([['javascript:alert(1)'], ['ftp://example.com/x'], 'https://example.com', array_fill(0, 11, 'https://example.com/'), ['https://example.com/'.str_repeat('a', 500)]] as $urls) {
            $this->assertNotEmpty(SecurityRules::errors(['urls' => $urls] + $definition), json_encode($urls));
        }
        $rule = new SecurityRule(['origin' => SecurityRule::CUSTOM, 'enabled' => true]);
        $rule->fill(SecurityRule::attributesFor($definition))->save();
        $this->assertSame([['url' => 'https://attack.mitre.org/techniques/T1219/', 'host' => 'attack.mitre.org'], ['url' => 'http://www.example.com/a?b=1', 'host' => 'example.com']], $rule->urls);
        // A stored definition with a bad link shows none.
        $rule->forceFill(['definition' => ['urls' => ['javascript:alert(1)']] + $definition])->save();
        $this->assertSame([], $rule->fresh()->urls);
    }

    public function test_collections_are_taken_into_the_cache_and_processed_by_a_job(): void
    {
        $device = $this->device();
        Queue::fake();
        $inventory = $this->inventory();
        unset($inventory['logs']);
        $this->agentSend('a', $inventory)->assertStatus(202)->assertJson(['queued' => true]);
        $payload = $this->lastCollection;

        // Nothing is parsed or stored in the request: the job waits on the security queue.
        Queue::assertPushedOn('security', ProcessSecurityCollection::class, function ($job) use ($device, $payload) {
            return $job->deviceId === $device->id && SecurityInbox::read($job->cacheKey) === json_encode($payload);
        });
        $this->assertSame(0, SecurityFinding::query()->count());
        $this->assertNull($device->securityInventory()->first());

        // The cache holds it encrypted and compressed, not as a readable JSON.
        $job = Queue::pushed(ProcessSecurityCollection::class)->first();
        $this->assertStringNotContainsString('AnyDesk', (string) Cache::get($job->cacheKey));

        // The worker runs it: findings open and the payload leaves the cache.
        $job->handle();
        $this->assertContains('software.remote-access', $this->openKeys($device));
        $this->assertNull(SecurityInbox::read($job->cacheKey));

        // The same job again (a retry after the work was done) and one of a device that is gone do nothing.
        $job->handle();
        $gone = $this->device('gone');
        $key = SecurityInbox::push($gone, json_encode(['collection_id' => 'x', 'sources' => $this->agentSources('gone', $inventory)['sources']]));
        $gone->delete();
        (new ProcessSecurityCollection($gone->id, $key))->handle();
        $this->assertNull(SecurityInbox::read($key));
    }

    public function test_logs_are_only_taken_from_agents_that_send_them(): void
    {
        $off = $this->device('off', 'windows', 'off');
        $this->send($off, $this->inventory(), 'off');
        // The inventory is scanned, the logs a device did not agree to send are dropped.
        $this->assertContains('software.remote-access', $this->openKeys($off));
        $this->assertNotContains('events.brute-force', $this->openKeys($off));
        $this->assertSame(0, SecurityEvent::query()->where('device_id', $off->id)->count());

        // On, but a system admin turned the logs off for the whole portal.
        $on = $this->device('on');
        SecurityScanner::setLogsAllowed(false);
        $this->send($on, $this->inventory(), 'on');
        $this->assertNotContains('events.brute-force', $this->openKeys($on));
        SecurityScanner::setLogsAllowed(true);
        $this->send($on, $this->inventory(), 'on');
        $this->assertContains('events.brute-force', $this->openKeys($on));
    }

    public function test_installations_start_with_a_script_that_turns_the_logs_on(): void
    {
        $script = Script::query()->where('name', 'Allow security logs')->firstOrFail();

        // Like Allow network scans: a run only detects, an admin starts the remediation per device.
        $this->assertTrue($script->manual_remediation);
        $this->assertSame('all', $script->platform);
        $this->assertSame($script->fingerprint, Script::fingerprintOf('all', 60, $script->detection, $script->remediation));
        $this->assertSame([], PowerShellCheck::errors($script->detection));
        $this->assertSame([], PowerShellCheck::errors($script->remediation));
        $this->assertTrue(PowerShellCheck::exits($script->detection, 0));
        $this->assertTrue(PowerShellCheck::exits($script->detection, 1));
        $this->assertStringContainsString("security_logs -NotePropertyValue 'on'", $script->remediation);
    }

    public function test_the_agent_tab_lists_the_features_read_only(): void
    {
        $this->withoutVite();
        $device = $this->device('a', 'linux', 'off');
        $this->send($device, $this->inventory(), 'a');
        $features = collect($device->fresh()->features)->keyBy('key');

        $this->assertTrue($features['scripts']['on']);
        $this->assertSame('ARP table', $features['network_discovery']['detail']);
        $this->assertTrue($features['security_inventory']['on']);
        $this->assertFalse($features['security_logs']['on']);
        $this->assertSame('security_logs', $features['security_logs']['setting']);
        $this->assertTrue($features['ping']['on']);

        // An agent that predates a feature says which version adds it.
        $old = $this->device('old');
        $data = json_decode($old->getRawOriginal('data'), true);
        $data['machine']['AgentVersion'] = '1.10.0';
        unset($data['machine']['Features'], $data['machine']['NetworkDiscovery']);
        $old->forceFill(['data' => json_encode($data)])->saveQuietly();
        $oldFeatures = collect($old->fresh()->features)->keyBy('key');
        $this->assertFalse($oldFeatures['security_logs']['on']);
        $this->assertSame('1.17.0', $oldFeatures['security_logs']['needs']);
        $this->assertSame('needs agent 1.16.0', $oldFeatures['network_discovery']['detail']);

        $this->actingAs(User::factory()->create());
        $page = Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id, 'tab' => 'agent']);
        $page->assertSee('Features')->assertSee('security_logs')->assertSee('Read only')->assertDontSee('Turn on');
    }

    public function test_an_inventory_opens_findings_and_the_next_one_resolves_them(): void
    {
        $device = $this->device();
        $this->assertCounts(['resolved' => 0], $this->send($device, $this->inventory()));

        $this->assertSame([
            'events.brute-force', 'listening.cleartext', 'posture.firewall-windows', 'process.encoded-powershell',
            'software.remote-access', 'software.winrar-cve-2023-38831',
        ], $this->openKeys($device));
        // Age of Empires is no attack framework, 7-Zip 24.09 is fixed, MySQL listens on loopback only.
        $finding = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'software.remote-access'))->first();
        $this->assertSame('AnyDesk 8.0.9 is installed', $finding->message);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame('203.0.113.7', SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first()->details['Source']);
        // Events of a known type are kept for the timeline.
        $this->assertSame(2, SecurityEvent::query()->where('device_id', $device->id)->count());
        $this->assertSame('AnyDesk', $device->securityInventory()->first()->data['software'][0]['Name']);
        $this->assertArrayNotHasKey('events', $device->securityInventory()->first()->data);

        // Next collection: AnyDesk updated (same finding), WinRAR updated, firewall on, no new events.
        $next = $this->inventory([
            'software' => [['Name' => 'AnyDesk', 'Version' => '9.0.1', 'Source' => 'registry'], ['Name' => 'WinRAR 7.01 (64-bit)', 'Version' => '7.01.0', 'Source' => 'registry']],
            'posture' => ['FirewallEnabled' => true],
            'logs' => [],
        ]);
        $this->assertCounts(['opened' => 0, 'resolved' => 2], $this->send($device, $next));
        $this->assertSame(['events.brute-force', 'listening.cleartext', 'process.encoded-powershell', 'software.remote-access'], $this->openKeys($device));
        $this->assertSame($finding->id, $finding->fresh()->id);
        $this->assertSame('AnyDesk 9.0.1 is installed', $finding->fresh()->message);
        // The brute force finding stays until it is acknowledged; it was seen once.
        $bruteForce = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first();
        $this->assertNull($bruteForce->resolved_at);

        // The same attack again: the finding counts it.
        $this->send($device, $this->inventory(['logs' => [['source' => 'windows.security', 'records' => $this->failedLogons(40, 'Administrator', '203.0.113.7')]]]));
        $this->assertSame(2, $bruteForce->fresh()->occurrences);
        $this->assertSame('40 failed sign-ins of Administrator from 203.0.113.7', $bruteForce->fresh()->message);
    }

    public function test_only_what_changed_is_sent_and_every_collection_is_checked(): void
    {
        $device = $this->device();
        $inventory = $this->inventory(['logs' => []]);
        unset($inventory['logs']);

        // The first collection is whole.
        $first = $this->agentSend('a', $inventory)->assertStatus(202);
        $this->runWorker();
        $this->assertTrue(collect($this->lastCollection['sources'])->every(fn ($source) => $source['full']));
        $this->assertSame(4, SecurityInventoryItem::query()->where('device_id', $device->id)->where('source', 'software')->count());
        $this->assertSame($first->json('ack.software'), $device->securityInventory()->first()->states['software']);
        $fullSize = strlen(json_encode($this->lastCollection));

        // Nothing changed: only the states are sent.
        $this->agentSend('a', $inventory)->assertStatus(202);
        $this->runWorker();
        $this->assertTrue(collect($this->lastCollection['sources'])->every(fn ($source) => array_keys($source) === ['source', 'state']));
        $this->assertLessThan(1200, strlen(json_encode($this->lastCollection)));
        $this->assertLessThan($fullSize / 2, strlen(json_encode($this->lastCollection)));

        // One program installed, one removed: one upsert and one remove, the rest of the source stays.
        $changed = $inventory;
        $changed['software'][] = ['Name' => 'Firefox', 'Version' => '131.0', 'Source' => 'registry'];
        array_shift($changed['software']);
        $this->agentSend('a', $changed)->assertStatus(202);
        $this->runWorker();
        $software = collect($this->lastCollection['sources'])->firstWhere('source', 'software');
        $this->assertCount(1, $software['upsert']);
        $this->assertCount(1, $software['remove']);
        $this->assertCount(1, collect($this->lastCollection['sources'])->filter(fn ($source) => isset($source['upsert'])));
        $names = SecurityInventoryItem::query()->where('device_id', $device->id)->where('source', 'software')->pluck('data')->map(fn ($data) => json_decode($data, true)['Name'])->sort()->values()->all();
        $this->assertSame(['7-Zip 24.09 (x64)', 'Age of Empires II', 'Firefox', 'WinRAR 6.02 (64-bit)'], $names);
        $this->assertNotContains('software.remote-access', $this->openKeys($device));

        // The same collection sent again (the ack got lost) is acknowledged and not processed twice.
        $id = $this->lastCollection['collection_id'];
        Queue::fake();
        $this->signedJson('POST', '/api/device/security', $this->lastCollection, 'a')->assertStatus(202)->assertJson(['duplicate' => true]);
        Queue::assertNothingPushed();
    }

    public function test_a_delta_that_does_not_follow_the_stored_state_is_refused_whole(): void
    {
        $device = $this->device();
        $inventory = $this->inventory(['logs' => []]);
        unset($inventory['logs']);
        $this->agentSend('a', $inventory)->assertStatus(202);
        $this->runWorker();
        $before = SecurityInventoryItem::query()->where('device_id', $device->id)->count();

        // The server lost its state (a restored backup): the delta is refused before anything is stored
        // and the agent sends that source whole.
        $device->securityInventory()->update(['states' => json_encode(['software' => str_repeat('0', 64)] + $device->securityInventory()->first()->states)]);
        $changed = $inventory;
        $changed['software'][] = ['Name' => 'Firefox', 'Version' => '131.0'];
        $changed['processes'][] = ['Name' => 'evil', 'Path' => '/tmp/evil', 'CommandLine' => '/tmp/evil'];
        $response = $this->agentSend('a', $changed)->assertStatus(202);
        $this->runWorker();
        $this->assertTrue(collect($this->lastCollection['sources'])->firstWhere('source', 'software')['full']);
        $this->assertSame($before + 2, SecurityInventoryItem::query()->where('device_id', $device->id)->count());

        // A delta that claims a state its items do not add up to stores nothing, not even its other sources.
        ['sources' => $sources] = $this->agentSources('a', $changed);
        $bad = [['source' => 'processes', 'base' => $device->securityInventory()->first()->states['processes'], 'state' => str_repeat('b', 64), 'upsert' => [], 'remove' => [], 'full' => false]];
        $count = SecurityInventoryItem::query()->count();
        $this->expectException(SecurityStateMismatch::class);
        try {
            SecurityScanner::ingest($device->fresh(), $bad);
        } finally {
            $this->assertSame($count, SecurityInventoryItem::query()->count());
        }
    }

    public function test_without_a_queue_the_job_runs_after_the_response(): void
    {
        config(['queue.default' => 'sync']);
        $device = $this->device();
        $inventory = $this->inventory();
        unset($inventory['logs']);
        $this->agentSend('a', $inventory)->assertStatus(202);
        $this->assertContains('software.remote-access', $this->openKeys($device));
    }

    public function test_the_state_hashes_match_the_agent(): void
    {
        // tests/fixtures/security-state.json is made by the agent (Get-SecurityItemKey, Get-SecurityItemHash,
        // Get-SecurityStateHash in app.ps1): the server must compute the same ones.
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/security-state.json')), true);
        $maps = [];
        foreach ($fixture['items'] as $entry) {
            $this->assertSame($entry['key'], SecurityInventory::itemKey($entry['source'], $entry['item']), $entry['source'].' key');
            $this->assertSame($entry['hash'], SecurityInventory::itemHash($entry['source'], $entry['item']), $entry['source'].' hash');
            $maps[$entry['source']][$entry['key']] = $entry['hash'];
        }
        foreach ($maps as $source => $map) {
            $this->assertSame($fixture['states'][$source], SecurityInventory::stateOf($map), $source.' state');
        }
        // The fields the agent hashes are the ones of the server's sources.
        $agent = file_get_contents(base_path('../powershell/app.ps1'));
        foreach (SecurityInventory::SOURCES as $source) {
            $this->assertStringContainsString('    '.str_pad($source, 9).' = @('.implode(', ', array_map(fn ($field) => "'$field'", SecurityRules::SOURCES[$source]['fields'])).')', $agent, $source.' fields');
            if ($source !== 'posture') {
                $this->assertStringContainsString('    '.str_pad($source, 9).' = @('.implode(', ', array_map(fn ($field) => "'$field'", SecurityRules::SOURCES[$source]['key'])).')', $agent, $source.' key fields');
            }
        }
    }

    public function test_logs_come_columnar_and_compressed_with_the_positions_acknowledged(): void
    {
        $device = $this->device();
        $inventory = $this->inventory();
        unset($inventory['logs']);
        $records = [];
        for ($i = 0; $i < 25; $i++) {
            $records[] = [now()->subMinutes(5)->toIso8601String(), 4625, 'Microsoft-Windows-Security-Auditing', 0, ['TargetUserName' => 'administrator', 'IpAddress' => '203.0.113.7', 'LogonType' => '10']];
        }
        $logs = [['source' => 'windows.security', 'fields' => ['Time', 'Id', 'Provider', 'Level', 'Data'], 'rows' => $records]];

        // Compressed with the signature over the compressed bytes: only the positions it may move on to come back.
        $response = $this->agentSend('a', $inventory, $logs, cursors: ['windows.security' => 4711, 'nonsense' => 'x'], gzip: true)->assertStatus(202);
        $this->runWorker();
        $this->assertSame(['windows.security' => 4711], $response->json('cursors'));
        $this->assertContains('events.brute-force', $this->openKeys($device));
        $this->assertSame(25, SecurityEvent::query()->where('device_id', $device->id)->sum('count'));
        $this->assertLessThan(strlen(json_encode($this->lastCollection)) / 3, strlen(gzencode(json_encode($this->lastCollection), 6)));
    }

    public function test_a_compressed_body_is_checked_before_and_after_it_is_inflated(): void
    {
        $device = $this->device();
        $body = json_encode(['collection_id' => 'abcdefabcdefabcdef', 'sources' => []]);

        // Changed after it was signed: refused by the signature, nothing is inflated.
        $tampered = gzencode($body, 6);
        $this->signedJson('POST', '/api/device/security', [], 'a', ['raw' => $tampered, 'server' => ['HTTP_CONTENT_ENCODING' => 'gzip'], 'signature' => base64_encode('x')])->assertUnauthorized();

        // A zip bomb: signed, small, inflates beyond what is allowed.
        $bomb = gzencode(str_repeat(' ', SecurityInbox::MAX_BYTES + 1024).$body, 9);
        $this->assertLessThan(100000, strlen($bomb));
        $this->signedJson('POST', '/api/device/security', [], 'a', ['raw' => $bomb, 'server' => ['HTTP_CONTENT_ENCODING' => 'gzip']])->assertStatus(422)
            ->assertJsonPath('error', 'not a JSON object, or more than 16 MB unpacked');
        // Not gzip although it says so.
        $this->signedJson('POST', '/api/device/security', [], 'a', ['raw' => $body, 'server' => ['HTTP_CONTENT_ENCODING' => 'gzip']])->assertStatus(422);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count());
    }

    public function test_the_policy_tells_the_agent_which_log_records_to_send(): void
    {
        $this->device();
        $policy = $this->signedJson('GET', '/api/device/security/policy', [], 'a')->assertOk()->json();
        // Built-in parsers: sshd and sudo etc. on Linux, the event ids of the Windows ones.
        $this->assertSame(['gpasswd', 'sshd', 'sshd-session', 'sudo', 'useradd', 'usermod'], $policy['sources']['linux.auth']);
        $this->assertContains(4625, $policy['sources']['windows.security']);
        $this->assertContains(7045, $policy['sources']['windows.system']);
        $this->assertContains(1116, $policy['sources']['windows.defender']);

        // A parser that takes any record of a log makes the whole log wanted; a log without parsers none.
        $parser = ['kind' => 'parser', 'key' => 'custom.any', 'name' => 'Any', 'source' => 'linux.auth', 'pattern' => 'x', 'event' => ['type' => 'x_y']];
        $parsers = SecurityRule::query()->parsers()->where('source', 'linux.auth')->pluck('definition')->push($parser);
        $this->assertNull(SecurityParsers::policy($parsers)['sources']['linux.auth']);
        $this->assertSame([], SecurityParsers::policy([])['sources']['windows.security']);
        $when = fn ($value) => ['source' => 'windows.security', 'when' => ['all' => [['field' => 'Provider', 'op' => 'eq', 'value' => 'x'], ['any' => [['field' => 'Id', 'op' => 'eq', 'value' => 1], ['field' => 'Id', 'op' => 'in', 'value' => [2, $value]]]]]]];
        $this->assertSame([1, 2, 3], SecurityParsers::policy([$when(3)])['sources']['windows.security']);
        $this->assertNotSame(SecurityParsers::policy([$when(3)])['version'], SecurityParsers::policy([$when(4)])['version']);

        // The portal takes no logs: nothing is wanted.
        SecurityScanner::setLogsAllowed(false);
        $off = $this->signedJson('GET', '/api/device/security/policy', [], 'a')->assertOk()->json();
        $this->assertSame([], $off['sources']['linux.auth']);
        $this->assertSame([], $off['sources']['windows.security']);
    }

    public function test_the_endpoint_needs_a_signing_agent(): void
    {
        $device = $this->device();
        $this->signedJson('POST', '/api/device/security', ['collection_id' => 'abcdefabcdefabcdef', 'sources' => []], 'wrong')->assertUnauthorized();
        // Not a collection: no id, sources of an unknown kind.
        $this->signedJson('POST', '/api/device/security', [], 'a')->assertStatus(422);
        $this->signedJson('POST', '/api/device/security', ['collection_id' => 'abcdefabcdefabcdef', 'sources' => [['source' => 'events', 'state' => str_repeat('a', 64)]]], 'a')->assertStatus(422);
        $this->signedJson('POST', '/api/device/security', ['collection_id' => 'abcdefabcdefabcdef', 'sources' => [['source' => 'software', 'state' => 'nope']]], 'a')->assertStatus(422)
            ->assertJsonPath('error', 'software: unknown, twice, or without a valid state / base');
        // Windows PowerShell 5.1 writes the rows as {"value": [...], "Count": 3}: taken as they are meant.
        $key = substr(hash('sha256', 'k'), 0, 16);
        $wrapped = SecurityScanner::normalizeSources([['source' => 'software', 'state' => str_repeat('a', 64), 'full' => true,
            'upsert' => [['value' => [$key, substr(hash('sha256', 'h'), 0, 16), ['Name' => '7-Zip', 'Source' => 'registry']], 'Count' => 3]]]], $problem);
        $this->assertNull($problem);
        $this->assertSame([$key, '7-Zip'], [$wrapped[0]['upsert'][0][0], $wrapped[0]['upsert'][0][2]['Name']]);
        // The agent's log says what is wrong (an alert of the device).
        $tooMany = collect(range(1, 1001))->map(fn ($i) => [substr(hash('sha256', "p$i"), 0, 16), substr(hash('sha256', "h$i"), 0, 16), ['Name' => "p$i"]])->all();
        $this->signedJson('POST', '/api/device/security', ['collection_id' => 'abcdefabcdefabcdef', 'sources' => [['source' => 'processes', 'state' => str_repeat('a', 64), 'full' => true, 'upsert' => $tooMany]]], 'a')
            ->assertStatus(422)->assertJsonPath('error', 'processes: 1001 items, at most 1000');
        $this->assertSame(0, SecurityFinding::query()->count());
    }

    public function test_rules_follow_the_platform_and_thresholds(): void
    {
        $linux = $this->device('l', 'linux');
        $inventory = $this->inventory([
            'processes' => [['Name' => 'kworkerds', 'Path' => '/tmp/.x/kworkerds', 'CommandLine' => '/tmp/.x/kworkerds -o stratum+tcp://pool.example:3333', 'User' => 'www-data']],
            'admins' => [['Name' => 'root'], ['Name' => 'alice'], ['Name' => 'bob'], ['Name' => 'carol', 'Enabled' => true], ['Name' => 'old', 'Enabled' => false]],
            'posture' => ['FirewallEnabled' => false, 'SshRootLogin' => 'yes', 'SshPasswordAuthentication' => true],
            'logs' => [],
        ]);
        $this->send($linux, $inventory, 'l');

        $keys = $this->openKeys($linux);
        // Windows rules (WinRAR, the Windows firewall) do not apply.
        $this->assertNotContains('software.winrar-cve-2023-38831', $keys);
        $this->assertNotContains('posture.firewall-windows', $keys);
        $this->assertContains('posture.firewall-linux', $keys);
        $this->assertContains('posture.ssh-root-login', $keys);
        $this->assertContains('process.temp-folder-linux', $keys);
        $this->assertContains('process.miner', $keys);
        $admins = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'admins.many'))->get();
        $this->assertCount(1, $admins);
        $this->assertSame('4 administrator accounts: root, alice, bob, carol', $admins[0]->message);
    }

    public function test_acknowledging_quiets_findings_until_they_are_gone(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/topic']]);
        AlertRule::create(['user_id' => $user->id, 'type' => 'security', 'target' => ['all' => true]]);
        $device = $this->device();
        $this->send($device, $this->inventory());

        // High findings (telnet, brute force, encoded PowerShell, WinRAR, firewall) fire the alert.
        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        $this->assertContains('security', array_column(SmartAlerts::for($device), 'key'));
        $this->assertGreaterThan(0, SecurityFinding::severeCount());

        $this->actingAs($user);
        $page = Livewire::test(Page::class)->assertSee('AnyDesk 8.0.9 is installed');
        foreach (SecurityFinding::query()->active()->get() as $finding) {
            $page->call('acknowledge', $finding->id);
        }
        $this->assertSame(['triggered' => 0, 'resolved' => 1], AlertEvaluator::run());
        $this->assertNotContains('security', array_column(SmartAlerts::for($device), 'key'));

        // Acknowledged events are done; acknowledged inventory findings stay open, quietly.
        $bruteForce = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first();
        $this->assertNotNull($bruteForce->resolved_at);
        $telnet = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'listening.cleartext'))->first();
        $this->assertNull($telnet->resolved_at);
        $this->assertCounts(['opened' => 0], $this->send($device, $this->inventory(['logs' => []])));
        $this->assertNull($telnet->fresh()->resolved_at);
        $this->assertNotNull($telnet->fresh()->acknowledged_at);

        // Gone, then back: a new finding that needs attention.
        $this->send($device, $this->inventory(['listening' => [], 'logs' => []]));
        $this->assertNotNull($telnet->fresh()->resolved_at);
        $this->assertCounts(['opened' => 1], $this->send($device, $this->inventory(['logs' => []])));
        $this->assertSame(1, SecurityFinding::query()->active()->whereHas('rule', fn ($q) => $q->where('key', 'listening.cleartext'))->count());

        $page->call('reopen', $bruteForce->id);
        $this->assertNull($bruteForce->fresh()->resolved_at);
        $this->assertNull($bruteForce->fresh()->acknowledged_at);
    }

    public function test_system_admins_manage_the_rules(): void
    {
        $device = $this->device();
        $this->send($device, $this->inventory());
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);

        // A rule switched off takes its findings with it.
        $rule = SecurityRule::query()->where('key', 'software.remote-access')->first();
        $this->actingAs($admin);
        Livewire::test(Page::class, ['tab' => 'rules'])->assertSee($rule->name)->call('toggleRule', $rule->id);
        $this->assertNotContains('software.remote-access', $this->openKeys($device));

        // Its own rule in JSON, tried on the device before it is saved.
        $definition = [
            'key' => 'custom.games', 'name' => 'Games', 'severity' => 'info', 'platform' => 'windows', 'source' => 'software',
            'when' => ['field' => 'Name', 'op' => 'matches', 'value' => 'age of empires'],
            'message' => 'Game: {Name}',
        ];
        Livewire::test(RuleForm::class)
            ->set('json', '{"key": ')
            ->assertSee('Not valid JSON')
            ->set('previewDeviceId', $device->id)
            ->set('json', json_encode($definition))
            ->assertSee('The rule is valid.')
            ->assertSee('Game: Age of Empires II')
            ->call('save')
            ->assertDispatched('securityRuleSaved');
        $this->assertContains('custom.games', $this->openKeys($device));

        // A parser of its own, tried on a sample line.
        Livewire::test(RuleForm::class, ['kind' => 'parser'])
            ->assertSee('The rule is valid.')
            ->assertSee('alice failed su to root')
            ->set('sample', 'something else')
            ->assertSee('The parser does not match this record.')
            ->call('save');
        $this->assertTrue(SecurityRule::query()->parsers()->where('key', 'custom.su-failed')->exists());
        Livewire::test(Page::class, ['tab' => 'rules'])->assertSee('su: wrong password');

        // Built-in rules cannot be changed, copies can.
        $builtIn = SecurityRule::query()->where('key', 'posture.smb1')->first();
        Livewire::test(RuleForm::class, ['ruleId' => $builtIn->id])->assertSet('readOnly', true)
            ->set('json', json_encode(['severity' => 'info'] + $builtIn->definition))->call('save');
        $this->assertSame('high', $builtIn->fresh()->severity);
        Livewire::test(RuleForm::class, ['copyOf' => $builtIn->id])
            ->assertSet('readOnly', false)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue(SecurityRule::query()->where('key', 'custom.posture.smb1-copy')->exists());
        // A key that is taken.
        Livewire::test(RuleForm::class)->set('json', json_encode(['key' => 'posture.smb1'] + $definition))->assertSee('another rule has this key');

        // Users see the rules, but do not change them.
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class, ['tab' => 'rules'])->call('toggleRule', $builtIn->id)->assertForbidden();
        Livewire::test(RuleForm::class)->assertForbidden();
    }

    public function test_the_pages_show_the_findings(): void
    {
        $this->withoutVite();
        $device = $this->device();
        $this->send($device, $this->inventory());
        $user = User::factory()->create();

        $this->actingAs($user)->get('/security')->assertOk()->assertSee('AnyDesk 8.0.9 is installed');
        $this->actingAs($user)->get('/security?tab=events')->assertOk()->assertSee('203.0.113.7');
        $this->actingAs($user)->get('/security?tab=rules')->assertOk()->assertSee('Remote access tool');
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])->assertSee('security-tab-pane', false);
        Livewire::test(DeviceSecurity::class, ['deviceId' => $device->id])
            ->assertSee('AnyDesk 8.0.9 is installed')
            ->assertSee('Microsoft Defender')
            ->assertSee('tlntsvr.exe');
        Livewire::test(Page::class)->set('search', 'winrar')->assertSee('WinRAR')->assertDontSee('AnyDesk 8.0.9');
        Livewire::test(Page::class)->set('severity', 'critical')->assertSee('Nothing needs attention');
    }

    public function test_acknowledged_findings_of_a_device_are_folded_away(): void
    {
        $this->withoutVite();
        $device = $this->device();
        $this->send($device, $this->inventory());
        $this->actingAs(User::factory()->create());
        $finding = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'software.remote-access'))->firstOrFail();

        $component = Livewire::test(DeviceSecurity::class, ['deviceId' => $device->id]);
        $this->assertTrue($component->viewData('findings')->contains('id', $finding->id));
        $this->assertCount(0, $component->viewData('acknowledged'));

        $component->call('acknowledge', $finding->id);
        $this->assertFalse($component->viewData('findings')->contains('id', $finding->id));
        $this->assertTrue($component->viewData('acknowledged')->contains('id', $finding->id));

        $component->call('reopen', $finding->id);
        $this->assertTrue($component->viewData('findings')->contains('id', $finding->id));
        $this->assertCount(0, $component->viewData('acknowledged'));
    }

    public function test_scanning_without_an_inventory_does_nothing(): void
    {
        $device = $this->device();
        $this->assertSame(['opened' => 0, 'resolved' => 0], SecurityScanner::scan($device));
        $this->assertSame(['opened' => 0, 'resolved' => 0], SecurityScanner::scanAll());
    }
}
