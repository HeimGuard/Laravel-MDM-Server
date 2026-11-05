<?php

namespace App\Livewire;

use App\Models\ValueSample;
use App\Support\DeviceUsage;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\Component;

class DeviceMetrics extends Component
{
    /** Range => [length in seconds, number of chart points]. */
    public const RANGES = [
        '1h' => [3600, 60],
        '24h' => [86400, 96],
        '7d' => [604800, 84],
        '30d' => [2592000, 90],
    ];

    public $selectedDeviceId;

    /** Chart range, kept in the URL. */
    #[Url(except: '1h')]
    public string $range = '1h';

    public function mount(): void
    {
        // A value from the URL that is not a known range.
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '1h';
        }
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
        }
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{selectedDeviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        [$length, $points] = self::RANGES[$this->range];
        $bucket = intdiv($length, $points);
        $end = CarbonImmutable::now();
        $start = $end->subSeconds($length);

        $sums = [];
        $definitions = DeviceUsage::definitions((int) $this->selectedDeviceId);
        if (isset($definitions['cpu'], $definitions['memory.used'], $definitions['memory.total'])) {
            $ids = ['cpu' => $definitions['cpu']->id, 'used' => $definitions['memory.used']->id, 'total' => $definitions['memory.total']->id];
            $query = ValueSample::query()->whereIn('definition_id', array_values($ids));
            if ($length > 86400) {
                // Up to 30 days of samples every 30 s: averaged by the database, one interval at a time
                // (portable, each one an index range).
                for ($i = 0; $i < $points; $i++) {
                    $average = (clone $query)->where('recorded_at', '>=', $start->addSeconds($i * $bucket))->where('recorded_at', '<', $start->addSeconds(($i + 1) * $bucket))
                        ->toBase()->selectRaw('definition_id, avg(value) as average, count(*) as samples')->groupBy('definition_id')->get()->keyBy('definition_id');
                    if (isset($average[$ids['cpu']]) && $average[$ids['cpu']]->samples > 0) {
                        $total = (float) ($average[$ids['total']]->average ?? 0);
                        $sums[$i] = ['cpu' => [(float) $average[$ids['cpu']]->average], 'memory' => [$total > 0 ? 100.0 * (float) ($average[$ids['used']]->average ?? 0) / $total : 0]];
                    }
                }
            } else {
                // The three values of a heartbeat have one time.
                $moments = [];
                (clone $query)->where('recorded_at', '>=', $start)->orderBy('recorded_at')->toBase()->get(['definition_id', 'value', 'recorded_at'])
                    ->each(function ($row) use (&$moments, $ids) {
                        $moments[$row->recorded_at][array_search($row->definition_id, $ids, true)] = (float) $row->value;
                    });
                foreach ($moments as $time => $moment) {
                    $index = min($points - 1, intdiv(CarbonImmutable::parse($time)->getTimestamp() - $start->getTimestamp(), $bucket));
                    $sums[$index]['cpu'][] = $moment['cpu'] ?? 0;
                    $sums[$index]['memory'][] = ($moment['total'] ?? 0) > 0 ? ($moment['used'] ?? 0) / $moment['total'] * 100 : 0;
                }
            }
        }

        $format = $length >= 86400 ? 'd.m. H:i' : 'H:i';
        $cpu = $memory = $labels = [];
        for ($i = 0; $i < $points; $i++) {
            $cpu[] = isset($sums[$i]) ? round(array_sum($sums[$i]['cpu']) / count($sums[$i]['cpu']), 1) : null;
            $memory[] = isset($sums[$i]) ? round(array_sum($sums[$i]['memory']) / count($sums[$i]['memory']), 1) : null;
            $labels[] = $start->addSeconds(($i + 1) * $bucket)->format($format);
        }

        $latest = DeviceUsage::latest((int) $this->selectedDeviceId);

        return view('livewire.device-metrics', [
            'cpu' => $cpu,
            'memory' => $memory,
            'labels' => $labels,
            'latest' => $latest,
            'from' => $start->format($format),
            'to' => $end->format($format),
        ]);
    }
}
