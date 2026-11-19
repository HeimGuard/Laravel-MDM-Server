<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Commands of a device: the agent takes them, reports their progress and result (agents 1.8.0+), acknowledges them (agents before 1.7.0).
 */
class DeviceCommandController extends Controller
{
    /**
     * Signing agents take their commands here when the WebSocket announces one: the response is
     * signed for this request, so a command cannot be replayed or injected.
     */
    public function take(Request $request)
    {
        return response()->json(Device::commandResponse($request->user()->id));
    }

    /**
     * Agents 1.8.0+ report what a command they took is doing: running (with an optional progress
     * in percent and a message), then succeeded or failed. Only for this device's commands.
     */
    public function result(Request $request, int $command)
    {
        /** @var Device $device */
        $device = $request->user();
        $deviceCommand = DeviceCommand::query()->whereKey($command)->where('device_id', $device->id)->first();
        abort_if($deviceCommand === null, 404);

        $status = (string) $request->json('status');
        if (! in_array($status, DeviceCommand::RESULTS, true)) {
            return response()->json(['error' => 'invalid_status'], 422);
        }
        $progress = $request->json('progress');
        $message = $request->json('message');
        $values = [
            'status' => $status,
            // 100 % only once it is done: a running command at 100 % would look stuck.
            'progress' => is_numeric($progress) ? max(0, min($status === 'running' ? 99 : 100, (int) $progress)) : ($status === 'succeeded' ? 100 : null),
            'message' => is_string($message) && $message !== '' ? mb_strcut($message, 0, 1000) : null,
            'updated_at' => now(),
        ];
        // Which rows an update of many packages is at (agents 1.22.0+): the one being installed and
        // the finished ones. Only plain strings of a sensible size are kept.
        $current = $request->json('current');
        $done = $request->json('done');
        $values['current'] = is_string($current) && $status === 'running' && $current !== '' ? mb_strcut($current, 0, 300) : null;
        if (is_array($done)) {
            $values['done'] = json_encode(array_values(array_map(fn ($target) => mb_strcut($target, 0, 300), array_slice(array_filter($done, 'is_string'), 0, 300))));
        }
        if ($status === 'running') {
            $values['started_at'] = $deviceCommand->started_at ?? now();
        } else {
            $values['finished_at'] = now();
        }

        // A finished (or given up) command is not changed anymore.
        $updated = DeviceCommand::query()->whereKey($deviceCommand->id)->whereIn('status', ['sent', 'running'])->toBase()->update($values);

        if ($updated === 1) {
            DeviceCommand::announce($device->id, $deviceCommand->target);
            // What failed on a device is also in the server log (the command keeps the first 1000 bytes of it, the log more).
            if ($status === 'failed') {
                Log::warning("Command {$deviceCommand->command} ({$deviceCommand->id}) failed on device {$device->id} ({$device->displayName}, agent {$device->agent_version}): ".(is_string($message) && $message !== '' ? mb_strcut($message, 0, 4000) : 'no reason given'));
            }
        }

        return $updated === 1
            ? response()->json(['status' => $status])
            : response()->json(['error' => 'not_running', 'status' => $deviceCommand->status], 409);
    }

    /**
     * Agents before 1.7.0 acknowledge a command before executing it so it is not delivered twice.
     */
    public function ack(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();
        $command = $request->input('command');

        // They execute the command from the WebSocket event, the acknowledgement is its delivery.
        $queued = $device->commands()->where('status', 'queued')->where('command', (string) $command)->orderBy('id')->first();
        $queued?->update(['status' => 'delivered']);
        $queued?->forceFill(['sent_at' => now(), 'finished_at' => now()])->save();

        return response()->json(['commands' => $device->queuedCommands]);
    }
}
