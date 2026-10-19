<?php

namespace App\Models;

use App\Support\SecurityParsers;
use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A rule of the security scanner: a detection rule (kind "detection", App\Support\SecurityRules)
 * or a parser of raw logs (kind "parser", App\Support\SecurityParsers). Built-in ones come from
 * resources/security/rules.json and parsers.json and follow those files (they can be switched
 * off, not edited); the others are added in the portal by system admins.
 */
class SecurityRule extends Model
{
    public const BUNDLED = 'bundled';

    public const FEED = 'feed';

    public const CUSTOM = 'custom';

    /** Which origin may replace which: a rule of the portal's own is never replaced, the feed is newer than the bundled ones. */
    private const RANK = [self::BUNDLED => 1, self::FEED => 2, self::CUSTOM => 3];

    public const BUILT_IN_PATHS = ['detection' => 'security/rules.json', 'parser' => 'security/parsers.json'];

    protected $fillable = ['kind', 'key', 'name', 'severity', 'source', 'platform', 'definition', 'origin', 'feed_version', 'enabled'];

    protected $casts = [
        'definition' => 'array',
        'enabled' => 'boolean',
    ];

    private ?string $token = null;

    /** @var array{key: string, rules: Collection<int, SecurityRule>, plan: array<int, array>}|null */
    private static ?array $set = null;

    protected static function booted(): void
    {
        $bump = fn () => static::touchVersion();
        static::saved($bump);
        static::deleted($bump);
    }

    /** Says that rules changed (the sets the workers keep in memory are built again). */
    public static function touchVersion(): void
    {
        Cache::forever('mdm.security_rules_version', (int) Cache::get('mdm.security_rules_version', 0) + 1);
        self::$set = null;
    }

    /**
     * The detection rules, kept in memory (a worker runs many scans) until a rule changes.
     *
     * @return Collection<int, SecurityRule>
     */
    public static function detectionSet(): Collection
    {
        return self::loadSet()['rules'];
    }

    /**
     * The same rules as plain values (id, token, enabled, platform, source, event, rule), so that a
     * scan that has nothing to do does not read a thousand models.
     *
     * @return array<int, array{id: int, token: string, enabled: bool, platform: string, source: string, event: bool, rule: SecurityRule}>
     */
    public static function scanPlan(): array
    {
        return self::loadSet()['plan'];
    }

    /** @return array{key: string, rules: Collection<int, SecurityRule>, plan: array<int, array>} */
    private static function loadSet(): array
    {
        $key = Cache::get('mdm.security_rules_version', 0).'|'.static::query()->detection()->count().'|'.static::query()->detection()->max('id');
        if (self::$set === null || self::$set['key'] !== $key) {
            $rules = static::query()->detection()->get();
            self::$set = ['key' => $key, 'rules' => $rules, 'plan' => $rules->map(fn (SecurityRule $rule) => [
                'id' => $rule->id, 'token' => $rule->scanToken, 'enabled' => $rule->enabled, 'platform' => $rule->platform,
                'source' => $rule->source, 'event' => $rule->isEvent, 'rule' => $rule,
            ])->all()];
        }

        return self::$set;
    }

