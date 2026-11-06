<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityRule;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use App\Support\Syslog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Syslog of other servers and devices: the sender is the device whose IP address it is. */
class SyslogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Syslog::forget();
    }

    private function pingDevice(string $ip, string $token = 'p'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => "ping-$ip", 'os' => '', 'token' => hash('sha256', $token.$ip), 'ping_address' => $ip, 'ping_prefix' => 24]);
        $device->save();

        return $device->fresh();
    }

    private function agent(string $name, array $addresses, ?string $publicIp = null): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $name);
        $device->name = $name;
        $device->data = json_encode(['machine' => ['Hostname' => $name, 'Platform' => 'linux', 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'IPAddresses' => $addresses],
        ]]]);
        $device->save();
        if ($publicIp !== null) {
            $device->forceFill(['public_ip' => $publicIp])->saveQuietly();
        }

        return $device->fresh();
    }

    private function message(string $ip, string $app, string $text, array $more = []): array
    {
        return $more + ['ip' => $ip, 'time' => '2026-11-05T14:51:42+00:00', 'host' => 'web01', 'facility' => 'auth', 'severity' => 'info', 'app' => $app, 'pid' => '1234', 'msg' => " $text"];
    }

    private function parser(): array
    {
        return [
            'key' => 'custom.syslog-failed-password', 'name' => 'Failed password', 'source' => 'syslog',
            'when' => ['field' => 'App', 'op' => 'in', 'value' => ['sshd']],
            'pattern' => '^Failed password for (?:invalid user )?(?<user>\S+) from (?<ip>\S+) port',
            'event' => ['type' => 'failed_logon', 'user' => '{user}', 'source' => '{ip}', 'message' => 'ssh from {Host}'],
        ];
    }

    private function enableParser(): void
    {
        SecurityRule::syncBuiltIn();
        SecurityRule::create(['kind' => 'parser', 'key' => 'custom.syslog-failed-password', 'name' => 'Failed password', 'severity' => 'info', 'source' => 'syslog', 'platform' => 'any', 'definition' => $this->parser(), 'origin' => 'custom', 'enabled' => true]);
    }

    public function test_addresses_are_written_one_way(): void
    {
        $this->assertSame('192.168.1.5', Syslog::normalize('192.168.1.5'));
        $this->assertSame('192.168.1.5', Syslog::normalize('::ffff:192.168.1.5'));
        $this->assertSame('fe80::1', Syslog::normalize('FE80:0:0:0:0:0:0:1%eth0'));
        $this->assertNull(Syslog::normalize('web01'));
    }

    public function test_a_message_becomes_a_record_of_the_log_source(): void
    {
        $record = Syslog::record($this->message('10.0.0.5', 'sshd', 'Failed password for root from 1.2.3.4 port 22 ssh2', ['pid' => '-']));

        $this->assertSame(['sshd', '', 'Failed password for root from 1.2.3.4 port 22 ssh2'], [$record['App'], $record['Pid'], $record['Message']]);
        $this->assertNull(Syslog::record(['ip' => '10.0.0.5']));
        $this->assertSame(SecurityParsers::LOG_SOURCES['syslog']['fields'], array_keys($record));
    }

    public function test_the_sender_is_the_device_that_has_the_address(): void
    {
        $printer = $this->pingDevice('192.168.1.50');
        $server = $this->agent('srv', ['192.168.1.10', 'fe80::1%eth0'], '203.0.113.7');

        $this->assertSame($printer->id, Syslog::deviceIdFor('192.168.1.50'));
        $this->assertSame($server->id, Syslog::deviceIdFor('192.168.1.10'));
        $this->assertSame($server->id, Syslog::deviceIdFor('::ffff:192.168.1.10'));
        $this->assertSame($server->id, Syslog::deviceIdFor('203.0.113.7'));
        $this->assertNull(Syslog::deviceIdFor('192.168.1.99'));
        $this->assertNull(Syslog::deviceIdFor('127.0.0.1'));
    }

    public function test_an_address_of_more_devices_belongs_to_none(): void
    {
        // The Docker bridge or the NAT address: it says nothing about which device sent.
        $this->agent('a', ['172.17.0.1', '10.1.0.1'], '203.0.113.7');
        $this->agent('b', ['172.17.0.1', '10.1.0.2'], '203.0.113.7');

        $this->assertNull(Syslog::deviceIdFor('172.17.0.1'));
        $this->assertNull(Syslog::deviceIdFor('203.0.113.7'));
        $this->assertNotNull(Syslog::deviceIdFor('10.1.0.1'));
    }

    public function test_the_messages_of_a_device_are_parsed_into_its_events(): void
    {
        $this->enableParser();
        $router = $this->pingDevice('192.168.1.1');
        $messages = [
            $this->message('192.168.1.1', 'sshd', 'Failed password for root from 1.2.3.4 port 22 ssh2'),
            $this->message('192.168.1.1', 'sshd', 'Failed password for invalid user bob from 1.2.3.4 port 22 ssh2'),
            $this->message('192.168.1.1', 'cron', 'job started'),
            $this->message('192.168.1.77', 'sshd', 'Failed password for root from 9.9.9.9 port 22 ssh2'),
            $this->message('192.168.1.77', 'sshd', 'Failed password for root from 9.9.9.9 port 22 ssh2'),
        ];

        $result = Syslog::ingest($messages);

        $this->assertSame(['192.168.1.77' => 2], $result['unknown']);
        $this->assertSame(2, $result['events']);
        $events = SecurityEvent::query()->forDevice($router->id)->get()->sortBy('user')->values();
        $this->assertSame(['bob', 'root'], $events->pluck('user')->all());
        $this->assertSame(['1.2.3.4', '1.2.3.4'], $events->pluck('source')->all());
        $this->assertSame('ssh from web01', $events[0]->message);
        $this->assertSame(0, SecurityEvent::query()->where('eventable_id', '!=', $router->id)->count());
    }

    public function test_a_device_that_sends_syslog_is_marked(): void
    {
        $router = $this->pingDevice('192.168.1.1');
        $other = $this->pingDevice('192.168.1.2');
        $this->assertFalse($router->sendsSyslog);

        // Even without a parser: the device sends syslog.
        Syslog::ingest([$this->message('192.168.1.1', 'cron', 'job started'), $this->message('192.168.1.9', 'cron', 'job started')]);

        $this->assertTrue($router->fresh()->sendsSyslog);
        $this->assertFalse($other->fresh()->sendsSyslog);
        $this->assertTrue($router->fresh()->updated_at->equalTo($router->updated_at));

        // Not for ever: a device that stopped does not keep the icon.
        $router->forceFill(['syslog_at' => now()->subDays(2)])->saveQuietly();
        $this->assertFalse($router->fresh()->sendsSyslog);
    }

    public function test_nothing_is_stored_while_the_portal_takes_no_logs(): void
    {
        $this->enableParser();
        $router = $this->pingDevice('192.168.1.1');
        SecurityScanner::setLogsAllowed(false);

        $result = Syslog::ingest([$this->message('192.168.1.1', 'sshd', 'Failed password for root from 1.2.3.4 port 22 ssh2')]);

        $this->assertSame(0, $result['events']);
        $this->assertSame(0, SecurityEvent::query()->forDevice($router->id)->count());
    }

    public function test_the_agents_are_not_asked_for_syslog(): void
    {
        $this->assertArrayNotHasKey('syslog', SecurityParsers::policy([$this->parser()])['sources']);
        $this->assertSame([], SecurityParsers::errors($this->parser()));
    }
}
