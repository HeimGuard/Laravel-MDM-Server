<?php

namespace App\Support;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Exports of the scanner for people and for an AI: the findings as Markdown, the security inventory of a device as JSON. */
class SecurityExport
{
    /** Findings are read and written this many at a time, a report of thousands never sits in memory. */
    public const CHUNK = 200;

    /**
     * The findings as a Markdown report, most severe first.
     *
     * @param  Collection<int, SecurityFinding>  $findings  with rule, device and acknowledger loaded
     */
    public static function markdown(Collection $findings, string $title = 'Security findings'): string
    {
        return self::header($title, $findings->count(), $findings->countBy('severity')->all()).$findings->map(fn ($finding) => self::block($finding))->implode('');
    }

    /**
     * Writes the report of the query to the output, CHUNK findings at a time (the query has to be ordered
     * the same way on every page, e.g. bySeverity()).
     *
     * @param  Builder<SecurityFinding>  $query
     */
    public static function streamMarkdown(Builder $query, string $title = 'Security findings'): void
    {
        $counts = (clone $query)->reorder()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity')->all();
        echo self::header($title, array_sum($counts), $counts);
        $query->with(['rule', 'device', 'acknowledger'])->chunk(self::CHUNK, function ($findings) {
            echo $findings->map(fn ($finding) => self::block($finding))->implode('');
            flush();
        });
    }

    /** @param  array<string, int>  $counts  findings per severity */
    private static function header(string $title, int $total, array $counts): string
    {
        $out = ["# {$title}", '', 'Exported '.now()->toDateTimeString().", {$total} finding(s).", ''];
        $summary = collect(array_keys(SecurityRules::SEVERITIES))->filter(fn ($level) => isset($counts[$level]))
            ->map(fn ($level) => ucfirst($level).': '.$counts[$level])->implode(' · ');
        if ($summary !== '') {
            array_push($out, $summary, '');
        }

        return implode("\n", $out)."\n";
    }

    private static function block(SecurityFinding $finding): string
    {
        $rule = $finding->rule;
        $out = ['## ['.ucfirst($finding->severity).'] '.self::line($rule?->name ?? $finding->message), ''];
        $out[] = '- **Target:** '.self::line($finding->device?->displayName ?? $finding->targetLabel);
        $out[] = '- **Status:** '.$finding->status;
        $out[] = '- **Finding:** '.self::line($finding->message);
        $out[] = '- **First seen:** '.$finding->first_seen_at?->toDateTimeString().', last seen '.$finding->last_seen_at?->toDateTimeString().($finding->occurrences > 1 ? ", {$finding->occurrences} times" : '');
        if ($finding->acknowledged_at) {
            $out[] = '- **Acknowledged:** '.$finding->acknowledged_at->toDateTimeString().' by '.self::line($finding->acknowledger?->name ?? '?');
        }
        if ($hint = $rule?->definition['remediation'] ?? null) {
            $out[] = '- **Remediation:** '.self::line($hint);
        }
        foreach ($rule?->urls ?? [] as $link) {
            $out[] = '- '.self::line($link['url']);
        }

        return implode("\n", $out)."\n\n";
    }

    /**
     * The security inventory of a device with its open findings, to hand to an AI for analysis.
     *
     * @return array<string, mixed>
     */
    public static function inventory(Device $device, ?SecurityInventory $inventory): array
    {
        $findings = $device->securityFindings()->with('rule')->open()->bySeverity()->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'device' => [
                'name' => $device->displayName,
                'platform' => $device->platform,
                'agent_version' => $device->agent_version,
            ],
            'collected_at' => $inventory?->collected_at?->toIso8601String(),
            'findings' => $findings->map(fn ($finding) => [
                'severity' => $finding->severity,
                'rule' => $finding->rule?->name,
                'message' => $finding->message,
                'first_seen_at' => $finding->first_seen_at?->toIso8601String(),
                'acknowledged' => $finding->acknowledged_at !== null,
            ])->values()->all(),
            'inventory' => $inventory?->data ?? [],
        ];
    }

    /** One line of text: no line breaks or table characters that would break the Markdown. */
    private static function line(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }
}
