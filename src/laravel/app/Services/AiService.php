<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The AI of the server: Anthropic (Claude) or OpenAI, or a server with an OpenAI compatible API
 * (MDM_AI_PROVIDER, MDM_AI_API_KEY, MDM_AI_MODEL, MDM_AI_URL). It writes the remediation scripts
 * (ScriptGeneratorService) and explains the findings of the scanner (FindingExplanationService).
 */
class AiService
{
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
     * The text the AI answers to one message: JSON of the schema when one is given, else plain text. Throws a
     * RuntimeException with a message for the user when it cannot (not set up, not reachable, declined, cut off).
     *
     * @param  array<string, mixed>|null  $schema  a JSON schema the answer has to follow
     * @param  string  $name  what is asked for, for the log and the name of the schema
     */
    public static function ask(string $system, string $content, ?array $schema = null, string $name = 'answer', int $maxTokens = 16000, string $effort = 'medium'): string
    {
        if (! self::available()) {
            throw new RuntimeException(__('AI is not set up (MDM_AI_API_KEY).'));
        }
        $provider = self::provider();
        $url = config('mdm.ai_url') ?: self::PROVIDERS[$provider]['url'];
        $model = config('mdm.ai_model') ?: self::PROVIDERS[$provider]['model'];

        // An answer takes a minute or two, more than a request may run by default.
        set_time_limit(300);
        try {
            $response = $provider === 'anthropic'
                ? Http::timeout(240)
                    ->withHeaders(['x-api-key' => config('mdm.ai_api_key'), 'anthropic-version' => '2023-06-01'])
                    // On a refusal the API answers with the model it picks instead (only these models take it).
                    ->when(in_array($model, self::FALLBACK_MODELS, true), fn ($http) => $http->withHeaders(['anthropic-beta' => 'server-side-fallback-2026-07-01']))
                    ->post($url, [
                        'model' => $model,
                        'max_tokens' => $maxTokens,
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $content]],
                        'output_config' => ['effort' => $effort] + ($schema ? ['format' => ['type' => 'json_schema', 'schema' => $schema]] : []),
                    ] + (in_array($model, self::FALLBACK_MODELS, true) ? ['fallbacks' => 'default'] : []))
                : Http::timeout(240)
                    ->when(filled(config('mdm.ai_api_key')), fn ($http) => $http->withToken(config('mdm.ai_api_key')))
                    ->post($url, [
                        'model' => $model,
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]],
                    ] + ($schema ? ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema]]] : []));
        } catch (ConnectionException $e) {
            Log::warning("AI ($name): {$e->getMessage()}");
            throw new RuntimeException(__('The AI could not be reached: :error', ['error' => mb_strimwidth($e->getMessage(), 0, 300, '…')]));
        }
        if ($response->failed()) {
            Log::warning("AI ($name, $provider) failed: HTTP ".$response->status().' '.mb_strimwidth($response->body(), 0, 500));
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

        // A cut answer is no answer: a half script, a half sentence.
        return is_string($text) && ! $cut ? $text : '';
    }
}
