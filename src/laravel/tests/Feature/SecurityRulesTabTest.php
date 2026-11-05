<?php

namespace Tests\Feature;

use App\Livewire\SecurityScan\Page;
use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The checks tab counts the devices (and scanned addresses) a rule has open findings on, in SQL every database takes. */
class SecurityRulesTabTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $name);
        $device->name = $name;
        $device->save();

        return $device;
    }

    private function finding(SecurityRule $rule, array $target, string $fingerprint): void
    {
        SecurityFinding::query()->create($target + [
            'security_rule_id' => $rule->id, 'fingerprint' => $fingerprint, 'severity' => 'high', 'message' => 'x',
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_the_open_findings_of_a_rule_are_counted_per_device_and_address(): void
    {
        $this->actingAs(User::factory()->create());
        SecurityRule::syncBuiltIn();
        $rule = SecurityRule::query()->detection()->first();
        $a = $this->device('a');
        $b = $this->device('b');
        // Two findings on one device count once, an address of a network scan counts as one target.
        $this->finding($rule, ['device_id' => $a->id], '1');
        $this->finding($rule, ['device_id' => $a->id], '2');
        $this->finding($rule, ['device_id' => $b->id], '3');
        $this->finding($rule, ['target_ip' => '192.168.1.9'], '4');
        $this->finding($rule, ['target_ip' => '192.168.1.9'], '5');

        Livewire::test(Page::class)->set('tab', 'rules')
            ->assertViewHas('rules', fn ($rules) => (int) $rules->firstWhere('id', $rule->id)->open_count === 3);
    }
}
