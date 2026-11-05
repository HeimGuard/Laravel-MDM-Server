<?php

namespace App\Livewire;

use App\Models\Device;
use App\Support\PingUsage;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The ping of a ping-only device on its detail: the response time and the uptime over the chosen
 * range (the bar of the last checks is <x-ping-status>).
 */
class PingMonitor extends Component
{
    public int $deviceId;

    /** Chart range, kept in the URL (?range=30d) as for the devices with the agent (DeviceMetrics). */
    #[Url(except: '1h')]
    public string $range = '1h';

    public const RANGES = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720];

    public function mount(): void
    {
        // A value from the URL that is not a known range.
        if (! isset(self::RANGES[$this->range])) {
            $this->range = '1h';
        }
    }

    /** Checks in the bar of <x-ping-status> (every 30 s: the last 25 minutes). */
    public const BEATS = 50;

    /** Points of the response time chart. */
    private const BUCKETS = 48;

    public function setRange(string $range): void
    {
        if (isset(self::RANGES[$range])) {
            $this->range = $range;
        }
    }

    #[On('ping-settings-saved')]
    public function refresh(): void {}

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{deviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::findOrFail($this->deviceId);
        $hours = self::RANGES[$this->range];
        $since = now()->subHours($hours);

        // Response time: the average of each bucket, and whether a ping went unanswered in it.
        $bucketSeconds = $hours * 3600 / self::BUCKETS;
        $buckets = array_fill(0, self::BUCKETS, ['sum' => 0.0, 'count' => 0, 'down' => false]);
        $index = fn (\Carbon\CarbonInterface $at) => min(self::BUCKETS - 1, (int) floor(($at->getTimestamp() - $since->getTimestamp()) / $bucketSeconds));
        foreach (PingUsage::checks($device->id, $since) as $check) {
            if (! $check->up && ($i = $index($check->at)) >= 0) {
                $buckets[$i]['down'] = true;
            }
        }
        foreach (PingUsage::responses($device->id, $since) as $response) {
            if (($i = $index($response->at)) >= 0) {
                $buckets[$i]['sum'] += $response->rtt;
                $buckets[$i]['count']++;
            }
        }
        $points = array_map(fn ($bucket) => $bucket['count'] > 0 ? round($bucket['sum'] / $bucket['count'], 1) : null, $buckets);

        $format = $hours <= 24 ? 'H:i' : 'j. n. H:i';
        $labels = array_map(fn ($i) => $since->copy()->addSeconds((int) (($i + 1) * $bucketSeconds))->format($format), range(0, self::BUCKETS - 1));

        return view('livewire.ping-monitor', [
            'labels' => $labels,
            'device' => $device,
            'relay' => $device->pingRelay(),
            'portScan' => $device->portScanState(),
            'points' => $points,
            'downs' => array_map(fn ($bucket) => $bucket['down'], $buckets),
            'max' => max(1, (float) max(array_filter($points, fn ($point) => $point !== null) ?: [1])),
            'from' => $since,
            'stats' => [
                'current' => $device->offline ? null : $device->ping_rtt,
                'average' => PingUsage::average($device->id, $since),
                'uptime' => PingUsage::uptime($device->id, $since),
            ],
        ]);
    }
}
