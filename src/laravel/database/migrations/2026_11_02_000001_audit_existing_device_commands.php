<?php

use App\Models\DeviceCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every command is in the audit log (activities) now, and the History tab of a device is read
     * from it: the commands sent before get their entry, as App\Support\Audit::command writes it.
     */
    public function up(): void
    {
        $audited = DB::table('activities')->where('data', 'like', '%"command_id"%')->pluck('data')
            ->map(fn ($data) => json_decode($data, true)['command_id'] ?? null)->filter()->flip();
        $userType = (new App\Models\User)->getMorphClass();
        $deviceType = (new App\Models\Device)->getMorphClass();

        DeviceCommand::query()->orderBy('id')->chunk(500, function ($commands) use ($audited, $userType, $deviceType) {
            $rows = [];
            foreach ($commands as $command) {
                if ($audited->has($command->id)) {
                    continue;
                }
                $about = preg_match('/^(device|ping):(\d+)$/', (string) $command->target, $matches) ? (int) $matches[2] : $command->device_id;
                $rows[] = [
                    'ip' => null,
                    'actor_type' => $command->issued_by ? $userType : null,
                    'actor_id' => $command->issued_by,
                    'affected_type' => $deviceType,
                    'affected_id' => $about,
                    'lang_text' => mb_substr(__('Command: :command', ['command' => $command->label]), 0, 255),
                    'data' => json_encode(array_filter([
                        'action' => 'command',
                        'command' => $command->command,
                        'command_id' => $command->id,
                        'target' => $command->target,
                        'relay' => $about !== $command->device_id ? $command->device_id : null,
                    ], fn ($value) => $value !== null)),
                    'created_at' => $command->created_at,
                ];
            }
            if ($rows !== []) {
                DB::table('activities')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        // The entries stay: the audit log is not rewritten.
    }
};
