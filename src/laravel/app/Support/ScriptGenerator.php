<?php

namespace App\Support;

use App\Models\Script;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes a remediation script from a description, with Claude (Anthropic API, MDM_AI_API_KEY). Only
 * the description and the script being edited leave the server; nothing about the devices.
 */
class ScriptGenerator
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    private const SYSTEM = <<<'TEXT'
        You write remediation scripts for a device management server, like Intune proactive remediations.
        A script is a pair of PowerShell 7 scripts that run on Windows and Linux devices as SYSTEM / root,
        without network access and without a user to answer prompts:
        - detection: checks the state. It has to end with `exit 0` when the device is compliant and `exit 1`
          when it needs the remediation; both exits have to be in the script. Write what was found to the output.
        - remediation: fixes it (it runs when the detection exits with 1, then the detection runs again). Make it
          idempotent, non-interactive, and exit with a non-zero code when it fails. Leave it empty when the request
          only asks for a check.
        Platform: "windows" or "linux" when the scripts only work there, "all" when they handle both (use $IsWindows /
        $IsLinux). Timeout in seconds, 5 to 3600, enough for the slowest step. manual_remediation: true when the fix
        is disruptive (a restart, removing software or data, stopping services) and should be started per device
        by hand. Name (short) and description (one or two sentences) in the language of the request; comments in
        the scripts in English. When a current script is given, change it as the request asks.
        TEXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'platform' => ['type' => 'string', 'enum' => ['all', 'windows', 'linux']],
            'timeout' => ['type' => 'integer'],
            'detection' => ['type' => 'string'],
            'remediation' => ['type' => 'string'],
            'manual_remediation' => ['type' => 'boolean'],
        ],
        'required' => ['name', 'description', 'platform', 'timeout', 'detection', 'remediation', 'manual_remediation'],
        'additionalProperties' => false,
    ];

    public static function available(): bool
    {
        return filled(config('mdm.ai_api_key'));
    }

    /**
     * The script for the request: name, description, platform, timeout, detection, remediation,
     * manual_remediation. Throws a RuntimeException with a message for the user when it cannot.
     *
     * @param  array<string, mixed>  $current  the script in the form, changed instead of a new one when it has code
     * @return array{name: string, description: string, platform: string, timeout: int, detection: string, remediation: string, manual_remediation: bool}
     */
    public static function generate(string $request, array $current = []): array
    {
        if (! self::available()) {
            throw new RuntimeException(__('AI is not set up (MDM_AI_API_KEY).'));
        }
        $content = 'Request: '.trim($request);
        if (trim((string) ($current['detection'] ?? '')) !== '') {
            $content .= "\n\nCurrent script (JSON):\n".json_encode(array_intersect_key($current, self::SCHEMA['properties']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Writing the scripts takes a minute or two, more than a request may run by default.
        set_time_limit(300);
        try {
            $response = Http::timeout(240)
                ->withHeaders([
                    'x-api-key' => config('mdm.ai_api_key'),
                    'anthropic-version' => '2023-06-01',
                    // On a refusal the API answers with the model it picks instead.
                    'anthropic-beta' => 'server-side-fallback-2026-07-01',
                ])
                ->post(self::URL, [
                    'model' => config('mdm.ai_model'),
                    'max_tokens' => 16000,
                    'system' => self::SYSTEM,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
                    'fallbacks' => 'default',
                ]);
        } catch (ConnectionException $e) {
            Log::warning("Script generation: {$e->getMessage()}");
            throw new RuntimeException(__('The AI could not be reached.'));
        }
        if ($response->failed()) {
            Log::warning('Script generation failed: HTTP '.$response->status().' '.mb_strimwidth($response->body(), 0, 500));
            throw new RuntimeException(__('The AI could not be reached.'));
        }
        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException(__('The AI declined this request.'));
        }
        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? null;
        $script = is_string($text) && $response->json('stop_reason') !== 'max_tokens' ? json_decode($text, true) : null;
        if (! is_array($script) || ! is_string($script['detection'] ?? null) || trim($script['detection']) === '') {
            throw new RuntimeException(__('The AI did not return a script, try again.'));
        }

        return [
            'name' => mb_substr(trim((string) ($script['name'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string) ($script['description'] ?? '')), 0, 2000),
            'platform' => array_key_exists($script['platform'] ?? null, Script::PLATFORMS) ? $script['platform'] : 'all',
            'timeout' => max(5, min(Script::MAX_TIMEOUT, (int) ($script['timeout'] ?? 60))),
            'detection' => $script['detection'],
            'remediation' => (string) ($script['remediation'] ?? ''),
            'manual_remediation' => (bool) ($script['manual_remediation'] ?? false),
        ];
    }
}
