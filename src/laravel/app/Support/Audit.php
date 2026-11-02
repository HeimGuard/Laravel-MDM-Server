<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Collection;
use SteelAnts\LaravelBoilerplate\Models\Activity;

/**
 * The audit log (activities, System → Audit) of everything done with the devices; the History tab of
 * a device is read from it alone. An entry is about its device (affected) and, for a command another
 * agent runs for it (wake, ping, port scan), names that target in data.target. data.action says what
 * it was ('command', 'cancel', 'rename', ...); a command entry links its command (data.command_id),
 * whose status the History shows.
 */
class Audit
{
    /** Everything done with a device, by the signed-in user (or $user) or by the system. */
    public static function device(Device $device, string $action, string $text, array $data = [], ?User $user = null): Activity
    {
        return self::write($device->getMorphClass(), $device->id, $action, $text, $data, $user);
    }

    /**
     * Every command sent to an agent. The device it is about: the one a wake or ping is for, else
     * the agent that runs it; a port scan names the scanned address as its target.
     */
    public static function command(DeviceCommand $command, string $action = 'command', ?User $user = null): Activity
    {
        $about = preg_match('/^(device|ping):(\d+)$/', (string) $command->target, $matches) ? (int) $matches[2] : $command->device_id;
        $text = match ($action) {
            'cancel' => __('Cancelled: :command', ['command' => $command->label]),
            default => __('Command: :command', ['command' => $command->label]),
        };

        return self::write((new Device)->getMorphClass(), $about, $action, $text, array_filter([
            'command' => $command->command,
            'command_id' => $command->id,
            'target' => $command->target,
            'relay' => $about !== $command->device_id ? $command->device_id : null,
        ], fn ($value) => $value !== null), $user ?? $command->issuer);
    }

    /**
     * The History of a device: its entries and the ones of commands run for it elsewhere
     * ($targets, e.g. 'ports:192.168.1.5'), newest first, with the commands they link.
     *
     * @return Collection<int, array{activity: Activity, command: ?DeviceCommand}>
     */
    public static function history(Device $device, array $targets = [], int $limit = 50): Collection
    {
        $activities = Activity::query()->with('actor')
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('affected_type', $device->getMorphClass())->where('affected_id', $device->id))
                ->when($targets !== [], fn ($query) => $query->orWhereIn('data->target', $targets)))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $commands = DeviceCommand::query()->with('device')
            ->whereIn('id', $activities->map(fn (Activity $activity) => $activity->data['command_id'] ?? null)->filter()->unique()->values())
            ->get()->keyBy('id');

        return $activities->map(fn (Activity $activity) => [
            'activity' => $activity,
            'command' => ($activity->data['action'] ?? null) === 'command' ? $commands[$activity->data['command_id'] ?? 0] ?? null : null,
        ]);
    }

    private static function write(string $type, int $id, string $action, string $text, array $data, ?User $user): Activity
    {
        $activity = new Activity;
        $activity->lang_text = mb_substr($text, 0, 255);
        $activity->data = ['action' => $action] + $data;
        $activity->affected_type = $type;
        $activity->affected_id = $id;
        // The signed-in user unless given (the observer of the package does it too, but not in the console).
        $user ??= auth()->user() instanceof User ? auth()->user() : null;
        if ($user !== null) {
            $activity->actor()->associate($user);
        }
        $activity->save();

        return $activity;
    }
}
