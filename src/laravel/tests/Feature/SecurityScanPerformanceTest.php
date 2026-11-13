<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityRule;
use App\Support\SecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\SimulatesSecurityAgent;
use Tests\TestCase;

/** The incremental scan: it finds what a full scan finds and does not do work it does not need to. */
#[Group('slow')]
class SecurityScanPerformanceTest extends TestCase
{
    use RefreshDatabase, SimulatesSecurityAgent;

    private const RULES = 1000;

    private const PACKAGES = 2000;

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'bench');
        $device->name = 'bench';
        $device->data = json_encode(['machine' => ['Hostname' => 'bench', 'AgentVersion' => '1.17.0', 'Platform' => 'windows']]);
        $device->save();

        return $device;
    }

    /** @return array<int, array> */
    private function packages(): array
    {
        $items = [];
        for ($i = 0; $i < self::PACKAGES; $i++) {
            $items[] = ['Name' => "package-$i", 'Version' => '1.'.($i % 9), 'Publisher' => 'Vendor '.($i % 40), 'Source' => 'msi'];
        }

        return $items;
    }

    private function rules(): void
    {
        SecurityRule::syncBuiltIn();
        $definitions = [];
        for ($i = 0; $i < self::RULES; $i++) {
            $definitions[] = ['key' => "bench.rule-$i", 'name' => "Bench $i", 'severity' => 'low', 'source' => 'software',
                'when' => ['field' => 'Name', 'op' => 'contains', 'value' => $i % 50 === 0 ? 'package-'.($i * 2).'x' : "nothing-$i"]];
        }
        // One that really matches: package-7 and everything that starts the same.
        $definitions[] = ['key' => 'bench.seven', 'name' => 'Seven {Name}', 'severity' => 'medium', 'source' => 'software',
            'when' => ['field' => 'Name', 'op' => 'eq', 'value' => 'package-7']];
        SecurityRule::apply($definitions, SecurityRule::CUSTOM);
    }

    /** One collection of the agent: the first sends everything, the next only what changed. */
    private function collect(Device $device, array $packages): array
    {
        $sources = SecurityScanner::normalizeSources($this->agentSources('bench', ['software' => $packages])['sources']);
        $start = microtime(true);
        $counts = SecurityScanner::ingest($device, $sources);
        // The agent takes the state over once acknowledged.
        $this->ack('bench', ['software' => $packages]);

        return [$counts, (microtime(true) - $start) * 1000];
    }

    private function ack(string $token, array $inventory): void
    {
        $result = $this->agentSources($token, $inventory);
        foreach ($result['pending'] as $source => $state) {
            $this->setAgentState($token, $source, $state);
        }
    }

    private function setAgentState(string $token, string $source, array $state): void
    {
        $property = new \ReflectionProperty($this, 'agentStates');
        $states = $property->getValue($this);
        $states[$token][$source] = $state;
        $property->setValue($this, $states);
    }

    private function fingerprints(): array
    {
        return SecurityFinding::query()->open()->orderBy('fingerprint')->pluck('fingerprint')->all();
    }

    public function test_changed_items_find_what_a_full_scan_finds(): void
    {
        $device = $this->device();
        $this->rules();
        $packages = $this->packages();

        [$counts] = $this->collect($device, $packages);
        $this->assertSame(1, $counts['opened']);

        // package-7 is replaced by another version (still found), package-9 is removed and a new one comes.
        $packages[7]['Version'] = '9.9';
        $packages[] = ['Name' => 'package-7', 'Version' => '2', 'Publisher' => 'x', 'Source' => 'msi'];
        [$counts] = $this->collect($device, $packages);
        $this->assertSame(0, $counts['resolved']);
        $incremental = $this->fingerprints();

        SecurityInventory::query()->update(['scanned' => null]);
        SecurityScanner::scan($device);
        $this->assertSame($incremental, $this->fingerprints());

        // The one that matched goes away: its finding is resolved by the incremental scan.
        $packages = array_values(array_filter($packages, fn ($item) => $item['Name'] !== 'package-7'));
        [$counts] = $this->collect($device, $packages);
        $this->assertSame(1, $counts['resolved']);
        $this->assertSame([], $this->fingerprints());
    }

    public function test_the_scan_skips_what_has_not_changed_and_stays_fast(): void
    {
        $device = $this->device();
        $this->rules();
        $packages = $this->packages();
        [, $first] = $this->collect($device, $packages);

        // Nothing changed: no rule is evaluated.
        $start = microtime(true);
        $counts = SecurityScanner::scan($device);
        $unchanged = (microtime(true) - $start) * 1000;
        $this->assertSame(['opened' => 0, 'resolved' => 0], $counts);

        $packages[100]['Version'] = '5.5';
        [, $typical] = $this->collect($device, $packages);

        fwrite(STDERR, sprintf("\nscan of %d rules x %d items: first %.1f ms, unchanged %.1f ms, one change %.1f ms\n", self::RULES, self::PACKAGES, $first, $unchanged, $typical));

        // Generous limits: they catch a scan that went back to looking at everything, not a slow machine.
        $this->assertLessThan(25, $unchanged);
        $this->assertLessThan(250, $typical);
    }

    public function test_changing_a_rule_scans_only_that_rule(): void
    {
        $device = $this->device();
        $this->rules();
        $this->collect($device, $this->packages());
        $this->assertCount(1, $this->fingerprints());

        SecurityRule::query()->where('key', 'bench.seven')->first()->update(['enabled' => false]);
        $counts = SecurityScanner::scan($device);
        $this->assertSame(['opened' => 0, 'resolved' => 1], $counts);
        $this->assertSame([], $this->fingerprints());

        SecurityRule::query()->where('key', 'bench.seven')->first()->update(['enabled' => true]);
        $this->assertSame(['opened' => 1, 'resolved' => 0], SecurityScanner::scan($device));
    }
}
