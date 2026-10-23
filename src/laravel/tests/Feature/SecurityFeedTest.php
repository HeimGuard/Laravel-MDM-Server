<?php

namespace Tests\Feature;

use App\Jobs\SyncSecurityFeed;
use App\Livewire\SecurityScan\Page;
use App\Models\SecurityRule;
use App\Models\User;
use App\Support\SecurityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** The rules of a public git repository: fetched, checked against the manifest, applied in one go. */
class SecurityFeedTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = 'https://raw.githubusercontent.com/example/security-rules/main';

    private function rule(string $key, array $overrides = []): array
    {
        return $overrides + [
            'key' => $key, 'name' => ucfirst($key), 'severity' => 'medium', 'platform' => 'any', 'source' => 'software',
            'when' => ['field' => 'Name', 'op' => 'contains', 'value' => $key],
        ];
    }

    /** Fakes a repository: files path => content, the manifest with their hashes (or the given ones). */
    private function repository(string $version, array $files, array $hashes = []): void
    {
        $manifest = ['version' => $version, 'files' => []];
        $responses = [];
        foreach ($files as $path => $content) {
            $body = is_string($content) ? $content : json_encode($content);
            $manifest['files'][$path] = $hashes[$path] ?? hash('sha256', $body);
            $responses[self::RAW.'/'.$path] = Http::response($body);
        }
        $responses[self::RAW.'/manifest.json'] = Http::response(json_encode($manifest));
        $this->fake($responses);
    }

    /** A fresh set of responses (fakes of the facade add up, the first match wins). */
    private function fake(array $responses): void
    {
        Http::swap(new Factory);
        Http::fake($responses);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mdm.security_feed_url' => 'https://github.com/example/security-rules', 'mdm.security_feed_ref' => 'main']);
        SecurityRule::syncBuiltIn();
    }

    public function test_the_feed_is_off_until_a_repository_is_set(): void
    {
        config(['mdm.security_feed_url' => null]);
        $this->assertFalse(SecurityFeed::enabled());
        config(['mdm.security_feed_url' => 'http://insecure.example/rules']);
        $this->assertFalse(SecurityFeed::enabled());
        config(['mdm.security_feed_url' => 'https://github.com/example/security-rules.git']);
        $this->assertSame(self::RAW, SecurityFeed::rawBase());
        config(['mdm.security_feed_url' => 'https://git.example.com/raw/rules/']);
        $this->assertSame('https://git.example.com/raw/rules', SecurityFeed::rawBase());
    }

    public function test_rules_and_parsers_of_the_repository_are_added_updated_and_removed(): void
    {
        $this->repository('2026.10.1', [
            'rules/apps/games.json' => [$this->rule('games'), $this->rule('chat')],
            'rules/one.json' => $this->rule('single', ['enabled' => false]),
            'parsers/linux/su.json' => ['key' => 'feed.su', 'name' => 'su', 'source' => 'linux.auth', 'when' => ['field' => 'Identifier', 'op' => 'eq', 'value' => 'su'], 'pattern' => '^FAILED SU (?<user>\S+)', 'event' => ['type' => 'su_failed', 'user' => '{user}']],
        ]);
        $state = SecurityFeed::sync();

        $this->assertTrue($state['ok']);
        $this->assertSame('2026.10.1', $state['version']);
        $this->assertSame(4, $state['added']);
        $games = SecurityRule::query()->where('key', 'games')->first();
        $this->assertSame(SecurityRule::FEED, $games->origin);
        $this->assertSame('2026.10.1', $games->feed_version);
        $this->assertTrue($games->builtIn);
        $this->assertFalse(SecurityRule::query()->where('key', 'single')->value('enabled'));
        $this->assertSame('parser', SecurityRule::query()->where('key', 'feed.su')->value('kind'));

        // The same version again: nothing is fetched twice.
        $again = SecurityFeed::sync();
        $this->assertSame('2026.10.1', $again['version']);
        // The manifest and its three files the first time, the manifest alone now.
        Http::assertSentCount(5);

        // A new version: changed rules are updated, the switch an admin set stays, missing ones go.
        SecurityRule::query()->where('key', 'games')->update(['enabled' => false]);
        $this->repository('2026.10.2', ['rules/apps/games.json' => [$this->rule('games', ['severity' => 'high'])]]);
        $state = SecurityFeed::sync();
        $this->assertSame(['added' => 0, 'updated' => 1, 'removed' => 3], array_intersect_key($state, ['added' => 0, 'updated' => 0, 'removed' => 0]));
        $games = SecurityRule::query()->where('key', 'games')->first();
        $this->assertSame('high', $games->severity);
        $this->assertFalse($games->enabled);
        $this->assertFalse(SecurityRule::query()->where('key', 'chat')->exists());
    }

    public function test_nothing_changes_when_the_repository_is_not_what_the_manifest_says(): void
    {
        $this->repository('1', ['rules/a.json' => $this->rule('alpha')]);
        SecurityFeed::sync();
        $before = SecurityRule::query()->count();

        // A file that does not match its hash (a changed file, a swapped one).
        $this->repository('2', ['rules/a.json' => $this->rule('beta'), 'rules/b.json' => $this->rule('gamma')], ['rules/b.json' => str_repeat('0', 64)]);
        $state = SecurityFeed::sync();
        $this->assertFalse($state['ok']);
        $this->assertStringContainsString('rules/b.json does not match the manifest', $state['error']);
        $this->assertSame('1', $state['version']);
        $this->assertSame($before, SecurityRule::query()->count());
        $this->assertTrue(SecurityRule::query()->where('key', 'alpha')->exists());

        // A repository that is down, a manifest that is not one, a path that leaves the folders.
        $this->fake([self::RAW.'/manifest.json' => Http::response('nope', 500)]);
        $this->assertFalse(SecurityFeed::sync()['ok']);
        $this->fake([self::RAW.'/manifest.json' => Http::response('{"version": "3"}')]);
        $this->assertFalse(SecurityFeed::sync()['ok']);
        $this->fake([self::RAW.'/manifest.json' => Http::response(json_encode(['version' => '3', 'files' => ['rules/../../.env' => str_repeat('a', 64)]]))]);
        $state = SecurityFeed::sync();
        $this->assertStringContainsString('unusable file', $state['error']);
        $this->assertSame($before, SecurityRule::query()->count());
    }

    public function test_a_big_feed_is_fetched_in_chunks_and_applied_in_one_go(): void
    {
        $files = [];
        foreach (range(1, 250) as $i) {
            $files["rules/any/r$i.json"] = $this->rule("big$i");
        }
        $this->repository('1', $files);
        $state = SecurityFeed::sync();
        $this->assertTrue($state['ok']);
        $this->assertSame(250, $state['added']);

        // One file of a later chunk is wrong: nothing of the new version is applied.
        $this->repository('2', $files + ['rules/any/r251.json' => $this->rule('big251')], ['rules/any/r251.json' => str_repeat('0', 64)]);
        $state = SecurityFeed::sync();
        $this->assertFalse($state['ok']);
        $this->assertStringContainsString('r251.json does not match the manifest', $state['error']);
        $this->assertSame('1', $state['version']);
        $this->assertFalse(SecurityRule::query()->where('key', 'big251')->exists());
    }

    public function test_invalid_rules_are_skipped_and_the_portals_own_rules_win(): void
    {
        SecurityRule::query()->create(SecurityRule::attributesFor($this->rule('mine', ['severity' => 'low'])) + ['origin' => SecurityRule::CUSTOM]);
        $bundled = SecurityRule::query()->where('origin', SecurityRule::BUNDLED)->value('key');
        $this->repository('1', [
            'rules/x.json' => [$this->rule('mine', ['severity' => 'critical']), $this->rule('Not A Key'), $this->rule('fine'), $this->rule($bundled, ['name' => 'From the feed'])],
        ]);
        $state = SecurityFeed::sync();

        $this->assertTrue($state['ok']);
        $this->assertCount(1, $state['skipped']);
        // A rule of the portal's own keeps its definition, the feed is newer than the bundled rules.
        $this->assertSame('low', SecurityRule::query()->where('key', 'mine')->value('severity'));
        $this->assertSame(SecurityRule::CUSTOM, SecurityRule::query()->where('key', 'mine')->value('origin'));
        $this->assertSame('From the feed', SecurityRule::query()->where('key', $bundled)->value('name'));
        $this->assertSame(SecurityRule::FEED, SecurityRule::query()->where('key', $bundled)->value('origin'));

        // The feed drops that rule: the bundled one comes back.
        $this->repository('2', ['rules/x.json' => [$this->rule('fine')]]);
        SecurityFeed::sync();
        $this->assertSame(SecurityRule::BUNDLED, SecurityRule::query()->where('key', $bundled)->value('origin'));
        $this->assertNotSame('From the feed', SecurityRule::query()->where('key', $bundled)->value('name'));
        // The bundled sync never takes a rule of the feed or of the portal's own back.
        SecurityRule::syncBuiltIn();
        $this->assertSame('fine', SecurityRule::query()->where('origin', SecurityRule::FEED)->value('key'));
    }

    public function test_a_system_admin_updates_the_feed_with_a_job(): void
    {
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $this->actingAs($admin);
        Queue::fake();
        Livewire::test(Page::class, ['tab' => 'rules'])->call('updateFeed');
        Queue::assertPushedOn('security', SyncSecurityFeed::class, fn ($job) => $job->force === true);

        // Without a feed there is nothing to update; users cannot.
        config(['mdm.security_feed_url' => null]);
        Queue::fake();
        Livewire::test(Page::class, ['tab' => 'rules'])->call('updateFeed');
        Queue::assertNothingPushed();
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class, ['tab' => 'rules'])->call('updateFeed')->assertForbidden();
    }
}
