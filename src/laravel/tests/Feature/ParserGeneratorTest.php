<?php

namespace Tests\Feature;

use App\Livewire\SecurityScan\RuleForm;
use App\Models\Device;
use App\Models\User;
use App\Services\ParserGeneratorService;
use App\Services\SyslogUnparsedService;
use App\Support\Syslog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** The parser of a syslog message nothing reads, written by the AI of the server (Add parser). */
class ParserGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'User [JonatanRek] from [192.168.1.103] signed in to [DSM] successfully via [password].';

    private function record(): array
    {
        return ['Time' => '2026-11-23T10:00:00+00:00', 'Host' => 'secret-host-42', 'Facility' => 'auth', 'Severity' => 'info', 'App' => 'Connection', 'Pid' => '', 'Message' => self::MESSAGE];
    }

    /** What the AI answers: a parser as the structured output of the schema. */
    private function answer(string $pattern): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode([
            'name' => 'Synology: sign-in', 'description' => 'A successful sign-in.', 'app' => 'Connection', 'pattern' => $pattern,
            'event' => ['type' => 'logon', 'user' => '{user}', 'source' => '{ip}', 'message' => '{user} signed in to {service} via {method}'],
        ])]], 'stop_reason' => 'end_turn'];
    }

    private const PATTERN = '^User \[(?<user>[^\]]+)\] from \[(?<ip>[^\]]+)\] signed in to \[(?<service>[^\]]+)\] successfully via \[(?<method>[^\]]+)\]';

    /** The AI answers with these, one after another. */
    private function ai(array ...$responses): void
    {
        config(['mdm.ai_provider' => 'anthropic', 'mdm.ai_api_key' => 'test-key', 'mdm.ai_url' => null, 'mdm.ai_model' => null]);
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }
        Http::fake(['api.anthropic.com/*' => $sequence]);
    }

    public function test_the_ai_writes_a_parser_that_reads_the_message_without_the_name_of_the_host(): void
    {
        $this->ai($this->answer(self::PATTERN));

        $parser = ParserGeneratorService::generate($this->record(), 'custom.syslog-connection-1');

        $this->assertSame(['syslog', 'Connection', 'logon'], [$parser['source'], $parser['when']['value'], $parser['event']['type']]);
        $event = \App\Support\SecurityParsers::apply($parser, $this->record());
        $this->assertSame(['JonatanRek', '192.168.1.103', 'JonatanRek signed in to DSM via password'], [$event['User'], $event['Source'], $event['Message']]);
        // The text of the message goes to the AI, not the name of the host.
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'JonatanRek') && ! str_contains($request->body(), 'secret-host-42'));
    }

    public function test_a_parser_that_does_not_read_the_message_is_refused(): void
    {
        $this->ai($this->answer('^Something else entirely (?<x>\d+)'));

        $this->expectException(RuntimeException::class);
        ParserGeneratorService::generate($this->record(), 'custom.syslog-connection-1');
    }

    public function test_the_form_of_a_syslog_message_asks_the_ai_and_keeps_the_template_when_it_cannot(): void
    {
        // A system admin by its id: on PostgreSQL the first user is not id 1 (the sequence is not reset between tests).
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $this->actingAs($admin);
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'nas', 'os' => '', 'token' => hash('sha256', 'nas'), 'ping_address' => '192.168.1.20', 'ping_prefix' => 24])->save();
        Syslog::ingest([['ip' => '192.168.1.20', 'time' => '2026-11-23T10:00:00+00:00', 'host' => 'nas', 'facility' => 'auth', 'severity' => 'info', 'app' => 'Connection', 'pid' => '-', 'msg' => ' '.self::MESSAGE]]);
        $key = SyslogUnparsedService::events($device->id)->firstOrFail()->cache_key;

        // No AI (no key): the parser of the whole message, nothing is asked.
        $this->ai($this->answer(self::PATTERN), $this->answer('^no match (?<x>\d+)'));
        config(['mdm.ai_api_key' => null]);
        $form = Livewire::test(RuleForm::class, ['kind' => 'parser', 'eventKey' => $key])->assertSet('aiPending', false);
        $this->assertStringContainsString('"pattern": "^User', $form->get('json'));
        Http::assertNothingSent();

        // With the AI: it is asked when the form is shown, and the parser replaces the pattern of the whole message.
        config(['mdm.ai_api_key' => 'test-key']);
        $form = Livewire::test(RuleForm::class, ['kind' => 'parser', 'eventKey' => $key])->assertSet('aiPending', true)
            ->call('generateParser')->assertSet('aiPending', false)->assertSet('aiError', '');
        $this->assertStringContainsString('(?<user>', $form->get('json'));

        // It cannot (a parser that reads nothing): the form keeps the template and says why.
        $form = Livewire::test(RuleForm::class, ['kind' => 'parser', 'eventKey' => $key])->call('generateParser');
        $this->assertStringContainsString('did not return a parser', $form->get('aiError'));
        $this->assertStringContainsString('"pattern": "^User', $form->get('json'));
    }
}
