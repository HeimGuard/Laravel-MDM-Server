<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SteelAnts\LaravelBoilerplate\Models\Activity;
use SteelAnts\LaravelBoilerplate\Traits\Auditable;
use Throwable;

/**
 * A remediation script: a PowerShell detection script (exit 0 = compliant, 1 = needs remediation)
 * and an optional remediation script, run on the devices as SYSTEM / root without network access.
 * With manual_remediation the runs only detect; the remediation is started per device (Remediate).
 */
class Script extends Model
{
    use Auditable;

    public const PLATFORMS = ['all' => 'All', 'windows' => 'Windows', 'linux' => 'Linux'];

    public const MAX_TIMEOUT = 3600;

    /** The most a detection or remediation script may have, in bytes (as in Intune: 200 kB). */
    public const MAX_CODE_BYTES = 204800;

    /** Runs not taken by the agent within this time expire (the signed manifest does too). */
    public const RUN_TTL = 86400;

    protected $fillable = ['name', 'description', 'platform', 'detection', 'remediation', 'timeout', 'schedule', 'schedule_target', 'manual_remediation'];

    protected $casts = [
        'manual_remediation' => 'boolean',
        'schedule_target' => 'array',
        'last_scheduled_at' => 'datetime',
    ];

    /** Schedules offered in the picker (any other cron expression can be entered). */
    public const SCHEDULE_PRESETS = [
        '0 * * * *' => 'Every hour',
        '0 */6 * * *' => 'Every 6 hours',
        '0 3 * * *' => 'Every day at 3:00',
        '0 3 * * 0' => 'Every Sunday at 3:00',
        '0 3 1 * *' => 'On the 1st of the month at 3:00',
    ];

    /** Whether the text is a cron expression with five fields (no seconds, no @macros beyond the standard ones). */
    public static function validSchedule(?string $expression): bool
    {
        $expression = trim((string) $expression);
        if ($expression === '' || strlen($expression) > 100) {
            return false;
        }

        return CronExpression::isValidExpression($expression);
    }

    public function getScheduledAttribute(): bool
    {
        return self::validSchedule($this->schedule);
    }

    /** The next scheduled run (app time zone), null without a schedule. */
    public function nextScheduledRun(?Carbon $after = null): ?Carbon
    {
        if (! $this->scheduled) {
            return null;
        }

        return Carbon::instance((new CronExpression($this->schedule))->getNextRunDate(($after ?? now())->toDateTime(), 0, false, config('mdm.timezone')))->setTimezone(config('mdm.timezone'));
    }

    /**
     * Runs the scripts whose schedule is due this minute on their target devices. A minute is only
     * run once, also when the scheduler starts it twice. Returns the number of scripts started.
     */
    public static function runScheduled(?Carbon $now = null): int
    {
        $minute = ($now ?? now())->copy()->startOfMinute();
        $started = 0;
        foreach (static::query()->whereNotNull('schedule')->get() as $script) {
            try {
                if (! $script->scheduled || ! (new CronExpression($script->schedule))->isDue($minute->toDateTime(), config('mdm.timezone'))) {
                    continue;
                }
                $claimed = static::query()->whereKey($script->id)
                    ->where(fn ($query) => $query->whereNull('last_scheduled_at')->orWhere('last_scheduled_at', '<', $minute))
                    ->toBase()->update(['last_scheduled_at' => $minute]);
                if ($claimed !== 1) {
                    continue;
                }
                $script->runOn(Device::targeted($script->schedule_target ?? [])->pluck('id')->all(), null, true);
                $started++;
            } catch (Throwable $e) {
                Log::warning("Scheduled run of script {$script->id} failed: {$e->getMessage()}");
            }
        }

        return $started;
    }

    protected static function booted(): void
    {
        // A change of what runs is a new version with a new fingerprint; runs keep theirs.
        static::saving(function (Script $script) {
            $script->remediation = ($script->remediation === null || trim($script->remediation) === '') ? null : $script->remediation;
            $script->platform ??= 'all';
            $script->timeout ??= 60;
            if (! $script->exists) {
                $script->version = 1;
            } elseif ($script->isDirty(['detection', 'remediation', 'platform', 'timeout'])) {
                $script->version = $script->getOriginal('version') + 1;
            }
            $script->fingerprint = self::fingerprintOf($script->platform, (int) $script->timeout, $script->detection, $script->remediation);
        });
    }

    /** SHA-256 over what the device runs: platform, timeout and the hashes of both scripts. */
    public static function fingerprintOf(string $platform, int $timeout, string $detection, ?string $remediation): string
    {
        return hash('sha256', json_encode([
            'detection_sha256' => hash('sha256', $detection),
            'platform' => $platform,
            'remediation_sha256' => $remediation === null ? null : hash('sha256', $remediation),
            'timeout' => $timeout,
        ]));
    }

    /** The fingerprint of a run that only detects: the device gets no remediation script. */
    public function detectionFingerprint(): string
    {
        return self::fingerprintOf($this->platform, (int) $this->timeout, $this->detection, null);
    }

    /** Whether runs only detect and the remediation is started by hand. */
    public function getDetectsOnlyAttribute(): bool
    {
        return $this->manual_remediation && $this->remediation !== null;
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScriptRun::class);
    }

    /** Whether this script is meant for the device's platform. */
    public function supports(Device $device): bool
    {
        return $this->platform === 'all' || $this->platform === $device->platform;
    }

    /**
     * Why the script cannot run on the device, or null when it can.
     */
    public function unavailableReason(Device $device): ?string
    {
        return match (true) {
            ! $this->supports($device) => __('Other platform'),
            ! $device->signsRequests => __('Agent does not sign (update it)'),
            ! $device->scriptsEnabled => __('Scripts disabled on the device'),
            default => null,
        };
    }

    /**
     * Queues a run on each device that can run it and triggers the agents. Returns the runs. A
     * script with manual remediation only detects, unless $remediate (Remediate on a device).
     *
     * @param  array<int>  $deviceIds
     * @return Collection<int, ScriptRun>
     */
    public function runOn(array $deviceIds, ?User $user = null, bool $scheduled = false, bool $remediate = false)
    {
        $detectOnly = $this->detectsOnly && ! $remediate;
        $runs = Device::query()->whereIn('id', $deviceIds)->get()
            ->filter(fn (Device $device) => $this->unavailableReason($device) === null)
            ->map(function (Device $device) use ($user, $detectOnly) {
                // A newer run replaces a waiting one of the same script.
                $this->runs()->where('device_id', $device->id)->where('status', 'pending')->update(['status' => 'superseded']);

                $run = $this->runs()->create([
                    'device_id' => $device->id,
                    'version' => $this->version,
                    'mode' => $detectOnly ? 'detect' : null,
                    'fingerprint' => $detectOnly ? $this->detectionFingerprint() : $this->fingerprint,
                    'issued_by' => $user?->id,
                    'issued_at' => now(),
                    'expires_at' => now()->addSeconds(self::RUN_TTL),
                ]);
                // Offline devices take it with their next report.
                $device->queueCommand('runScripts');

                return $run;
            })
            ->values();

        $activity = new Activity;
        $activity->lang_text = match (true) {
            $remediate => __('Remediated with Script :name', ['name' => $this->name]),
            $scheduled => __('Scheduled run of Script :name', ['name' => $this->name]),
            default => __('Ran Script :name', ['name' => $this->name]),
        };
        $activity->data = ['version' => $this->version, 'fingerprint' => $this->fingerprint, 'devices' => $runs->pluck('device_id')->all()];
        $activity->affected()->associate($this);
        $activity->save();

        return $runs;
    }
}
