<?php

namespace App\Support;

use App\Models\Script;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes a remediation script from a description with an AI: Anthropic (Claude) or OpenAI, or a server
 * with an OpenAI compatible API (MDM_AI_PROVIDER, MDM_AI_API_KEY, MDM_AI_MODEL, MDM_AI_URL). Only the
 * description and the script being edited leave the server; nothing about the devices.
 */
class ScriptGenerator
{
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

    /** Where the requests go and what they answer: the Messages API or the Chat Completions API. */
    public const PROVIDERS = [
        'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages', 'model' => 'claude-opus-5-5'],
        'openai' => ['url' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-5'],
    ];

    /** Anthropic models that take the server side fallback on a refusal (others answer it with HTTP 400). */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public static function provider(): ?string
    {
        $provider = strtolower((string) config('mdm.ai_provider'));

        return array_key_exists($provider, self::PROVIDERS) ? $provider : null;
    }

    /** A key, or for an OpenAI compatible server of your own (Ollama, LM Studio, vLLM) its address. */
    public static function available(): bool
    {
        return self::provider() !== null && (filled(config('mdm.ai_api_key')) || (self::provider() === 'openai' && filled(config('mdm.ai_url'))));
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
        $provider = self::provider();
        $url = config('mdm.ai_url') ?: self::PROVIDERS[$provider]['url'];
        $model = config('mdm.ai_model') ?: self::PROVIDERS[$provider]['model'];

        // Writing the scripts takes a minute or two, more than a request may run by default.
        set_time_limit(300);
        try {
            $response = $provider === 'anthropic'
                ? Http::timeout(240)
                    ->withHeaders(['x-api-key' => config('mdm.ai_api_key'), 'anthropic-version' => '2023-06-01'])
                    // On a refusal the API answers with the model it picks instead (only these models take it).
                    ->when(in_array($model, self::FALLBACK_MODELS, true), fn ($http) => $http->withHeaders(['anthropic-beta' => 'server-side-fallback-2026-07-01']))
                    ->post($url, [
                        'model' => $model,
                        'max_tokens' => 16000,
                        'system' => self::SYSTEM,
                        'messages' => [['role' => 'user', 'content' => $content]],
                        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
                    ] + (in_array($model, self::FALLBACK_MODELS, true) ? ['fallbacks' => 'default'] : []))
                : Http::timeout(240)
                    ->when(filled(config('mdm.ai_api_key')), fn ($http) => $http->withToken(config('mdm.ai_api_key')))
                    ->post($url, [
                        'model' => $model,
                        'messages' => [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $content]],
                        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'script', 'strict' => true, 'schema' => self::SCHEMA]],
                    ]);
        } catch (ConnectionException $e) {
            Log::warning("Script generation: {$e->getMessage()}");
            throw new RuntimeException(__('The AI could not be reached: :error', ['error' => mb_strimwidth($e->getMessage(), 0, 300, '…')]));
        }
        if ($response->failed()) {
            Log::warning("Script generation ($provider) failed: HTTP ".$response->status().' '.mb_strimwidth($response->body(), 0, 500));
            // Both APIs say what is wrong in error.message (a wrong key, no credit, an unknown model).
            $error = $response->json('error.message') ?? $response->json('message') ?? mb_strimwidth(trim(strip_tags($response->body())), 0, 200, '…');
            throw new RuntimeException(__('The AI could not be reached: :error', ['error' => 'HTTP '.$response->status().', '.mb_strimwidth((string) $error, 0, 300, '…')]));
        }
        if ($provider === 'anthropic') {
            $refused = $response->json('stop_reason') === 'refusal';
            $cut = $response->json('stop_reason') === 'max_tokens';
            $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? null;
        } else {
            $refused = filled($response->json('choices.0.message.refusal')) || $response->json('choices.0.finish_reason') === 'content_filter';
            $cut = $response->json('choices.0.finish_reason') === 'length';
            $text = $response->json('choices.0.message.content');
        }
        if ($refused) {
            throw new RuntimeException(__('The AI declined this request.'));
        }
        $script = is_string($text) && ! $cut ? json_decode($text, true) : null;
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