    /** What a scan remembers of the rule: when it is the same, so is what the rule finds in the same items. */
    public function getScanTokenAttribute(): string
    {
        return $this->token ??= substr(md5(json_encode([$this->definition, $this->severity, $this->platform, $this->enabled])), 0, 12);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class);
    }

    /** Whether it comes with the portal or its feed: shown, switched on and off, not edited. */
    public function getBuiltInAttribute(): bool
    {
        return $this->origin !== self::CUSTOM;
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('origin', self::CUSTOM);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeDetection(Builder $query): Builder
    {
        return $query->where('kind', 'detection');
    }

    public function scopeParsers(Builder $query): Builder
    {
        return $query->where('kind', 'parser');
    }

    /** What is wrong with a definition of either kind (empty when it can be used). */
    public static function errorsOf(mixed $definition): array
    {
        return is_array($definition) && ($definition['kind'] ?? null) === 'parser'
            ? SecurityParsers::errors($definition)
            : SecurityRules::errors($definition);
    }

    /** The columns of a rule from its definition (a valid one, see errorsOf). */
    public static function attributesFor(array $definition): array
    {
        $parser = ($definition['kind'] ?? null) === 'parser';

        return [
            'kind' => $parser ? 'parser' : 'detection',
            'key' => $definition['key'],
            'name' => $definition['name'],
            'severity' => $parser ? null : $definition['severity'],
            'source' => $definition['source'],
            'platform' => $definition['platform'] ?? 'any',
            'definition' => $definition,
        ];
    }

    /** Whether the rule looks at devices of this platform. */
    public function appliesTo(Device $device): bool
    {
        return $this->platform === 'any' || $this->platform === $device->platform;
    }

    /**
     * Links of the rule (documentation, ATT&CK, CVE): only http(s) addresses, as [url, host].
     *
     * @return list<array{url: string, host: string}>
     */
    public function getUrlsAttribute(): array
    {
        return array_map(fn (string $url) => ['url' => $url, 'host' => (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST))],
            \App\Support\SecurityRules::urlList($this->definition['urls'] ?? null) ?? []);
    }

    public function getIsParserAttribute(): bool
    {
        return $this->kind === 'parser';
    }

    public function getIsEventAttribute(): bool
    {
        return (bool) (SecurityRules::SOURCES[$this->source]['event'] ?? false);
    }

    public function getSeverityColorAttribute(): string
    {
        return SecurityRules::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function getSourceLabelAttribute(): string
    {
        return __(SecurityRules::SOURCES[$this->source]['label'] ?? SecurityParsers::LOG_SOURCES[$this->source]['label'] ?? $this->source);
    }

    /**
     * Makes the rules of an origin the given definitions: new ones are added (switched on unless the
     * definition says "enabled": false), changed ones updated (the switch stays), the ones of this origin
     * that are not given anymore deleted with their findings. A rule of an origin that ranks higher
     * (custom over feed over bundled) keeps its definition. Invalid definitions are skipped.
     *
     * @param  iterable<int, array>  $definitions  each with "kind" set for parsers
     * @return array{added: int, updated: int, removed: int, skipped: array<int, string>}
     */
    public static function apply(iterable $definitions, string $origin, ?string $version = null): array
    {
        $result = ['added' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => []];
        DB::transaction(function () use ($definitions, $origin, $version, &$result) {
            $keys = [];
            foreach ($definitions as $definition) {
                if (($errors = self::errorsOf($definition)) !== []) {
                    $result['skipped'][] = (is_array($definition) && is_string($definition['key'] ?? null) ? $definition['key'] : '?').': '.implode(' ', $errors);

                    continue;
                }
                $keys[] = $definition['key'];
                $rule = static::query()->firstOrNew(['key' => $definition['key']]);
                if ($rule->exists && self::RANK[$rule->origin] > self::RANK[$origin]) {
                    continue;
                }
                $rule->fill(self::attributesFor($definition) + ['origin' => $origin, 'feed_version' => $origin === self::FEED ? $version : null]);
                if (! $rule->exists) {
                    $rule->enabled = $definition['enabled'] ?? true;
                    $result['added']++;
                } elseif ($rule->isDirty()) {
                    $result['updated']++;
                }
                $rule->save();
            }
            $gone = static::query()->where('origin', $origin)->whereNotIn('key', $keys)->get();
            $result['removed'] = $gone->count();
            $gone->each->delete();
        });

        return $result;
    }

    /**
     * The bundled rules and parsers as they are in their files (resources/security). Done when a file
     * changed since the last time, or when the rules of another origin made room for a bundled one.
     */
    public static function syncBuiltIn(): void
    {
        $hashes = [];
        foreach (self::BUILT_IN_PATHS as $kind => $path) {
            $hashes[$kind] = is_file(resource_path($path)) ? md5_file(resource_path($path)) : null;
        }
        $hash = md5(implode('|', $hashes));
        if (Cache::get('mdm.security_rules') === $hash && static::query()->where('origin', self::BUNDLED)->exists()) {
            return;
        }

        $definitions = [];
        foreach (self::BUILT_IN_PATHS as $kind => $path) {
            if ($hashes[$kind] === null) {
                continue;
            }
            $rules = json_decode((string) file_get_contents(resource_path($path)), true);
            if (! is_array($rules)) {
                report(new \RuntimeException("resources/$path is not valid JSON."));

                return;
            }
            foreach ($rules as $definition) {
                $definitions[] = $kind === 'parser' && is_array($definition) ? ['kind' => 'parser'] + $definition : $definition;
            }
        }
        foreach (self::apply($definitions, self::BUNDLED)['skipped'] as $skipped) {
            report(new \RuntimeException('Bundled security rule '.$skipped));
        }
        Cache::forever('mdm.security_rules', $hash);
    }
}
