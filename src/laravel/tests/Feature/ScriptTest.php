<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\Script\DataTable;
use App\Livewire\Script\Detail;
use App\Livewire\Script\Form;
use App\Livewire\Script\Run;
use App\Livewire\ScriptRun\DataTable as RunDataTable;
use App\Livewire\ScriptRun\Output as RunOutput;
use App\Models\Device;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\User;
use App\Support\Signing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use SteelAnts\LaravelBoilerplate\Models\Activity;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class ScriptTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(string $token = 'secret-token', string $platform = 'linux', bool $signed = true, bool $scriptsEnabled = true): Device
    {
        $device = new Device();
        $device->token = hash('sha256', $token);
        $device->name = "srv-$token";
        $device->data = json_encode(['machine' => ['Hostname' => "srv-$token", 'Platform' => $platform, 'ScriptsEnabled' => $scriptsEnabled, 'Drives' => []]]);
        $device->save();
        Device::recordHeartbeat($device->id);

        return $signed ? $this->registerDeviceKey($device) : $device->fresh();
    }

    private function script(array $attributes = []): Script
    {
        return Script::create($attributes + ['name' => 'Check', 'platform' => 'all', 'detection' => "if (\$true) {\n\texit 1\n}\n", 'remediation' => "'fixed'\n", 'timeout' => 60]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);

        return $admin;
    }

    public function test_fingerprint_and_version_follow_the_code(): void
    {
        $script = $this->script();
        $this->assertSame(1, $script->version);
        $this->assertSame(Script::fingerprintOf('all', 60, $script->detection, $script->remediation), $script->fingerprint);
        // Stored byte for byte, tabs and trailing new lines included.
        $this->assertSame("if (\$true) {\n\texit 1\n}\n", $script->fresh()->detection);

        $fingerprint = $script->fingerprint;
        $script->update(['name' => 'Renamed', 'description' => 'Only metadata']);
        $this->assertSame([1, $fingerprint], [$script->version, $script->fingerprint]);

        $script->update(['remediation' => "'fixed differently'\n"]);
        $this->assertSame(2, $script->version);
        $this->assertNotSame($fingerprint, $script->fingerprint);

        // An empty remediation means detection only.
        $script->update(['remediation' => "  \n"]);
        $this->assertNull($script->remediation);
    }

    public function test_run_is_queued_only_where_it_can_run(): void
    {
        $linux = $this->device('linux');
        $windows = $this->device('windows', 'windows');
        $legacy = $this->device('legacy', signed: false);
        $disabled = $this->device('disabled', scriptsEnabled: false);
        $script = $this->script(['platform' => 'linux']);

        $runs = $script->runOn([$linux->id, $windows->id, $legacy->id, $disabled->id], $this->admin());

        $this->assertSame([$linux->id], $runs->pluck('device_id')->all());
        $this->assertSame(['runScripts'], $linux->fresh()->queuedCommands);
        $this->assertSame('Other platform', $script->unavailableReason($windows));
        $this->assertSame('Agent does not sign (update it)', $script->unavailableReason($legacy));
        $this->assertSame('Scripts disabled on the device', $script->unavailableReason($disabled));
        $activity = Activity::query()->where('affected_type', Script::class)->latest('id')->first();
        $this->assertSame(['version' => 1, 'fingerprint' => $script->fingerprint, 'devices' => [$linux->id]], $activity->data);

        // Running again replaces the run still waiting.
        $script->runOn([$linux->id]);
        $this->assertSame(['superseded', 'pending'], $script->runs()->orderBy('id')->pluck('status')->all());
    }

    public function test_agent_takes_signed_runs_once(): void
    {
        $device = $this->device();
        $script = $this->script();
        $run = $script->runOn([$device->id])->first();

        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'srv1', 'Platform' => 'linux', 'ScriptsEnabled' => true, 'Drives' => []]], 'secret-token')
            ->assertJson(['scripts_pending' => true]);

        $response = $this->assertSignedResponse($this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertOk(), $device->id);
        $payload = $response->json('runs.0');
        $this->assertTrue(Signing::verify(Signing::publicKey(), 'MDM1-SCRIPT', $payload['manifest'], $payload['signature']));
        $manifest = json_decode($payload['manifest'], true);
        $this->assertSame([$run->id, $device->id, $script->fingerprint, 'all', 60], [$manifest['run_id'], $manifest['device_id'], $manifest['fingerprint'], $manifest['platform'], $manifest['timeout']]);
        $this->assertSame(hash('sha256', base64_decode($payload['detection'])), $manifest['detection_sha256']);
        $this->assertSame(hash('sha256', base64_decode($payload['remediation'])), $manifest['remediation_sha256']);
        $this->assertSame('sent', $run->fresh()->status);

        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertExactJson(['runs' => []]);
    }

    public function test_manual_remediation_only_detects_until_remediate(): void
    {
        $device = $this->device();
        $script = $this->script(['manual_remediation' => true]);
        $admin = $this->admin();
        $run = $script->runOn([$device->id], $admin)->first();
        $this->assertSame('detect', $run->mode);
        $this->assertSame($script->detectionFingerprint(), $run->fingerprint);

        // The device gets no remediation: no agent can remediate with this run.
        $payload = $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertOk()->json('runs.0');
        $manifest = json_decode($payload['manifest'], true);
        $this->assertNull($payload['remediation']);
        $this->assertNull($manifest['remediation_sha256']);
        $this->assertTrue($manifest['detect_only']);
        $this->assertSame(Script::fingerprintOf('all', 60, $script->detection, null), $manifest['fingerprint']);

        // Detection exits 1: needs remediation (agents report it as failed, there was nothing to run).
        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", ['status' => 'failed', 'fingerprint' => $manifest['fingerprint'], 'detection_exit' => 1], 'secret-token')
            ->assertOk()->assertExactJson(['status' => 'noncompliant']);

        $alert = collect(\App\Support\SmartAlerts::for($device->fresh()))->firstWhere('key', 'remediate:'.$script->id);
        $this->assertSame('Check needs remediation', $alert['title']);
        $this->assertSame('remediate', $alert['action']['command']);

        // Remediate in the device's runs: a full run, then the alert is gone.
        $this->actingAs($admin);
        Livewire::test(RunDataTable::class, ['deviceId' => $device->id])
            ->assertSee('Needs remediation')
            ->assertSeeHtml('remediate('.$run->id.')')
            ->call('remediate', $run->id);
        $full = $script->runs()->latest('id')->first();
        $this->assertNotSame($run->id, $full->id);
        $this->assertNull($full->mode);
        $this->assertSame($script->fingerprint, $full->fingerprint);
        $this->assertNull(collect(\App\Support\SmartAlerts::for($device->fresh()))->firstWhere('key', 'remediate:'.$script->id));
        $payload = $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->json('runs.0');
        $this->assertSame(hash('sha256', $script->remediation), json_decode($payload['manifest'], true)['remediation_sha256']);
        $this->signedJson('POST', "/api/device/scripts/runs/{$full->id}", ['status' => 'remediated', 'fingerprint' => $script->fingerprint, 'detection_exit' => 1, 'remediation_exit' => 0, 'post_detection_exit' => 0], 'secret-token')->assertOk();

        // From the alert: the next detect-only run found it again.
        $again = $script->runOn([$device->id])->first();
        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token');
        $this->signedJson('POST', "/api/device/scripts/runs/{$again->id}", ['status' => 'failed', 'fingerprint' => $again->fingerprint, 'detection_exit' => 1], 'secret-token');
        // Users who are not system admins do not run scripts.
        $this->actingAs(User::factory()->create());
        $this->assertSame('Only system admins run scripts', collect(\App\Support\SmartAlerts::for($device->fresh()))->firstWhere('key', 'remediate:'.$script->id)['refusal']);
        $this->actingAs($admin);
        Livewire::test(\App\Livewire\DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Check needs remediation')
            ->call('runAlert', 'remediate:'.$script->id)
            ->assertHasNoErrors();
        $this->assertNull($script->runs()->latest('id')->first()->mode);
    }

    public function test_expired_and_changed_runs_are_not_sent(): void
    {
        $device = $this->device();
        $expired = $this->script(['name' => 'Old'])->runOn([$device->id])->first();
        $expired->update(['expires_at' => now()->subMinute()]);
        $changed = $this->script(['name' => 'Changed']);
        $changedRun = $changed->runOn([$device->id])->first();
        $changed->update(['detection' => 'exit 0']);

        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertExactJson(['runs' => []]);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('superseded', $changedRun->fresh()->status);
    }

    public function test_scripts_need_a_signing_agent(): void
    {
        $this->device(signed: false);

        $this->withToken('secret-token')->getJson('/api/device/scripts')->assertForbidden();
    }

    public function test_agent_reports_the_result_of_its_own_run(): void
    {
        $device = $this->device();
        $other = $this->device('other-token');
        $script = $this->script();
        $run = $script->runOn([$device->id])->first();
        $otherRun = $script->runOn([$other->id])->first();

        // Not taken yet.
        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", ['status' => 'compliant', 'fingerprint' => $script->fingerprint], 'secret-token')
            ->assertStatus(409);
        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token');

        $this->signedJson('POST', "/api/device/scripts/runs/{$otherRun->id}", ['status' => 'compliant'], 'secret-token')->assertNotFound();

        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", [
            'status' => 'remediated',
            'fingerprint' => $script->fingerprint,
            'detection_exit' => 1,
            'remediation_exit' => 0,
            'post_detection_exit' => 0,
            'output' => str_repeat('x', 20000),
        ], 'secret-token')->assertOk()->assertExactJson(['status' => 'remediated']);

        $run->refresh();
        $this->assertSame(['remediated', 1, 0, 0], [$run->status, $run->detection_exit, $run->remediation_exit, $run->post_detection_exit]);
        $this->assertSame(ScriptRun::MAX_OUTPUT, strlen($run->output));
        $this->assertNotNull($run->finished_at);
    }

    public function test_result_of_another_fingerprint_is_an_error(): void
    {
        $device = $this->device();
        $run = $this->script()->runOn([$device->id])->first();
        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token');

        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", ['status' => 'compliant', 'fingerprint' => str_repeat('0', 64)], 'secret-token')
            ->assertExactJson(['status' => 'error']);
        $this->assertSame('The device ran a different fingerprint', $run->fresh()->error);
    }

    public function test_scripts_are_for_system_admins_only(): void
    {
        $this->withoutVite();
        $script = $this->script(['name' => 'Firewall on']);
        // APP_SYSTEM_ADMINS of the local .env would make the first users admins.
        config(['boilerplate.system_admins' => []]);
        $this->actingAs(User::factory()->create());
        $this->get('/scripts')->assertForbidden();
        $this->get('/scripts/'.$script->id)->assertForbidden();

        $this->actingAs($this->admin());
        $this->get('/scripts')->assertOk()->assertSee('Scripts')->assertSee('Firewall on');
        $this->get('/scripts/'.$script->id)->assertOk()->assertSee('Firewall on')->assertSee($script->fingerprint);
    }

    public function test_admin_creates_and_runs_a_script(): void
    {
        $this->actingAs($this->admin());
        $device = $this->device();
        $windows = $this->device('windows', 'windows');

        Livewire::test(Form::class)
            ->set('name', 'Disk space')
            ->set('platform', 'linux')
            ->set('detection', "\$free = 10\r\nif (\$free -lt 5) { exit 1 }\r\n")
            ->call('save')
            ->assertHasErrors('detection')
            ->set('detection', "\$free = 10\r\nif (\$free -lt 5) { exit 1 }\r\nexit 0\r\n")
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('scriptSaved');
        $script = Script::query()->where('name', 'Disk space')->firstOrFail();
        $this->assertSame("\$free = 10\r\nif (\$free -lt 5) { exit 1 }\r\nexit 0\r\n", $script->detection);
        $this->assertNull($script->remediation);

        Livewire::test(Run::class, ['scriptId' => $script->id])
            ->assertSee('srv-secret-token')
            ->assertSee('Other platform')
            ->set('selected', [(string) $device->id, (string) $windows->id])
            ->call('start');
        $this->assertSame([$device->id], $script->runs()->pluck('device_id')->all());

        // Opened again, the devices of the last run are selected.
        Livewire::test(Run::class, ['scriptId' => $script->id])->assertSet('selected', [(string) $device->id]);

        Livewire::test(DataTable::class)->assertSee('Disk space')->assertSee(route('script.show', $script))->assertDontSee('Detection only');
        Livewire::test(Detail::class, ['script' => $script])->assertSee('Disk space')->assertSee('v1');
        Livewire::test(RunDataTable::class, ['scriptId' => $script->id])->assertSee('srv-secret-token')->assertSee('Pending');
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])->assertSee('Scripts')->assertSee('Disk space');
    }

    public function test_scripts_are_checked_as_powershell_before_saving(): void
    {
        // The light check (no pwsh on the server).
        config(['mdm.pwsh' => '/nonexistent/pwsh']);
        $this->actingAs($this->admin());
        $form = fn (string $detection, string $remediation = '') => Livewire::test(Form::class)
            ->set('name', 'Check')->set('detection', $detection)->set('remediation', $remediation)->call('save');

        $error = fn ($component, string $field) => $component->errors()->first($field);
        $this->assertSame('Not valid PowerShell: line 1: { is not closed', $error($form("if (\$true) {\n  exit 1\n"), 'detection'));
        // Only in a comment or a string does not count.
        $this->assertSame('The detection has to end with exit 0 (compliant) and exit 1 (needs remediation), exit 0 is missing.', $error($form("# exit 0\nWrite-Host 'exit 0'\nexit 1"), 'detection'));
        $form("Write-Host \"x\"\nexit 0")->assertHasErrors('detection');
        $form("if (Test-Path /x) { exit 0 }\nexit 1", "Write-Host \"fix")->assertHasErrors(['remediation'])->assertHasNoErrors('detection');
        $big = "if (Test-Path /x) { exit 0 }\nexit 1\n# ".str_repeat('x', Script::MAX_CODE_BYTES);
        $this->assertSame('The script has 200.0 kB, at most 200 kB.', $error($form($big), 'detection'));
        // Nothing saved (only the script every installation starts with).
        $this->assertSame(0, Script::query()->where('name', 'Check')->count());

        $form("\$a = @\"\nmultiline \"text\" exit 5\n\"@\nif (Test-Path /x) { exit 0 }\nexit 1", "New-Item /x")->assertHasNoErrors();
        $this->assertSame(1, Script::query()->where('name', 'Check')->count());

        // The whole agent passes the light check.
        $this->assertSame([], \App\Support\PowerShellCheck::lex(file_get_contents(base_path('../powershell/app.ps1')))['errors']);
    }

    public function test_script_code_is_required(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Form::class)->set('name', 'Empty')->call('save')->assertHasErrors(['detection']);
        Livewire::test(Form::class)->set('name', 'Slow')->set('detection', 'exit 0')->set('timeout', 7200)->call('save')->assertHasErrors(['timeout']);
    }

    public function test_ai_writes_the_script_into_the_form(): void
    {
        $this->actingAs($this->admin());
        $script = fn (array $fields) => json_encode($fields + ['description' => '', 'platform' => 'all', 'timeout' => 60, 'remediation' => '', 'manual_remediation' => false]);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $script([
                    'name' => 'Firewall on', 'description' => 'Turns the firewall on.', 'platform' => 'windows', 'timeout' => 99999,
                    'detection' => "if ((Get-NetFirewallProfile).Enabled -contains \$false) { exit 1 }\nexit 0", 'remediation' => 'Set-NetFirewallProfile -Enabled True',
                ])]]])
                ->push(['stop_reason' => 'refusal', 'content' => []])
                ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
            'ollama:11434/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $script([
                'name' => 'Disk check', 'platform' => 'linux', 'detection' => "if ((Get-PSDrive /).Free -lt 1GB) { exit 1 }\nexit 0",
            ])]]]]),
        ]);

        config(['mdm.ai_provider' => 'anthropic', 'mdm.ai_api_key' => null, 'mdm.ai_url' => null, 'mdm.ai_model' => null]);
        Livewire::test(Form::class)->set('aiMode', true)->set('aiPrompt', 'Firewall on')->call('generate')
            ->assertHasErrors(['aiPrompt'])->assertSet('detection', '');
        Http::assertNothingSent();

        config(['mdm.ai_api_key' => 'test-key']);
        Livewire::test(Form::class)->set('aiMode', true)->set('aiPrompt', 'Turn the firewall on')->call('generate')
            ->assertHasNoErrors()->assertSet('aiMode', false)
            ->assertSet('name', 'Firewall on')->assertSet('platform', 'windows')->assertSet('timeout', Script::MAX_TIMEOUT)
            ->assertSet('remediation', 'Set-NetFirewallProfile -Enabled True')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('windows', Script::query()->where('name', 'Firewall on')->sole()->platform);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages' && $request['model'] === 'claude-opus-5-5'
            && $request->hasHeader('x-api-key', 'test-key') && str_contains($request['messages'][0]['content'], 'Turn the firewall on'));

        // Changing a script sends it along; a refusal leaves the form as it is.
        Livewire::test(Form::class)->set('detection', 'exit 0')->set('aiMode', true)->set('aiPrompt', 'Add logging')->call('generate')
            ->assertHasErrors(['aiPrompt'])->assertSet('detection', 'exit 0')->assertSet('aiMode', true);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Current script'));
        // What the API says is wrong is shown.
        $this->assertSame('The AI could not be reached: HTTP 401, invalid x-api-key', Livewire::test(Form::class)->set('aiMode', true)->set('aiPrompt', 'Firewall on')->call('generate')->errors()->first('aiPrompt'));

        // OpenAI, or a server of your own with its API (no key needed then).
        config(['mdm.ai_provider' => 'openai', 'mdm.ai_api_key' => null, 'mdm.ai_url' => 'http://ollama:11434/v1/chat/completions', 'mdm.ai_model' => 'qwen3']);
        Livewire::test(Form::class)->set('aiMode', true)->set('aiPrompt', 'Free space')->call('generate')
            ->assertHasNoErrors()->assertSet('name', 'Disk check')->assertSet('platform', 'linux')->assertSet('remediation', '');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama:11434/v1/chat/completions' && $request['model'] === 'qwen3'
            && ! $request->hasHeader('Authorization') && $request['messages'][0]['role'] === 'system' && $request['response_format']['type'] === 'json_schema');

        // Without the address OpenAI needs a key; an unknown provider is off.
        config(['mdm.ai_url' => null]);
        $this->assertFalse(\App\Support\ScriptGenerator::available());
        config(['mdm.ai_provider' => 'gemini', 'mdm.ai_api_key' => 'key']);
        $this->assertFalse(\App\Support\ScriptGenerator::available());
    }

    public function test_a_finding_is_remediated_with_ai(): void
    {
        $this->actingAs($this->admin());
        config(['mdm.ai_provider' => 'anthropic', 'mdm.ai_api_key' => 'test-key', 'mdm.ai_url' => null, 'mdm.ai_model' => null]);
        \App\Models\SecurityRule::syncBuiltIn();
        $rule = \App\Models\SecurityRule::query()->detection()->get()->first(fn ($rule) => ! $rule->isEvent);
        $device = $this->device();
        $finding = \App\Models\SecurityFinding::query()->create([
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => 'test', 'severity' => 'high',
            'message' => 'Firewall is off', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
            'name' => 'Firewall on', 'description' => 'Turns it on.', 'platform' => 'linux', 'timeout' => 60,
            'detection' => "if (-not (Test-Path /x)) { exit 1 }\nexit 0", 'remediation' => 'ufw enable', 'manual_remediation' => false,
        ])]]])]);

        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id)->assertHasNoErrors();

        $script = Script::query()->where('name', 'Firewall on')->sole();
        $this->assertTrue($script->manual_remediation);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Firewall is off') && ! str_contains($request['messages'][0]['content'], 'References of the detection')
            && ! str_contains($request['messages'][0]['content'], 'Operating system'));

        // The operating system and its version of the device go along: the script is written for that system.
        $device->forceFill(['os' => 'Ubuntu 26.04 LTS'])->save();
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "Platform: linux\nOperating system: Ubuntu 26.04 LTS"));

        // The links of the detection (advisories, vendor documentation) go along: they name recommended mitigations.
        $rule->update(['definition' => array_merge($rule->definition, ['urls' => ['https://example.org/advisory', 'https://vendor.example/kb']])]);
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "References of the detection (advisories, vendor documentation):\n- https://example.org/advisory\n- https://vendor.example/kb"));

        // The other open findings of the device go along, so one script fixes them together.
        \App\Models\SecurityFinding::query()->create([
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => 'second', 'severity' => 'critical',
            'message' => 'php-cli 8.5.4 is outdated', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Other open findings') && str_contains($request['messages'][0]['content'], 'php-cli 8.5.4 is outdated'));

        // Anybody signed in may ask for it; the script waits for a system admin.
        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id)
            ->assertNoRedirect()->assertSet('remediateError', null)->assertNotSet('remediateDone', null);
        // Asking again for the findings of a rule updates its script, it does not write another one.
        $this->assertSame(1, Script::query()->where('name', 'Firewall on')->count());
        $this->assertSame($rule->id, $script->fresh()->security_rule_id);
        $this->assertTrue(Script::query()->where('name', 'Firewall on')->sole()->is($script));
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'A script for this rule exists') && str_contains($request['messages'][0]['content'], 'Current script'));
    }

    public function test_asking_for_a_new_version_of_a_remediation_script_keeps_its_name_and_makes_a_version(): void
    {
        $this->actingAs($this->admin());
        config(['mdm.ai_provider' => 'anthropic', 'mdm.ai_api_key' => 'test-key', 'mdm.ai_url' => null, 'mdm.ai_model' => null]);
        \App\Models\SecurityRule::syncBuiltIn();
        $rule = \App\Models\SecurityRule::query()->detection()->get()->first(fn ($rule) => ! $rule->isEvent);
        $device = $this->device();
        $finding = \App\Models\SecurityFinding::query()->create([
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => 'test', 'severity' => 'high',
            'message' => 'LXD is affected', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'details' => ['Status' => 'no fix available yet', 'Cves' => 'CVE-2025-54289', 'Channel' => '4.0/stable/…'],
        ]);
        $answer = fn (string $name, string $remediation) => ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
            'name' => $name, 'description' => 'Updates LXD.', 'platform' => 'linux', 'timeout' => 60,
            'detection' => "if (-not (Test-Path /x)) { exit 1 }\nexit 0", 'remediation' => $remediation, 'manual_remediation' => false,
        ])]]];

        // Two answers one after the other (a later Http::fake would not replace the first one).
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($answer('LXD CVE-2025-54289 (snap)', 'snap refresh lxd'))
            ->push($answer('LXD (Snap) aktualisieren', 'snap refresh lxd --channel=5.21/stable'))]);
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id);
        $script = Script::query()->where('security_rule_id', $rule->id)->sole();
        $this->assertSame(1, $script->version);
        // The AI is told what the scanner found out (status, CVEs, channel, the rule) and what the detection has to mean.
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "Rule: {$rule->name} ({$rule->key})")
            && str_contains($request['messages'][0]['content'], 'Status of the CVEs: no fix available yet')
            && str_contains($request['messages'][0]['content'], 'CVEs: CVE-2025-54289')
            && str_contains($request['messages'][0]['content'], 'Snap channel: 4.0/stable/…')
            && str_contains($request['messages'][0]['content'], 'is not a fix'));

        // Another language or another answer of the AI: the same script, its name stays, the code is a new version.
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('remediateWithAi', $finding->id);

        $script = Script::query()->where('security_rule_id', $rule->id)->sole();
        $this->assertSame('LXD CVE-2025-54289 (snap)', $script->name);
        $this->assertSame('snap refresh lxd --channel=5.21/stable', $script->remediation);
        $this->assertSame(2, $script->version);
    }

    public function test_a_failed_run_is_fixed_with_ai_into_a_copy_that_only_runs_by_hand(): void
    {
        $this->actingAs($this->admin());
        config(['mdm.ai_provider' => 'anthropic', 'mdm.ai_api_key' => 'test-key', 'mdm.ai_url' => null, 'mdm.ai_model' => null]);
        $device = $this->device();
        $script = $this->script(['name' => 'Cleanup']);
        $run = $script->runOn([$device->id])->first();
        $run->forceFill(['status' => 'failed', 'detection_exit' => 1, 'remediation_exit' => 2, 'error' => 'Remove-Item: access denied', 'output' => "step 1\nstep 2 failed"])->save();
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
            'name' => 'Cleanup', 'description' => 'Cleans up.', 'platform' => 'all', 'timeout' => 60,
            'detection' => "if (Test-Path /x) { exit 1 }\nexit 0", 'remediation' => 'Remove-Item /x -Force', 'manual_remediation' => false,
        ])]]])]);

        $device->forceFill(['os' => 'Ubuntu 26.04 LTS'])->save();
        $scripts = Script::query()->count();
        Livewire::test(RunOutput::class, ['runId' => $run->id])->assertSee('AI fix')->call('aiFix')->assertHasNoErrors()->assertRedirect();

        // The AI got the script, the error, the exit codes and the output; the result is the script itself, a new version.
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Remove-Item: access denied')
            && str_contains($request['messages'][0]['content'], 'step 2 failed')
            && str_contains($request['messages'][0]['content'], 'remediation 2')
            && str_contains($request['messages'][0]['content'], 'operating system Ubuntu 26.04 LTS')
            && str_contains($request['messages'][0]['content'], 'Current script'));
        $this->assertSame($scripts, Script::query()->count(), 'no copy is written');
        $fixed = $script->fresh();
        $this->assertSame('Cleanup', $fixed->name);
        $this->assertTrue($fixed->manual_remediation);
        $this->assertSame('Remove-Item /x -Force', $fixed->remediation);
        $this->assertStringContainsString('Test-Path /x', $fixed->detection);
        $this->assertSame(2, $fixed->version);

        // Only a failed run is fixed, and only by a system admin.
        $ok = $script->runOn([$device->id])->first();
        $ok->forceFill(['status' => 'compliant'])->save();
        Livewire::test(RunOutput::class, ['runId' => $ok->id])->assertDontSee('AI fix')->call('aiFix');
        Http::assertSentCount(1);
        $this->actingAs(User::factory()->create());
        Livewire::test(RunOutput::class, ['runId' => $run->id])->call('aiFix')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_failed_devices_run_the_whole_script_again_by_their_latest_run(): void
    {
        $this->actingAs($this->admin());
        $script = $this->script(['name' => 'Patch', 'manual_remediation' => true]);
        $set = fn (Device $device, string $status) => $script->runs()->create(['device_id' => $device->id, 'version' => $script->version, 'fingerprint' => $script->fingerprint,
            'status' => $status, 'issued_at' => now(), 'expires_at' => now()->addHour()]);

        $recovered = $this->device('a');   // failed, then went well: not one of them
        $set($recovered, 'failed');
        $set($recovered, 'compliant');
        $failed = $this->device('b');
        $set($failed, 'failed');
        $errored = $this->device('c');     // an error, then a failure: its latest run counts
        $set($errored, 'error');
        $set($errored, 'failed');
        $unsigned = $this->device('d', 'linux', false);   // cannot run it now
        $set($unsigned, 'failed');
        $waiting = $this->device('e');
        $set($waiting, 'failed');
        $set($waiting, 'pending');

        Livewire::test(Detail::class, ['script' => $script])->assertSee('Rerun failed (2)')->assertSee('srv-b')->assertSee('srv-c')
            ->call('rerunFailed')->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$failed->id, $errored->id], ScriptRun::query()->where('script_id', $script->id)->where('status', 'pending')->whereIn('device_id', [$recovered->id, $failed->id, $errored->id, $unsigned->id])->pluck('device_id')->all());
        // The whole script, not only the detection of a script with manual remediation.
        $this->assertSame([null], ScriptRun::query()->where('script_id', $script->id)->where('status', 'pending')->whereIn('device_id', [$failed->id, $errored->id])->pluck('mode')->unique()->values()->all());

        $this->actingAs(User::factory()->create());
        $this->get('/scripts/'.$script->id)->assertForbidden();
    }

    public function test_findings_and_the_inventory_are_exported(): void
    {
        $this->actingAs($this->admin());
        \App\Models\SecurityRule::syncBuiltIn();
        $rule = \App\Models\SecurityRule::query()->detection()->first();
        $device = $this->device();
        \App\Models\SecurityFinding::query()->create([
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => 'export', 'severity' => 'high',
            'message' => "Firewall\nis off", 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        // The report is a plain download (not an answer of Livewire): of a device, or of the filters of the page.
        $response = $this->get(route('security.export', ['device' => $device->id, 'status' => 'unresolved']))->assertOk()->assertDownload();
        $this->assertStringEndsWith('.md', $response->headers->get('Content-Disposition'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('## [High] ', $content);
        $this->assertStringContainsString('Firewall is off', $content);
        $this->assertStringContainsString('1 finding(s)', $this->get(route('security.export'))->streamedContent());
        $this->assertStringContainsString('0 finding(s)', $this->get(route('security.export', ['severity' => 'critical']))->streamedContent());
        $this->assertStringContainsString('1 finding(s)', $this->get(route('security.export', ['search' => 'Firewall']))->streamedContent());
        $this->assertStringContainsString('0 finding(s)', $this->get(route('security.export', ['search' => 'nothing like it']))->streamedContent());
        // What somebody acknowledged is quiet: it is not in the counts per severity.
        \App\Models\SecurityFinding::query()->create([
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => 'export-ack', 'severity' => 'low',
            'message' => 'No firewall', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'acknowledged_at' => now(),
        ]);
        $content = $this->get(route('security.export'))->streamedContent();
        $this->assertStringContainsString('2 finding(s), 1 of them acknowledged.', $content);
        $this->assertStringContainsString('Not acknowledged: High: 1', $content);
        $this->assertStringNotContainsString('Low: 1', $content);
        $this->get(route('security.export', ['status' => 'bogus']))->assertSessionHasErrors('status');
        $this->get(route('security.export', ['device' => 999999]))->assertSessionHasErrors('device');
        Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->assertSee('Export Markdown')->assertSee(route('security.export', ['device' => $device->id, 'status' => 'unresolved']));
        \Illuminate\Support\Facades\Auth::logout();
        $this->get(route('security.export'))->assertRedirect();
        $this->actingAs($this->admin());

        $json = Livewire::test(\App\Livewire\DeviceSecurity::class, ['deviceId' => $device->id])->call('exportInventory')->effects['download'] ?? null;
        $this->assertNotNull($json);
        $data = json_decode(base64_decode($json['content']), true);
        $this->assertSame("Firewall\nis off", $data['findings'][0]['message']);
        $this->assertArrayHasKey('inventory', $data);
    }
}
