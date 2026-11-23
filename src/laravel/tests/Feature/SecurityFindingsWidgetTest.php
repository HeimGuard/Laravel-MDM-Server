<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\View\Components\Widgets\SecurityFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SecurityFindingsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function finding(Device $device, SecurityRule $rule, string $severity, string $message, array $attributes = []): SecurityFinding
    {
        return SecurityFinding::query()->create([
            'device_id' => $device->id,
            'security_rule_id' => $rule->id,
            'fingerprint' => uniqid('', true),
            'severity' => $severity,
            'message' => $message,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ] + $attributes);
    }

    public function test_widget_counts_open_findings_and_lists_the_severe_ones(): void
    {
        SecurityRule::syncBuiltIn();
        $rule = SecurityRule::query()->detection()->firstOrFail();
        $device = new Device;
        $device->token = hash('sha256', uniqid('', true));
        $device->save();

        $this->finding($device, $rule, 'critical', 'Mimikatz is installed');
        $this->finding($device, $rule, 'high', 'AnyDesk is installed');
        $this->finding($device, $rule, 'low', 'Wireshark is installed');
        // Not counted: acknowledged and resolved.
        $this->finding($device, $rule, 'critical', 'Quiet finding', ['acknowledged_at' => now()]);
        $this->finding($device, $rule, 'critical', 'Gone finding', ['resolved_at' => now()]);

        $html = Blade::render('<x-widgets.SecurityFindings :config="$config" />', ['config' => ['list' => '5']]);

        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<small[^>]*>Critical/', $html);
        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<small[^>]*>High/', $html);
        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<small[^>]*>Low/', $html);
        $this->assertStringContainsString('Mimikatz is installed', $html);
        $this->assertStringContainsString('AnyDesk is installed', $html);
        // Below the default minimum severity of the list (medium).
        $this->assertStringNotContainsString('Wireshark is installed', $html);
        $this->assertStringNotContainsString('Quiet finding', $html);
        $this->assertStringNotContainsString('Gone finding', $html);
        $this->assertStringContainsString('selectedDeviceId='.$device->id, $html);
        $this->assertStringContainsString(route('security'), $html);
    }

    public function test_widget_without_findings_says_so(): void
    {
        $html = Blade::render('<x-widgets.SecurityFindings :config="$config" />', ['config' => []]);

        $this->assertStringContainsString('No open security findings.', $html);
    }

    public function test_widget_config_keeps_only_known_keys_and_a_known_severity(): void
    {
        $widget = new SecurityFindings(['name' => 'Alerts', 'bogus' => 'x', 'severity' => 'nope']);

        $this->assertSame('Alerts', $widget->config['name']);
        $this->assertArrayNotHasKey('bogus', $widget->config);
    }
}
