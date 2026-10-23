<?php

namespace App\Support;

use App\Models\SecurityRule;
use App\Jobs\ApplySecurityFeed;
use App\Jobs\FetchSecurityFeedChunk;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Throwable;

/**
 * The rules of the security scanner from a public git repository (MDM_SECURITY_FEED_URL, at
 * MDM_SECURITY_FEED_REF): manifest.json lists its version and the SHA-256 of every file, rules/*.json
 * and parsers/*.json hold the definitions (one rule or a list per file). Everything is fetched and
 * checked first, then applied in one go: a file that does not match the manifest or an unreachable
 * repository changes nothing.
 */
class SecurityFeed
{
    public const SETTING = 'mdm.security_feed';

    private const MAX_FILES = 2048;

    /** Files one job fetches. */
    private const CHUNK_FILES = 100;

    private const MAX_FILE_BYTES = 1048576;

    private const MAX_TOTAL_BYTES = 8388608;

    public static function enabled(): bool
    {
        return self::rawBase() !== null;
    }

    /** Where the raw files are: a GitHub address becomes raw.githubusercontent.com, anything else is taken as the base. */
    public static function rawBase(): ?string
    {
        $url = rtrim(trim((string) config('mdm.security_feed_url')), '/');
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }
        $ref = trim((string) config('mdm.security_feed_ref')) ?: 'main';
        if (preg_match('~^https://github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?$~', $url, $m)) {
            return "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/".rawurlencode($ref);
        }

