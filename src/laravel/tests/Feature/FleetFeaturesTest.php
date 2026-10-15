<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Livewire\Notifications\DeviceRules;
use App\Livewire\Notifications\Page;
use App\Livewire\Notifications\RuleForm;
use App\Livewire\Script\Schedule;
use App\Livewire\ShowDevices;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\NotificationSetting;
use App\Models\Script;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Tags, notifications with alert rules, scheduled remediations and Wake-on-LAN. */
class FleetFeaturesTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(string $token, array $machine = [], bool $online = true, string $version = '1.10.0'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->data = json_encode(['machine' => $machine + ['Hostname' => "pc-$token", 'AgentVersion' => $version, 'Platform' => 'linux', 'ScriptsEnabled' => true, 'Drives' => []]]);
        $device->save();
        $this->registerDeviceKey($device);
        if ($online) {
            Device::recordHeartbeat($device->id);
        } else {
            $device->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        }

        return $device->fresh();
    }

    private function network(string $ip, int $prefix = 24, string $mac = 'AA-BB-CC-DD-EE-01', string $type = 'lan'): array
    {
        return ['Name' => 'eth0', 'Type' => $type, 'Connected' => true, 'Status' => 'Up', 'Mac' => $mac, 'IPAddresses' => [$ip], 'Addresses' => [['Address' => $ip, 'PrefixLength' => $prefix]]];
    }

    // Tags

    public function test_tags_are_normalized_and_filter_the_device_list(): void
    {
        $this->assertSame(['Servers', 'home lab'], Device::normalizeTags(' Servers, servers ,home   lab, <b>, '));

        $user = User::factory()->create();
        $server = $this->device('a');
        $laptop = $this->device('b');
        $this->actingAs($user);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $server->id])
            ->call('startEditTags')
            ->set('tagsText', 'servers, Rack 1')
            ->call('saveTags')
            ->assertDispatched('device-tags-changed');
        $this->assertSame(['servers', 'Rack 1'], $server->fresh()->tags);
        $this->assertSame(['Rack 1', 'servers'], Device::allTags());

        Livewire::test(ShowDevices::class)
            ->call('filterTag', 'servers')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('device.id')->all() === [$server->id]);

        $this->assertTrue($server->fresh()->matchesTarget(['tags' => ['SERVERS']]));
        $this->assertFalse($laptop->matchesTarget(['tags' => ['servers']]));
        $this->assertTrue($laptop->matchesTarget(['devices' => [$laptop->id]]));
        $this->assertSame([$server->id, $laptop->id], Device::targeted(['all' => true])->pluck('id')->all());
    }

    public function test_device_list_searches_and_filters(): void
    {
        $nas = $this->device('nas');
        $nas->forceFill(['tags' => ['servers']])->save();
        $this->device('pc', online: false);
        $fine = $this->device('ok');
        $this->actingAs(User::factory()->create());

        Livewire::test(ShowDevices::class)
            ->assertSet('selectedDeviceId', $nas->id)
            ->assertViewHas('total', 3)
            ->set('search', 'PC-OK')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('device.id')->all() === [$fine->id])
            ->set('search', '')
            ->call('filterTag', 'servers')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('device.id')->all() === [$nas->id])
            ->call('selectDevice', $fine->id)
            ->assertSet('selectedDeviceId', $fine->id);
        Livewire::test(ShowDevices::class, ['selectedDeviceId' => 999])->assertSet('selectedDeviceId', $nas->id);
    }

    // Scheduled remediations

    public function test_scheduled_script_runs_once_per_due_minute_on_its_target(): void
    {
        $tagged = $this->device('a');
        $tagged->forceFill(['tags' => ['servers']])->save();
        $other = $this->device('b');
        $script = Script::create(['name' => 'Check', 'platform' => 'all', 'detection' => 'exit 0', 'timeout' => 60, 'schedule' => '0 3 * * *', 'schedule_target' => ['tags' => ['servers']]]);
        $version = $script->version;

        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-05 02:59:00')));
        $this->assertSame(1, Script::runScheduled(Carbon::parse('2026-10-05 03:00:00')));
        // The same minute again (a second scheduler) does nothing.
        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-05 03:00:30')));
        $this->assertSame([$tagged->id], $script->runs()->pluck('device_id')->all());
        $this->assertSame($version, $script->fresh()->version, 'The schedule is not a new version');
        $this->assertSame(1, $script->fresh()->runs()->count());
        $this->assertSame(0, $other->scriptRuns()->count());

        $this->assertSame('2026-10-06 03:00', $script->fresh()->nextScheduledRun(Carbon::parse('2026-10-05 03:00:00'))->format('Y-m-d H:i'));

        // In the configured time zone: 3:00 in Prague (CEST) is 1:00 UTC.
        config(['mdm.timezone' => 'Europe/Prague']);
        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-06 03:00:00', 'UTC')));
        $this->assertSame(1, Script::runScheduled(Carbon::parse('2026-10-06 01:00:00', 'UTC')));
        $this->assertSame('2026-10-07 03:00 Europe/Prague', $script->fresh()->nextScheduledRun(Carbon::parse('2026-10-06 01:00:00', 'UTC'))->format('Y-m-d H:i e'));
    }

    public function test_schedule_modal_validates_and_saves(): void
    {
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $script = Script::create(['name' => 'Check', 'platform' => 'all', 'detection' => 'exit 0', 'timeout' => 60]);
        $this->actingAs($admin);

        Livewire::test(Schedule::class, ['scriptId' => $script->id])
            ->set('enabled', true)
            ->set('schedule', 'every day')
            ->call('save')
            ->assertHasErrors('schedule')
            ->set('schedule', '*/15  * * * *')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('closeModal');
        $this->assertSame('*/15 * * * *', $script->fresh()->schedule);
        $this->assertTrue($script->fresh()->schedule_target['all']);

        // Ping-only devices cannot run scripts: not offered.
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.50', 'tags' => ['printers']])->save();
        Livewire::test(Schedule::class, ['scriptId' => $script->id])->set('targetMode', 'devices')->assertDontSee('Printer')->set('targetMode', 'tags')->assertDontSee('printers');
        Livewire::test(\App\Livewire\Script\Run::class, ['scriptId' => $script->id])->assertDontSee('Printer');

        Livewire::test(Schedule::class, ['scriptId' => $script->id])->set('enabled', false)->call('save');
        $this->assertNull($script->fresh()->schedule);

        $this->actingAs(User::factory()->create());
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        Livewire::test(Schedule::class, ['scriptId' => $script->id])->assertForbidden();
    }

    // Notifications

    public function test_notification_urls_are_turned_into_requests(): void
    {
        Http::fake();

        Notifier::send('ntfy://user:pass@ntfy.example.com/alerts?priority=high', 'Title ✅', 'Body');
        Notifier::send('discord://tok@123', 'T', 'M');
        Notifier::send('telegram://123:ABC@telegram?chats=@one,2', 'T', 'M');
        Notifier::send('gotify://gotify.example.com/sub/AppToken?disabletls=yes', 'T', 'M');
        Notifier::send('generic+http://hooks.local:8080/in?x=1', 'T', 'M');
        Notifier::send('slack://hook:T0-B0-XX@webhook', 'T', 'M');
        Notifier::send('pushover://shoutrrr:apptoken@userkey', 'T', 'M');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $urls = $sent->map(fn (Request $request) => $request->url())->all();
        $this->assertSame([
            'https://ntfy.example.com/alerts',
            'https://discord.com/api/webhooks/123/tok',
            'https://api.telegram.org/bot123:ABC/sendMessage',
            'https://api.telegram.org/bot123:ABC/sendMessage',
            'http://gotify.example.com/sub/message?token=AppToken',
            'http://hooks.local:8080/in?x=1',
            'https://hooks.slack.com/services/T0/B0/XX',
            'https://api.pushover.net/1/messages.json',
        ], $urls);
        $ntfy = $sent->first();
        $this->assertSame('Body', $ntfy->body());
        $this->assertSame('high', $ntfy->header('Priority')[0]);
        $this->assertStringStartsWith('=?UTF-8?B?', $ntfy->header('Title')[0]);
        $this->assertSame(['2', '@one'], $sent->slice(2, 2)->map(fn ($request) => $request['chat_id'])->sort()->values()->all());
        $this->assertSame(['title' => 'T', 'message' => 'M'], $sent[5]->data());

        $this->assertFalse(Notifier::validUrl('https://example.com'));
        $this->assertFalse(Notifier::validUrl('telegram://token@telegram'));
        $this->assertSame('discord://…@123', Notifier::redact('discord://tok@123'));
    }

    public function test_new_device_alert_is_sent_once_per_added_device(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/topic']]);
        $before = $this->device('old');
        $this->travel(1)->minutes();
        $this->actingAs($user);
        Livewire::test(RuleForm::class)->set('type', 'new_device')->call('save')->assertHasNoErrors();
        $rule = AlertRule::where('type', 'new_device')->sole();
        $this->assertSame(['all' => true], $rule->target);
        $this->travel(1)->minutes();

        // An agent that has not reported yet waits for its name; a ping-only device goes right away.
        $enrolled = new Device;
        $enrolled->forceFill(['token' => hash('sha256', 'new'), 'name' => '', 'os' => ''])->save();
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();
        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'Printer was added (ping-only, 192.168.1.50)'));

        $enrolled->forceFill(['name' => 'LAPTOP-NEW', 'os' => 'Windows 11 Pro', 'data' => json_encode(['machine' => ['Hostname' => 'LAPTOP-NEW', 'AgentVersion' => '1.14.0', 'Platform' => 'windows', 'Drives' => []]])])->save();
        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'LAPTOP-NEW was enrolled (Windows 11 Pro, agent 1.14.0)'));
        // Once each; the device that was there before the rule is not new; nothing stays open.
        $this->assertSame(['triggered' => 0, 'resolved' => 0], AlertEvaluator::run());
        Http::assertSentCount(2);
        $this->assertSame(0, $rule->events()->whereNull('resolved_at')->count());
        $this->assertNotContains($before->id, $rule->events()->pluck('device_id')->all());
        // Not a switch of a single device.
        Livewire::test(DeviceRules::class, ['deviceId' => $before->id])->assertViewHas('types', fn ($types) => ! isset($types['new_device']));
    }

    public function test_status_alert_triggers_once_and_resolves(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/topic']]);
        $device = $this->device('a', online: false);
        AlertRule::create(['user_id' => $user->id, 'type' => 'status', 'minutes' => 5, 'target' => ['all' => true]]);

        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        $this->assertSame(['triggered' => 0, 'resolved' => 0], AlertEvaluator::run());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->header('Title')[0] !== '' && str_contains($request->body(), 'is offline'));

        Device::recordHeartbeat($device->id);
        $this->assertSame(['triggered' => 0, 'resolved' => 1], AlertEvaluator::run());
        Http::assertSentCount(2);
        $this->assertNotNull($device->fresh()->id);
        $this->assertSame(0, AlertRule::first()->events()->whereNull('resolved_at')->count());
    }

    public function test_notification_links_use_the_address_the_portal_is_opened_with(): void
    {
        $this->withoutVite();
        config(['app.url' => 'http://localhost']);
        // Only a signed-in user's page is taken, not any request's Host header.
        $this->get('http://evil.example/login');
        $this->assertNull(\App\Support\PortalUrl::remembered());
        // Nor a user who is not a system admin (they could send any Host header with their session).
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $this->actingAs(User::factory()->create())->get('https://evil.example/devices')->assertOk();
        $this->assertNull(\App\Support\PortalUrl::remembered());
        $this->actingAs($admin)->get('https://mdm.example.com/devices')->assertOk();
        $this->assertSame('https://mdm.example.com', \App\Support\PortalUrl::remembered());

        // The scheduler (no request) links there.
        \App\Support\PortalUrl::apply();
        $this->assertSame('https://mdm.example.com/devices?selectedDeviceId=5', url('/devices?selectedDeviceId=5'));

        // APP_URL set to a real address wins.
        config(['app.url' => 'https://portal.example.org']);
        $this->assertTrue(\App\Support\PortalUrl::configured());
        $this->actingAs($admin)->get('https://other.example.com/devices');
        $this->assertSame('https://mdm.example.com', \App\Support\PortalUrl::remembered());
    }

    public function test_metric_disk_and_scope_alerts(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $busy = $this->device('a', ['Drives' => [['FriendlyName' => 'System', 'DriveLetter' => 'C', 'Size' => 100, 'SizeRemaining' => 5]]]);
        $idle = $this->device('b');
        $idle->forceFill(['tags' => ['lab']])->save();
        foreach (range(1, 20) as $i) {
            DeviceMetric::create(['device_id' => $busy->id, 'cpu' => 95, 'memory_used' => 1, 'memory_total' => 10]);
            DeviceMetric::create(['device_id' => $idle->id, 'cpu' => 5, 'memory_used' => 1, 'memory_total' => 10]);
        }
        $cpu = AlertRule::create(['user_id' => $user->id, 'type' => 'cpu', 'threshold' => 80, 'minutes' => 10, 'target' => ['all' => true]]);
        $disk = AlertRule::create(['user_id' => $user->id, 'type' => 'disk', 'threshold' => 90, 'target' => ['devices' => [$busy->id]]]);
        $memory = AlertRule::create(['user_id' => $user->id, 'type' => 'memory', 'threshold' => 5, 'minutes' => 10, 'target' => ['tags' => ['lab']]]);

        AlertEvaluator::run();
        $this->assertSame([$busy->id], $cpu->events()->pluck('device_id')->all());
        $this->assertEqualsWithDelta(95.0, $cpu->events()->first()->value, 0.01);
        $this->assertStringContainsString('System (C) 95 %', $disk->events()->first()->message);
        $this->assertSame([$idle->id], $memory->events()->pluck('device_id')->all());

        // Out of the target: closed without a message.
        $memory->update(['target' => ['tags' => ['other']]]);
        AlertEvaluator::run();
        $this->assertSame(0, $memory->events()->whereNull('resolved_at')->count());
        // No channels saved: recorded, nothing sent.
        Http::assertNothingSent();
    }

    public function test_disk_and_memory_alerts_in_free_gb(): void
    {
        $gb = 1073741824;
        $user = User::factory()->create();
        $device = $this->device('a', ['Drives' => [
            ['FriendlyName' => 'System', 'DriveLetter' => 'C', 'Size' => 500 * $gb, 'SizeRemaining' => 8 * $gb, 'DriveType' => 3],
            ['FriendlyName' => 'Data', 'DriveLetter' => 'D', 'Size' => 4000 * $gb, 'SizeRemaining' => 400 * $gb, 'DriveType' => 3],
        ]]);
        foreach (range(1, 20) as $i) {
            DeviceMetric::create(['device_id' => $device->id, 'cpu' => 5, 'memory_used' => 15 * $gb, 'memory_total' => 16 * $gb]);
        }
        // 90 % used on D would not alert in percent (400 GB free), 8 GB free on C does in GB.
        $disk = AlertRule::create(['user_id' => $user->id, 'type' => 'disk', 'unit' => 'gb', 'limit_gb' => 10, 'target' => ['all' => true]]);
        $memory = AlertRule::create(['user_id' => $user->id, 'type' => 'memory', 'unit' => 'gb', 'limit_gb' => 2, 'minutes' => 10, 'target' => ['all' => true]]);
        $enough = AlertRule::create(['user_id' => $user->id, 'type' => 'memory', 'unit' => 'gb', 'limit_gb' => 0.5, 'minutes' => 10, 'target' => ['all' => true]]);
        $this->assertSame('A drive with less than 10 GB free', $disk->condition);
        $this->assertSame('Average free memory below 2 GB for 10 min', $memory->condition);

        AlertEvaluator::run();
        $this->assertStringContainsString('System (C) 8 GB', $disk->events()->sole()->message);
        $this->assertStringNotContainsString('Data', $disk->events()->sole()->message);
        $this->assertEqualsWithDelta(1.0, $memory->events()->sole()->value, 0.01);
        $this->assertSame(0, $enough->events()->count());

        // Switching to percent: 98.4 % used on C is above 90 %.
        $this->actingAs($user);
        Livewire::test(RuleForm::class, ['ruleId' => $disk->id])
            ->assertSet('unit', 'gb')
            ->set('unit', 'percent')
            ->set('threshold', 90)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['percent', null, 90], [$disk->fresh()->unit, $disk->fresh()->limit_gb, $disk->fresh()->threshold]);
        $this->assertNotNull($disk->events()->sole()->resolved_at, 'A changed condition starts over');

        Livewire::test(RuleForm::class)
            ->set('type', 'memory')
            ->set('unit', 'gb')
            ->set('limitGb', 0)
            ->call('save')
            ->assertHasErrors('limitGb')
            ->set('limitGb', 1.5)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1.5, AlertRule::latest('id')->first()->limit_gb);

        // The bell: switching the unit keeps the rule, with the GB limit.
        Livewire::test(DeviceRules::class, ['deviceId' => $device->id])
            ->set('settings.disk.enabled', true)
            ->set('settings.disk.unit', 'gb')
            ->set('settings.disk.limit_gb', 25);
        $own = AlertRule::where('type', 'disk')->get()->first(fn ($rule) => $rule->target['devices'] === [$device->id]);
        $this->assertSame(['gb', 25.0, null], [$own->unit, $own->limit_gb, $own->threshold]);
    }

    public function test_notification_page_saves_channels_and_rules(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $device = $this->device('a');
        $this->actingAs($user);

        Livewire::test(Page::class)
            ->set('emails', "me@example.com\nnot-an-email")
            ->set('urls', ['ntfy://ntfy.sh/x', 'https://nope'])
            ->call('save')
            ->assertHasErrors(['emails', 'urls.1'])
            ->set('emails', 'me@example.com')
            ->set('urls', ['ntfy://ntfy.sh/x', ''])
            ->call('save')
            ->assertHasNoErrors()
            ->call('testUrl', 0)
            ->assertSet('tests.0.ok', true);
        $this->assertSame(['ntfy://ntfy.sh/x'], NotificationSetting::for($user)->urlList);
        $this->assertSame(['me@example.com'], NotificationSetting::for($user)->emailList);

        Livewire::test(RuleForm::class)
            ->set('type', 'cpu')
            ->assertSet('threshold', 80)
            ->set('targetMode', 'devices')
            ->call('save')
            ->assertHasErrors('target')
            ->set('targetDevices', [(string) $device->id])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['cpu', 80, 10, [$device->id]], [AlertRule::first()->type, AlertRule::first()->threshold, AlertRule::first()->minutes, AlertRule::first()->target['devices']]);

        // The bell of the device: one switch per type.
        Livewire::test(DeviceRules::class, ['deviceId' => $device->id])
            ->assertSet('settings.cpu.enabled', true)
            ->set('settings.status.enabled', true)
            ->set('settings.cpu.threshold', 150)
            ->assertSet('settings.cpu.threshold', 99);
        $this->assertSame(99, AlertRule::where('type', 'cpu')->first()->threshold);
        $this->assertSame(2, AlertRule::count());
        Livewire::test(DeviceRules::class, ['deviceId' => $device->id])->set('settings.status.enabled', false);
        $this->assertSame(['cpu'], AlertRule::pluck('type')->all());

        // Other users do not see or change the rules.
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class)->assertViewHas('rules', fn ($rules) => $rules->isEmpty());
        Livewire::test(Page::class)->call('deleteRule', AlertRule::first()->id);
        $this->assertSame(1, AlertRule::count());
    }

    public function test_alert_channels_preview_and_delete(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/a', 'discord://tok@1']]);
        $down = $this->device('a', online: false);
        $this->device('b');
        $this->actingAs($user);

        // The form shows where the alert would fire now and picks the channels.
        $form = Livewire::test(RuleForm::class)
            ->set('type', 'status')
            ->set('minutes', 5)
            ->assertSee('Would fire now on: pc-a.')
            ->assertSet('channels', ['ntfy://ntfy.sh/a', 'discord://tok@1'])
            ->set('channels', [])
            ->call('save')
            ->assertHasErrors('channels')
            ->set('channels', ['discord://tok@1'])
            ->call('save')
            ->assertHasNoErrors();
        $rule = AlertRule::sole();
        $this->assertSame(['discord://tok@1'], $rule->channels);

        AlertEvaluator::run();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));

        // All channels picked: saved as "all", so channels added later are used too.
        Livewire::test(RuleForm::class, ['ruleId' => $rule->id])
            ->set('channels', ['ntfy://ntfy.sh/a', 'discord://tok@1'])->call('save');
        $this->assertNull($rule->fresh()->channels);

        // A channel that is removed is taken out of the alerts that picked it.
        $rule->update(['channels' => ['discord://tok@1']]);
        Livewire::test(Page::class)->set('tab', 'channels')->set('urls', ['ntfy://ntfy.sh/a'])->call('save');
        $this->assertNull($rule->fresh()->channels);

        Livewire::test(Page::class)
            ->assertSee('Firing now')
            ->assertSee('pc-a')
            ->set('tab', 'history')
            ->assertViewHas('events', fn ($events) => $events->count() === 1);
        $this->assertSame(1, \App\Models\AlertEvent::firingCountFor($user));

        Livewire::test(RuleForm::class, ['ruleId' => $rule->id])->call('delete')->assertDispatched('closeModal');
        $this->assertSame(0, AlertRule::count());
    }

    // Wake-on-LAN

    public function test_wake_goes_through_an_online_agent_in_the_same_network(): void
    {
        $sleeping = $this->device('sleep', ['Networks' => [$this->network('192.168.1.20', 24, 'AA-BB-CC-DD-EE-01'), $this->network('10.8.0.2', 24, '00-00-00-00-00-00', 'vpn')]], online: false);
        $elsewhere = $this->device('far', ['Networks' => [$this->network('192.168.2.5')]]);
        $old = $this->device('old', ['Networks' => [$this->network('192.168.1.6')]], version: '1.8.1');
        // It says what keeps the agent of the network from relaying.
        $this->assertSame(__('No online agent 1.9.0+ in the same network').' (pc-old: '.__('The agent is too old for this command').')', $sleeping->wakeRefusal());

        $relay = $this->device('relay', ['Networks' => [$this->network('192.168.1.5', 24, 'AA-BB-CC-DD-EE-02')]]);
        $this->assertNull($sleeping->wakeRefusal());
        [$found, $broadcasts] = $sleeping->wakeRelay();
        $this->assertSame($relay->id, $found->id);
        $this->assertSame(['192.168.1.255', '255.255.255.255'], $broadcasts);

        // A relay behind another public address is not in the same network.
        Device::query()->whereKey($sleeping->id)->update(['public_ip' => '1.1.1.1']);
        Device::query()->whereKey($relay->id)->update(['public_ip' => '2.2.2.2']);
        $this->assertNull($sleeping->fresh()->wakeRelay());
        // An agent in the server's own network reaches it over a private address: that tells nothing.
        Device::query()->whereKey($relay->id)->update(['public_ip' => '192.168.1.5']);
        $this->assertSame($relay->id, $sleeping->fresh()->wakeRelay()[0]->id);
        // Both reaching the server over private addresses of the same LAN: nothing tells them apart.
        Device::query()->whereKey($sleeping->id)->update(['public_ip' => '192.168.1.180']);
        $this->assertSame($relay->id, $sleeping->fresh()->wakeRelay()[0]->id);
        Device::query()->whereKey($relay->id)->update(['public_ip' => '1.1.1.1']);

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $sleeping->id])->call('wake')->assertHasNoErrors();
        $command = $relay->commands()->sole();
        $this->assertSame(['macs' => ['AA:BB:CC:DD:EE:01'], 'broadcasts' => ['192.168.1.255', '255.255.255.255'], 'device' => $sleeping->id, 'title' => 'pc-sleep'], $command->params);
        $this->assertSame('device:'.$sleeping->id, $command->target);
        // No second wake while one is on its way.
        $this->assertNull($sleeping->fresh()->wake());

        $this->signedJson('POST', '/api/device/commands/take', [], 'relay')
            ->assertJsonPath('tasks.0.command', 'wake')
            ->assertJsonPath('tasks.0.params.macs', ['AA:BB:CC:DD:EE:01'])
            ->assertJsonMissingPath('tasks.0.params.title');
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'succeeded', 'message' => 'Magic packet sent to AA:BB:CC:DD:EE:01'], 'relay')->assertOk();
        $this->assertSame('succeeded', $sleeping->fresh()->recentWake()->status);
        $this->assertSame(0, $elsewhere->commands()->count() + $old->commands()->count());
    }

    public function test_wake_runs_until_the_device_reports_and_fails_as_an_alert_of_the_device(): void
    {
        $sleeping = $this->device('sleep', ['Networks' => [$this->network('192.168.1.20')]], online: false);
        $relay = $this->device('relay', ['Networks' => [$this->network('192.168.1.5', 24, 'AA-BB-CC-DD-EE-02')]], version: '1.13.2');
        // The same network reaching the server over IPv6 from one and over IPv4 from the other.
        Device::query()->whereKey($sleeping->id)->update(['public_ip' => '2001:db8::20']);
        Device::query()->whereKey($relay->id)->update(['public_ip' => '1.1.1.1']);
        $this->assertNull($sleeping->fresh()->wakeRefusal());

        $this->actingAs(User::factory()->create());
        $command = $sleeping->fresh()->wake();
        $this->signedJson('POST', '/api/device/commands/take', [], 'relay')->assertJsonPath('tasks.0.command', 'wake');
        // Agents 1.13.2+: running once the packet is out; a progress bar on the woken device.
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'running', 'progress' => 50, 'message' => 'Magic packet sent to AA:BB:CC:DD:EE:01, waiting for the device to come online'], 'relay')->assertOk();
        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $sleeping->id])
            ->assertSee('waiting for the device to come online')
            ->assertSeeHtml('progress-bar');

        // In the history of the woken device too, through the relay.
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $sleeping->id])
            ->assertViewHas('history', fn ($history) => $history->pluck('id')->contains($command->id))
            ->assertSee('through pc-relay');

        // The device reports: done.
        $this->signedJson('POST', '/api/device/heartbeat', [], 'sleep')->assertNoContent();
        $this->assertSame('succeeded', $command->fresh()->status);
        $this->assertStringStartsWith('Online after', $command->fresh()->message);

        // Another time it does not come online: an alert of the device, not of the relay.
        Device::query()->whereKey($sleeping->id)->update(['last_seen_at' => now()->subHour()]);
        $again = $sleeping->fresh()->wake();
        $this->signedJson('POST', '/api/device/commands/take', [], 'relay');
        $this->signedJson('POST', "/api/device/commands/{$again->id}", ['status' => 'running', 'progress' => 50], 'relay')->assertOk();
        $this->travel(11)->minutes();
        Device::recordHeartbeat($relay->id, channel: 'http');
        DeviceCommand::expireStale();
        $this->assertSame('expired', $again->fresh()->status);
        $this->assertStringContainsString('did not come online within 10 minutes', $again->fresh()->message);

        $alerts = collect(\App\Support\SmartAlerts::for($sleeping->fresh()));
        $wake = $alerts->firstWhere('key', 'wake');
        $this->assertSame('danger', $wake['severity']);
        $this->assertSame('wake', $wake['action']['command']);
        $this->assertNull($wake['refusal']);
        $this->assertNull(collect(\App\Support\SmartAlerts::for($relay->fresh()))->first(fn ($alert) => str_starts_with($alert['key'], 'failed:')));

        // Try again from the alert: a new wake through the relay.
        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Livewire\DeviceAlerts::class, ['selectedDeviceId' => $sleeping->id])->call('runAlert', 'wake')->assertHasNoErrors();
        $this->assertSame(1, $relay->commands()->where('command', 'wake')->active()->count());
    }

    public function test_a_wake_of_an_older_relay_shows_a_progress_bar_until_the_device_is_back(): void
    {
        $sleeping = $this->device('sleep', ['Networks' => [$this->network('192.168.1.20')]], online: false);
        $this->device('relay', ['Networks' => [$this->network('192.168.1.5', 24, 'AA-BB-CC-DD-EE-02')]], version: '1.12.0');
        $this->actingAs(User::factory()->create());
        $command = $sleeping->fresh()->wake();
        $this->signedJson('POST', '/api/device/commands/take', [], 'relay');
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'succeeded', 'message' => 'Magic packet sent to AA:BB:CC:DD:EE:01'], 'relay')->assertOk();

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $sleeping->id])
            ->assertSeeHtml('progress-bar')
            ->assertSee('Magic packet sent by pc-relay, waiting for the device to come online');
    }

    public function test_errors_from_the_agent_log_are_an_alert_of_the_device(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');
        $device = $this->device('a', version: '1.13.2');
        $errors = [
            ['message' => 'Inventory collection failed: access denied', 'count' => 3, 'first' => now()->subHour()->getTimestamp(), 'last' => now()->subMinutes(5)->getTimestamp()],
            ['message' => 'Report failed: HTTP 500', 'count' => 1, 'first' => now()->subMinutes(2)->getTimestamp(), 'last' => now()->subMinutes(2)->getTimestamp()],
            ['message' => 'Too old', 'count' => 1, 'last' => now()->subDays(8)->getTimestamp()],
            ['message' => '', 'last' => now()->getTimestamp()],
        ];
        $this->signedJson('POST', '/api/device/errors', ['errors' => $errors], 'a')->assertJson(['taken' => 2]);
        // The same error again is counted, not listed twice.
        $this->signedJson('POST', '/api/device/errors', ['errors' => [$errors[1]]], 'a')->assertJson(['taken' => 1]);

        $device->refresh();
        $this->assertSame(['Report failed: HTTP 500', 'Inventory collection failed: access denied'], array_column($device->recentAgentErrors, 'message'));
        $this->assertSame(2, $device->recentAgentErrors[0]['count']);

        $alert = collect(\App\Support\SmartAlerts::for($device))->firstWhere('key', 'agent_errors');
        $this->assertSame('The agent reported 2 errors', $alert['title']);
        $this->assertStringStartsWith('Report failed: HTTP 500 · 2 times', $alert['message']);
        $this->assertCount(1, $alert['details']);

        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Livewire\DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('The agent reported 2 errors')
            ->call('runAlert', 'agent_errors')
            ->assertDontSee('The agent reported');
        $this->assertNull($device->fresh()->agent_errors);
        Carbon::setTestNow();
    }

    public function test_ping_only_device_is_pinged_by_a_stationary_agent_in_its_network(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(ShowDevices::class)
            ->set('addDevice', true)
            ->set('addMode', 'ping')
            ->set('pingName', 'Printer')
            ->set('pingAddress', '192.168.1.300')
            ->call('createPingDevice')
            ->assertHasErrors('pingAddress')
            ->set('pingAddress', '192.168.1.50')
            ->set('pingMac', 'aa-bb-cc-dd-ee-50')
            ->call('createPingDevice')
            ->assertHasNoErrors();
        $printer = Device::where('kind', 'ping')->sole();
        $this->assertSame(['192.168.1.50', 24, 'AA:BB:CC:DD:EE:50'], [$printer->ping_address, $printer->ping_prefix, $printer->ping_mac]);
        $this->assertTrue($printer->offline, 'Not answered yet');
        $this->assertSame('ping', $printer->type);

        $laptop = $this->device('laptop', ['Type' => 'laptop', 'Battery' => 80, 'Networks' => [$this->network('192.168.1.7')]]);
        $old = $this->device('old', ['Networks' => [$this->network('192.168.1.8')]], version: '1.9.0');
        $this->assertNull($printer->pingRelay(), 'Laptops move between networks, 1.9.0 does not ping');

        $nas = $this->device('nas', ['Networks' => [$this->network('192.168.1.5')]], version: '1.10.0');
        $this->assertSame($nas->id, $printer->pingRelay()->id);
        $this->assertSame([['id' => $printer->id, 'address' => '192.168.1.50']], Device::pingTargetsFor($nas));
        $this->assertSame([], Device::pingTargetsFor($laptop));

        // The report hands the list over, the results make it online.
        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'pc-nas', 'Drives' => [], 'AgentVersion' => '1.10.0', 'Networks' => [$this->network('192.168.1.5')]]], 'nas')
            ->assertJsonPath('ping_targets.0.address', '192.168.1.50');
        $this->signedJson('POST', '/api/device/pings', ['results' => [['id' => $printer->id, 'up' => true, 'rtt' => 2.4], ['id' => $nas->id, 'up' => true]]], 'nas')
            ->assertJson(['taken' => 1]);
        $this->assertFalse($printer->fresh()->offline);
        $this->assertSame([2.4, $nas->id], [$printer->fresh()->ping_rtt, $printer->fresh()->ping_relay_id]);
        $this->assertSame([true], \App\Models\PingResult::where('device_id', $printer->id)->pluck('up')->all());
        $this->assertSame(100.0, \App\Models\PingResult::uptime($printer->id, now()->subDay()));
        \App\Models\PingResult::create(['device_id' => $printer->id, 'up' => false]);
        $this->assertSame(50.0, \App\Models\PingResult::uptime($printer->id, now()->subDay()));
        // Another agent cannot report it.
        $this->signedJson('POST', '/api/device/pings', ['results' => [['id' => $printer->id, 'up' => true]]], 'laptop')->assertJson(['taken' => 0]);

        // Wake-on-LAN through the same agent, with the MAC that was entered.
        $printer->forceFill(['last_seen_at' => now()->subHour()])->save();
        // Any stationary agent 1.9.0+ can wake (not the laptop).
        $this->assertContains($printer->fresh()->wakeRelay()[0]->id, [$old->id, $nas->id]);
        $this->assertSame(['AA:BB:CC:DD:EE:50'], $printer->fresh()->wake()->params['macs']);
        $this->assertSame(0, $laptop->commands()->count());
        // Only the status alert makes sense for it.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);
        Livewire::test(\App\Livewire\PingMonitor::class, ['deviceId' => $printer->id])
            ->assertSee('50 %')
            ->assertSee('192.168.1.50')
            ->call('setRange', '7d')->assertSet('range', '7d');
        // The range from the URL (?range=30d), as for devices with the agent.
        Livewire::withQueryParams(['range' => '30d'])->test(\App\Livewire\PingMonitor::class, ['deviceId' => $printer->id])->assertSet('range', '30d');
        Livewire::withQueryParams(['range' => 'nope'])->test(\App\Livewire\PingMonitor::class, ['deviceId' => $printer->id])->assertSet('range', '1h');
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $printer->id])
            ->set('address', 'nope')->call('save')->assertHasErrors('address')
            ->set('address', '192.168.1.51')->set('mac', '')->call('save')->assertHasNoErrors()->assertDispatched('ping-settings-saved');
        $this->assertSame(['192.168.1.51', null, null], [$printer->fresh()->ping_address, $printer->fresh()->ping_mac, $printer->fresh()->last_seen_at]);
        $printer->forceFill(['ping_mac' => 'AA:BB:CC:DD:EE:50'])->save();
        Livewire::test(DeviceRules::class, ['deviceId' => $printer->id])->assertViewHas('types', fn ($types) => array_keys($types) === ['status']);
    }

    public function test_a_device_on_a_battery_only_wakes_behind_the_same_public_address(): void
    {
        $sleeping = $this->device('pc', ['Networks' => [$this->network('192.168.1.20')]], online: false);
        $laptop = $this->device('laptop', ['Battery' => 60, 'Networks' => [$this->network('192.168.1.7', 24, 'AA-BB-CC-DD-EE-07', 'wifi')]]);
        $this->assertNull($sleeping->wakeRelay(), 'The laptop may be in another 192.168.1.0/24');

        Device::query()->whereKey([$sleeping->id, $laptop->id])->update(['public_ip' => '203.0.113.7']);
        $this->assertSame($laptop->id, $sleeping->fresh()->wakeRelay()[0]->id);

        // A stationary agent wins over the laptop, one with an old report does not count.
        $desk = $this->device('desk', ['Networks' => [$this->network('192.168.1.9')]]);
        $this->assertSame($desk->id, $sleeping->fresh()->wakeRelay()[0]->id);
        $desk->forceFill(['updated_at' => now()->subHour(), 'last_http_at' => now()->subHour()])->saveQuietly();
        $this->assertSame($laptop->id, $sleeping->fresh()->wakeRelay()[0]->id);
    }

    public function test_device_changes_are_announced_for_live_updates(): void
    {
        $device = $this->device('a');
        \Illuminate\Support\Facades\Event::fake([\App\Events\DevicesChanged::class]);

        Device::recordHeartbeat($device->id);
        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'pc', 'Drives' => [], 'AgentVersion' => '1.10.1']], 'a')->assertOk();
        $device->issueCommand('restart');

        $announced = collect(\Illuminate\Support\Facades\Event::dispatched(\App\Events\DevicesChanged::class))->map(fn ($call) => $call[0]->what)->all();
        $this->assertSame(['heartbeat', 'report', 'command'], $announced);
        $event = new \App\Events\DevicesChanged($device->id, 'report');
        $this->assertSame('private-devices', $event->broadcastOn()->name);
        $this->assertSame(['device_id' => $device->id, 'what' => 'report'], $event->broadcastWith());

        // A device that just went offline is announced by the scheduler.
        $device->forceFill(['last_seen_at' => now()->subSeconds(120)])->save();
        $this->assertSame(1, Device::announceNewlyOffline());
    }

    public function test_wake_parameters_are_checked(): void
    {
        $this->assertNull(DeviceCommand::sanitizeParams('wake', ['macs' => ['nope'], 'broadcasts' => ['192.168.1.255'], 'device' => 1]));
        $this->assertNull(DeviceCommand::sanitizeParams('wake', ['macs' => ['AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['fe80::1'], 'device' => 1]));
        $this->assertSame(
            ['macs' => ['AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['192.168.1.255'], 'device' => 3],
            DeviceCommand::sanitizeParams('wake', ['macs' => ['aa-bb-cc-dd-ee-ff', 'AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['192.168.1.255'], 'device' => 3, 'evil' => 'x']),
        );
    }

    public function test_report_records_the_public_address(): void
    {
        $device = $this->device('a');
        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'pc', 'Drives' => [], 'AgentVersion' => '1.9.0']], 'a', ['server' => ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1']])->assertOk();
        $this->assertSame('203.0.113.7', $device->fresh()->public_ip);
    }

    public function test_public_addresses_are_shown(): void
    {
        foreach (['8.8.8.8' => true, '2a00:1450:4014::1' => true, '10.0.0.1' => false, '192.168.1.5' => false, '100.64.1.1' => false, 'fe80::1%12' => false, 'fd00::1' => false, '::1' => false, 'nonsense' => false] as $address => $public) {
            $this->assertSame($public, Device::isPublicIp($address), $address);
        }

        $device = $this->device('a', ['Networks' => [
            ['Name' => 'Ethernet', 'Status' => 'Up', 'Connected' => true, 'IPAddresses' => ['192.168.1.5', 'fe80::1%12', '2a00:1450:4014::1']],
            ['Name' => 'Wi-Fi', 'Status' => 'Down', 'Connected' => false, 'IPAddresses' => ['2a00:1450:4014::2']],
        ]]);
        $device->forceFill(['public_ip' => '8.8.8.8'])->save();
        $this->assertSame(['8.8.8.8', '2a00:1450:4014::1'], $device->fresh()->publicAddresses);

        // The server in the same network sees a private address: not shown.
        $device->forceFill(['public_ip' => '192.168.1.5'])->save();
        $this->assertSame(['2a00:1450:4014::1'], $device->fresh()->publicAddresses);

        $device->forceFill(['public_ip' => '8.8.8.8'])->save();
        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Public address')->assertSee('8.8.8.8');
        Livewire::test(ShowDevices::class)->set('search', '8.8.8')->assertSee('pc-a');
    }

    public function test_windows_updates_waiting_for_a_restart_are_not_installable(): void
    {
        $device = $this->device('a', ['Platform' => 'windows', 'RestartRequired' => true]);
        $data = json_decode($device->getRawOriginal('data'), true);
        $data['os_updates'] = [['Id' => 'u1', 'Title' => 'Cumulative Update', 'RebootRequired' => true], ['Id' => 'u2', 'Title' => 'Defender', 'RebootRequired' => false]];
        $device->forceFill(['data' => json_encode($data)])->save();

        $device = $device->fresh();
        $this->assertSame(['installable', 'restart'], array_column($device->updates, 'Status'));
        $this->assertSame(['u2'], array_column($device->installableUpdates, 'Id'));
    }

    public function test_data_collected_without_the_server_is_backfilled(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $device = $this->device('a', version: '1.11.0');
        $sample = fn ($at, $cpu) => ['at' => $at->getTimestamp(), 'cpu' => $cpu, 'memory_used' => 4e9, 'memory_total' => 8e9];
        $samples = [
            $sample(now()->subHours(2), 40),
            $sample(now()->subHours(2)->addSeconds(30), 41),
            $sample(now()->subDays(40), 50),          // older than the retention
            $sample(now()->addHour(), 60),            // from the future
            ['at' => now()->subHour()->getTimestamp(), 'cpu' => 'x'],
        ];
        $this->signedJson('POST', '/api/device/metrics/backfill', ['samples' => $samples], 'a')->assertJson(['taken' => 2]);
        // Sent again (the answer got lost): nothing twice.
        $this->signedJson('POST', '/api/device/metrics/backfill', ['samples' => $samples], 'a')->assertJson(['taken' => 0]);
        $stored = DeviceMetric::where('device_id', $device->id)->where('created_at', '<', now()->subHour())->orderBy('created_at')->get();
        $this->assertSame([40.0, 41.0], $stored->pluck('cpu')->all());
        $this->assertSame('2026-10-01 10:00:00', $stored->first()->created_at->format('Y-m-d H:i:s'));

        // Pings of the ping-only devices in its network, at the time they were made.
        $relay = $this->device('nas', ['Networks' => [$this->network('192.168.1.5')]], version: '1.11.0');
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();
        $other = new Device;
        $other->forceFill(['kind' => 'ping', 'name' => 'Elsewhere', 'os' => '', 'token' => hash('sha256', 'q'), 'ping_address' => '10.9.9.9', 'ping_prefix' => 24])->save();
        $at = now()->subMinutes(20)->getTimestamp();
        $backlog = [
            ['id' => $printer->id, 'up' => true, 'rtt' => 3.1, 'at' => $at],
            ['id' => $printer->id, 'up' => false, 'at' => $at + 30],
            ['id' => $other->id, 'up' => true, 'at' => $at],   // not its target
        ];
        $this->signedJson('POST', '/api/device/pings', ['results' => [], 'backlog' => $backlog], 'nas')->assertJson(['backfilled' => 2]);
        $this->signedJson('POST', '/api/device/pings', ['results' => [], 'backlog' => $backlog], 'nas')->assertJson(['backfilled' => 0]);
        $this->assertSame([true, false], \App\Models\PingResult::where('device_id', $printer->id)->orderBy('created_at')->pluck('up')->all());
        $this->assertSame(0, \App\Models\PingResult::where('device_id', $other->id)->count());
        // The history only: the device stays offline until a live ping answers.
        $this->assertTrue($printer->fresh()->offline);
        Carbon::setTestNow();
    }

    public function test_wake_is_in_the_menu_of_physical_devices(): void
    {
        $this->actingAs(User::factory()->create());
        $pc = $this->device('pc', ['Networks' => [$this->network('192.168.1.20')]], version: '1.11.0');
        $vm = $this->device('vm', ['Networks' => [$this->network('192.168.1.21')], 'Virtualization' => ['Type' => 'vm', 'Name' => 'kvm']], version: '1.11.0');

        // Online: in the menu, disabled with the reason.
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $pc->id])
            ->assertSeeHtml('wire:click="wake"')->assertSee('The device is online');
        // A virtual machine is not woken by a magic packet.
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $vm->id])
            ->assertDontSeeHtml('wire:click="wake"');
    }

    public function test_wake_on_lan_can_be_set_by_hand(): void
    {
        // The agent reports only a VPN card: nothing to wake, no network to find a relay in.
        $pc = $this->device('pc', ['Networks' => [$this->network('10.8.0.2', 24, 'AA-BB-CC-DD-EE-09', 'vpn')]], online: false, version: '1.11.0');
        $relay = $this->device('relay', ['Networks' => [$this->network('192.168.1.5')]], version: '1.11.0');
        $this->assertSame(__('No wired or Wi-Fi network card is known'), $pc->wakeRefusal());

        $this->assertNull(Device::sanitizeWakeSettings('nonsense', null, null));
        $this->assertNull(Device::sanitizeWakeSettings(null, '192.168.1.300', 24));
        $this->assertNull(Device::sanitizeWakeSettings(null, '192.168.1.20', 31));
        $this->assertSame(['wake_mac' => null, 'wake_address' => null, 'wake_prefix' => null], Device::sanitizeWakeSettings('', ' ', 24));

        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Livewire\WakeSettings::class, ['deviceId' => $pc->id])
            ->set('mac', 'aa-bb-cc-dd-ee-10')->set('address', '192.168.1.20')->set('prefix', 24)
            ->call('save')->assertHasNoErrors();
        $pc = $pc->fresh();
        $this->assertSame(['AA:BB:CC:DD:EE:10'], $pc->wakeMacs);
        $this->assertSame(['192.168.1.0/24' => ['broadcast' => '192.168.1.255']], $pc->wakeNetworks());
        [$found, $broadcasts] = $pc->wakeRelay();
        $this->assertSame([$relay->id, ['192.168.1.255', '255.255.255.255']], [$found->id, $broadcasts]);
        $this->assertSame(['AA:BB:CC:DD:EE:10'], $pc->wake()->params['macs']);

        // Emptied again: what the agent reports.
        Livewire::test(\App\Livewire\WakeSettings::class, ['deviceId' => $pc->id])
            ->set('mac', '')->set('address', '')->call('save')->assertHasNoErrors();
        $this->assertSame([], $pc->fresh()->wakeMacs);
        // The settings are in the menu of the device.
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $pc->id])->assertSee('Wake-on-LAN settings');
    }

    public function test_pings_are_spread_over_all_agents_in_the_network(): void
    {
        $a = $this->device('a', ['Networks' => [$this->network('192.168.1.5')]], version: '1.11.0');
        $b = $this->device('b', ['Networks' => [$this->network('192.168.1.6')]], version: '1.11.0');
        $far = $this->device('far', ['Networks' => [$this->network('10.0.0.5')]], version: '1.11.0');
        $printers = collect(range(1, 4))->map(function ($i) {
            $device = new Device;
            $device->forceFill(['kind' => 'ping', 'name' => "Printer $i", 'os' => '', 'token' => hash('sha256', "p$i"), 'ping_address' => "192.168.1.5$i", 'ping_prefix' => 24])->save();

            return $device;
        });

        // Two each, none to the agent in another network.
        $count = fn (Device $relay) => count(Device::pingTargetsFor($relay));
        $this->assertSame([2, 2, 0], [$count($a), $count($b), $count($far)]);
        $this->assertNotSame($printers[0]->pingRelay()->id, $printers[1]->pingRelay()->id);

        // A device stays with the agent that pings it when that is as good (no moving around).
        $current = Device::pingAssignments();
        foreach ($current as $id => $relayId) {
            Device::query()->whereKey($id)->update(['ping_relay_id' => $relayId]);
        }
        $this->assertSame($current, Device::pingAssignments());

        // An agent goes offline: the other one takes all of them.
        $b->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $this->assertSame([4, 0], [$count($a), $count($b->fresh())]);
    }

    public function test_a_ping_only_device_in_another_subnet_is_pinged_over_the_router(): void
    {
        $this->actingAs(User::factory()->create());
        $archer = new Device;
        $archer->forceFill(['kind' => 'ping', 'name' => 'Archer NX500', 'os' => '', 'token' => hash('sha256', 'a'), 'ping_address' => '192.168.2.1', 'ping_prefix' => 24])->save();
        $nas = $this->device('nas', ['Networks' => [$this->network('192.168.1.5')]]);
        $this->device('laptop', ['Battery' => 80, 'Networks' => [$this->network('192.168.1.7')]]);

        // No agent in 192.168.2.0/24: the stationary ones (all at one site) ping it over their gateway.
        $this->assertSame($nas->id, $archer->fresh()->pingRelay()->id);
        $this->assertSame([['id' => $archer->id, 'address' => '192.168.2.1']], Device::pingTargetsFor($nas));

        // Agents at two sites: the same private address could be at either, each site tries in
        // turn (this server's first) until it answers, then it stays with that agent.
        $office = $this->device('office', ['Networks' => [$this->network('10.0.0.5')]]);
        Device::query()->whereKey($office->id)->update(['public_ip' => '89.24.10.5']);
        // Agents that keep reporting while the time goes on.
        $alive = function () use ($nas, $office) {
            Device::recordHeartbeat($nas->id, channel: 'http');
            Device::recordHeartbeat($office->id, channel: 'http');
        };
        $slot = intdiv(now()->getTimestamp(), Device::ROUTED_PING_SECONDS);
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(($slot + 2 - $slot % 2) * Device::ROUTED_PING_SECONDS + 10));
        $alive();
        $this->assertSame($nas->id, $archer->fresh()->pingRelay()->id);
        $this->travel(Device::ROUTED_PING_SECONDS)->seconds();
        $alive();
        $this->assertSame($office->id, $archer->fresh()->pingRelay()->id);
        $this->signedJson('POST', '/api/device/pings', ['results' => [['id' => $archer->id, 'up' => true, 'rtt' => 3]]], 'office')->assertJson(['taken' => 1]);
        $this->travel(Device::ROUTED_PING_SECONDS)->seconds();
        $alive();
        $this->assertSame($office->id, $archer->fresh()->pingRelay()->id);

        // One can be chosen for it.
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $archer->id])
            ->assertSee('pc-office')
            ->set('via', (string) $nas->id)->call('save')->assertHasNoErrors();
        $this->assertSame($nas->id, $archer->fresh()->ping_via_id);
        $this->assertSame($nas->id, $archer->fresh()->pingRelay()->id);
        $this->assertSame([], Device::pingTargetsFor($office->fresh()));
        // The chosen agent offline: nobody else takes it.
        $nas->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        $this->assertNull($archer->fresh()->pingRelay());
        Livewire::test(\App\Livewire\PingSettings::class, ['deviceId' => $archer->id])->set('via', '999999')->call('save')->assertHasErrors('via');
    }

    public function test_sync_of_a_ping_only_device_pings_it_now(): void
    {
        $this->actingAs(User::factory()->create());
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.50', 'ping_prefix' => 24])->save();
        $this->assertSame(__('No agents available to send ping'), $printer->pingNowRefusal());

        $old = $this->device('old', ['Networks' => [$this->network('192.168.1.5')]], version: '1.11.0');
        $this->assertSame('pc-old: '.__('Needs agent :version or newer', ['version' => '1.12.0']), $printer->pingNowRefusal());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $printer->id])->assertSee('Sync')->call('pingNow')->assertHasErrors('command');

        $old->forceFill(['data' => json_encode(['machine' => ['Hostname' => 'pc-old', 'AgentVersion' => '1.12.0', 'Platform' => 'linux', 'Drives' => [], 'Networks' => [$this->network('192.168.1.5')]]])])->saveQuietly();
        $this->assertNull($printer->fresh()->pingNowRefusal());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $printer->id])->call('pingNow')->assertHasNoErrors();
        $command = $old->commands()->sole();
        $this->assertSame(['pingNow', ['device' => $printer->id, 'title' => 'Printer'], 'ping:'.$printer->id], [$command->command, $command->params, $command->target]);
        // No second one while it is on its way.
        $this->assertNull($printer->fresh()->pingNow());

        $this->signedJson('POST', '/api/device/commands/take', [], 'old')->assertJsonPath('tasks.0.command', 'pingNow')->assertJsonPath('tasks.0.params.device', $printer->id);
        $this->signedJson('POST', '/api/device/pings', ['results' => [['id' => $printer->id, 'up' => true, 'rtt' => 1.2]]], 'old')->assertJson(['taken' => 1]);
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'succeeded', 'message' => '192.168.1.50 answered in 1 ms'], 'old')->assertOk();
        $this->assertFalse($printer->fresh()->offline);
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $printer->id])->assertSee('192.168.1.50 answered in 1 ms');
    }

    public function test_a_realtek_card_is_not_a_mobile_network(): void
    {
        // Agents before 1.12.1 on Windows matched "LTE" inside "Realtek".
        $device = $this->device('pc', ['Networks' => [
            ['Name' => 'Ethernet', 'InterfaceDescription' => 'Realtek Gaming 2.5GbE Family Controller', 'Type' => 'cellular', 'Connected' => true, 'Status' => 'Up', 'Mac' => '58-11-22-A1-59-F0', 'IPAddresses' => ['192.168.1.180']],
            ['Name' => 'Mobilní připojení', 'InterfaceDescription' => 'Generic Mobile Broadband Adapter', 'Type' => 'cellular', 'Connected' => false, 'Status' => 'Disconnected', 'Mac' => '', 'IPAddresses' => []],
        ]]);
        $types = collect($device->networks)->pluck('Type', 'Name')->all();
        $this->assertSame(['Ethernet' => 'lan', 'Mobilní připojení' => 'cellular'], $types);
        $this->assertSame('lan', Device::guessNetworkType('Ethernet', 'Realtek PCIe GbE Family Controller'));
        $this->assertSame(['58:11:22:A1:59:F0'], $device->wakeMacs);
    }

    public function test_networks_are_listed_public_physical_virtual_online_first(): void
    {
        $net = fn ($name, $type, $connected, $ip = '10.0.0.1') => ['Name' => $name, 'Type' => $type, 'Connected' => $connected, 'IPAddresses' => [$ip]];
        $sorted = Device::sortNetworks([
            $net('docker-off', 'docker', false),
            $net('wifi-off', 'wifi', false),
            $net('vpn-on', 'vpn', true),
            $net('lan-on', 'lan', true),
            $net('wan', 'lan', true, '31.30.4.122'),
            $net('lan2-on', 'lan', true),
        ]);
        $this->assertSame(['wan', 'lan-on', 'lan2-on', 'vpn-on', 'wifi-off', 'docker-off'], array_column($sorted, 'Name'));
    }

    public function test_a_winget_update_carries_its_source(): void
    {
        $device = $this->device('pc', ['Platform' => 'windows'], version: '1.12.3');
        $this->assertSame('winget', $device->updateTarget('app', ['Id' => 'BlenderFoundation.Blender', 'Source' => 'winget'])['source']);
        $this->assertSame('msstore', $device->updateTarget('app', ['Id' => '9NBLGGH4NNS1', 'Source' => 'msstore'])['source']);
        // Unknown: the agent tries the Microsoft Store, then the winget repository.
        $this->assertArrayNotHasKey('source', $device->updateTarget('app', ['Id' => 'Git.Git', 'Source' => '']));
        $this->assertArrayNotHasKey('source', DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'winget', 'id' => 'Git.Git', 'source' => 'evil; rm']));
        // Installed only for the logged-on user (agents 1.14.0+ update it in their session).
        $this->assertSame('user', $device->updateTarget('app', ['Id' => 'Discord.Discord', 'Source' => 'winget', 'Scope' => 'user'])['scope']);
        $this->assertArrayNotHasKey('scope', $device->updateTarget('app', ['Id' => 'Git.Git', 'Source' => 'winget', 'Scope' => 'machine']));
        $this->assertArrayNotHasKey('scope', DeviceCommand::sanitizeParams('installUpdate', ['kind' => 'winget', 'id' => 'Git.Git', 'scope' => 'root']));
    }

    public function test_winget_exit_codes_are_explained(): void
    {
        $device = $this->device('pc', ['Platform' => 'windows'], version: '1.12.4');
        $command = $device->commands()->create(['command' => 'installUpdate', 'params' => ['kind' => 'winget', 'id' => 'Heroic'], 'status' => 'failed', 'message' => 'winget upgrade Heroic: exit -1978335090, Found Heroic']);
        $this->assertSame('winget upgrade Heroic: exit 0x8A15008E (An upgrade is available but uses a different install technology than the current installation: uninstall it and install the new version), Found Heroic', $command->resultNote);
        $this->assertStringContainsString('HTTP 404 when downloading the installer', \App\Support\WingetCodes::explain('exit -2145844844'));
        // Not a winget code, or no code at all: unchanged.
        $this->assertSame('exit 1', \App\Support\WingetCodes::explain('exit 1'));
        $this->assertSame('Updated to 1.12.4', \App\Support\WingetCodes::explain('Updated to 1.12.4'));
    }
}
