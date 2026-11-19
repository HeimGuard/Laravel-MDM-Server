<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Models\User;
use App\Services\DetectionFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The export of what the detection did (DetectionFeedbackService): numbers per rule, no names or addresses. */
class DetectionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function finding(Device $device, SecurityRule $rule, string $message, array $state = []): SecurityFinding
    {
        return SecurityFinding::query()->create($state + [
            'device_id' => $device->id, 'security_rule_id' => $rule->id, 'fingerprint' => md5($message.$device->id), 'severity' => 'high',
            'message' => $message, 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function device(): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'feedback');
        $device->name = 'payroll-srv';
        $device->data = json_encode(['machine' => ['Hostname' => 'payroll-srv', 'Platform' => 'linux', 'Drives' => []]]);
        $device->save();

        return $device->fresh();
    }

    public function test_the_export_counts_per_rule_and_leaves_out_names_and_addresses(): void
    {
        SecurityRule::syncBuiltIn();
        $rule = SecurityRule::query()->detection()->first();
        $device = $this->device();
        $this->finding($device, $rule, 'payroll-srv listens on 10.1.2.3 for bob@example.com in /home/bob/x');
        $this->finding($device, $rule, 'second', ['acknowledged_at' => now()]);

        $data = DetectionFeedbackService::build();
        $row = collect($data['rules'])->firstWhere('key', $rule->key);

        $this->assertSame(1, $row['findings']['open']);
        $this->assertSame(1, $row['findings']['acknowledged']);
        $this->assertSame(0.5, $row['acknowledged_share']);
        $this->assertSame(1, $row['devices']);
        $json = json_encode($data);
        foreach (['payroll-srv', '10.1.2.3', 'bob@example.com', '/home/bob'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringContainsString('DEVICE listens on IP for EMAIL in /home/USER/x', $json);
    }

    public function test_a_finding_that_names_another_architecture_is_found(): void
    {
        $this->assertSame('arm64', DetectionFeedbackService::foreignArch('Linux arm64 kernel is outdated', 'amd64'));
        $this->assertSame('amd64', DetectionFeedbackService::foreignArch('package foo:x86_64 is old', 'arm64'));
        $this->assertNull(DetectionFeedbackService::foreignArch('package foo:x86_64 is old', 'amd64'));
        $this->assertNull(DetectionFeedbackService::foreignArch('Nothing about CPUs', 'amd64'));
        $this->assertNull(DetectionFeedbackService::foreignArch('arm64', null));
    }

    public function test_it_is_a_download_for_signed_in_users_only(): void
    {
        $this->get(route('security.feedback'))->assertRedirect();

        $this->actingAs(User::factory()->create());
        $response = $this->get(route('security.feedback'))->assertOk()->assertDownload();
        $this->assertStringEndsWith('.json', $response->headers->get('Content-Disposition'));
        $this->assertSame(DetectionFeedbackService::FORMAT, json_decode($response->streamedContent(), true)['format']);
    }
}