        return $url;
    }

    /** What the last run did: version, checked_at, ok, added, updated, removed, skipped, error. */
    public static function state(): array
    {
        $value = Setting::query()->whereNull('settable_id')->where('index', self::SETTING)->value('value');
        $state = is_string($value) ? json_decode($value, true) : null;

        return is_array($state) ? $state : [];
    }

    private static function remember(array $state): array
    {
        Setting::query()->whereNull('settable_id')->updateOrCreate(['index' => self::SETTING], ['value' => json_encode($state), 'type' => SettingDataType::STRING]);

        return $state;
    }

    /**
     * Takes the rules of the feed. Not when the version did not change since the last successful run
     * (unless forced).
     *
     * @return array the state after the run
     */
    public static function sync(bool $force = false): array
    {
        $previous = self::state();
        $base = self::rawBase();
        if ($base === null) {
            return $previous;
        }
        $state = ['checked_at' => now()->toIso8601String()] + $previous;

        try {
            $manifest = self::manifest(self::fetch("$base/manifest.json"));
            if (! $force && ($previous['ok'] ?? false) && ($previous['version'] ?? null) === $manifest['version']) {
                return self::remember($state);
            }

            // Fetched in chunks, each in its own job (a feed of hundreds of files does not fit one job);
            // the last job applies everything in one go.
            $run = (string) Str::uuid();
            self::purgeOldRuns();
            $jobs = [];
            foreach (array_chunk($manifest['files'], self::CHUNK_FILES, true) as $index => $files) {
                $jobs[] = new FetchSecurityFeedChunk($run, $index, $files);
            }
            $jobs[] = new ApplySecurityFeed($run, $manifest['version'], count($jobs));
            Bus::chain($jobs)->dispatch();

            return self::state();
        } catch (Throwable $e) {
            Log::warning('Security rules feed: '.$e->getMessage());

            return self::remember(['ok' => false, 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…')] + $state);
        }
    }

    /** Fetches and checks one chunk of the manifest and keeps its definitions for the job that applies the run. */
    public static function fetchChunk(string $run, int $index, array $files): void
    {
        if (self::aborted($run)) {
            return;
        }

        try {
            $base = self::rawBase() ?? throw new RuntimeException('The feed is not set.');
            $definitions = [];
            $bytes = 0;
            foreach ($files as $path => $hash) {
                $body = self::fetch("$base/$path");
                $bytes += strlen($body);
                if ($bytes > self::MAX_TOTAL_BYTES || ! hash_equals(strtolower($hash), hash('sha256', $body))) {
                    throw new RuntimeException("$path does not match the manifest.");
                }
                $parsed = json_decode($body, true);
                if (! is_array($parsed)) {
                    throw new RuntimeException("$path is not JSON.");
                }
                foreach (isset($parsed['key']) ? [$parsed] : $parsed as $definition) {
                    $definitions[] = str_starts_with($path, 'parsers/') && is_array($definition) ? ['kind' => 'parser'] + $definition : $definition;
                }
            }
            self::disk()->put(self::dir($run)."/$index.json", json_encode(['bytes' => $bytes, 'definitions' => $definitions]));
        } catch (Throwable $e) {
            self::abort($run, $e);
        }
    }

    /** Applies the chunks of a run in one go, when every one of them was fetched and checked. */
    public static function applyRun(string $run, string $version, int $chunks): void
    {
        try {
            if (self::aborted($run)) {
                return;
            }
            $definitions = [];
            $total = 0;
            for ($index = 0; $index < $chunks; $index++) {
                $chunk = json_decode((string) self::disk()->get(self::dir($run)."/$index.json"), true);
                if (! is_array($chunk)) {
                    throw new RuntimeException("Part $index of the feed was not fetched.");
                }
                $total += (int) $chunk['bytes'];
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('The feed is larger than '.self::MAX_TOTAL_BYTES.' bytes.');
                }
                $definitions = array_merge($definitions, $chunk['definitions']);
            }

            $result = SecurityRule::apply($definitions, SecurityRule::FEED, $version);
            // A bundled rule the feed no longer has comes back.
            Cache::forget('mdm.security_rules');
            SecurityRule::syncBuiltIn();
            if ($result['added'] + $result['updated'] + $result['removed'] > 0) {
                SecurityScanner::scanAll();
            }

            self::remember([
                'checked_at' => now()->toIso8601String(), 'version' => $version, 'ok' => true, 'error' => null,
                'added' => $result['added'], 'updated' => $result['updated'], 'removed' => $result['removed'], 'skipped' => array_slice($result['skipped'], 0, 20),
            ]);
        } catch (Throwable $e) {
            self::abort($run, $e);
        } finally {
            self::disk()->deleteDirectory(self::dir($run));
        }
    }

    /** A run failed: the other jobs of it do nothing and the state shows why; nothing was applied. */
    public static function abort(string $run, Throwable $e): void
    {
        Log::warning('Security rules feed: '.$e->getMessage());
        self::disk()->put(self::dir($run).'/failed', '1');
        self::remember(['ok' => false, 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…'), 'checked_at' => now()->toIso8601String()] + self::state());
    }

    private static function aborted(string $run): bool
    {
        return self::disk()->exists(self::dir($run).'/failed');
    }

    private static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    private static function dir(string $run): string
    {
        return "security-feed/$run";
    }

    /** Leftovers of runs whose worker died. */
    private static function purgeOldRuns(): void
    {
        foreach (self::disk()->directories('security-feed') as $dir) {
            if (self::disk()->lastModified($dir) < now()->subDay()->getTimestamp()) {
                self::disk()->deleteDirectory($dir);
            }
        }
    }

    private static function fetch(string $url): string
    {
        $response = Http::timeout(20)->withHeaders(['Accept' => 'application/json, text/plain'])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("$url answered HTTP {$response->status()}.");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_FILE_BYTES) {
            throw new RuntimeException("$url is larger than ".self::MAX_FILE_BYTES.' bytes.');
        }

        return $body;
    }

    /** @return array{version: string, files: array<string, string>} */
    private static function manifest(string $body): array
    {
        $manifest = json_decode($body, true);
        if (! is_array($manifest) || ! is_string($manifest['version'] ?? null) || ! preg_match('/^[\w.+-]{1,32}$/', $manifest['version']) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('manifest.json is not a manifest: it needs a version and files.');
        }
        if (count($manifest['files']) > self::MAX_FILES) {
            throw new RuntimeException('manifest.json lists more than '.self::MAX_FILES.' files.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (! is_string($path) || ! preg_match('~^(rules|parsers)/[\w.-]+(/[\w.-]+)*\.json$~', $path) || str_contains($path, '..') || ! is_string($hash) || ! preg_match('/^[0-9a-fA-F]{64}$/', $hash)) {
                throw new RuntimeException('manifest.json lists an unusable file: '.json_encode($path));
            }
        }

        return ['version' => $manifest['version'], 'files' => $manifest['files']];
    }
}
