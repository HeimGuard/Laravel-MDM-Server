<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Icon of a container from the dashboard-icons (https://github.com/homarr-labs/dashboard-icons) and
 * selfh.st/icons (https://selfh.st/icons) databases, served by the jsDelivr CDN.
 *
 * The names of the icons are read from the index of each database (cached for a day) and compared
 * without case and punctuation, so the image `pihole` finds `pi-hole`, and a file that exists only
 * as a PNG is used as it is. Without the index (the CDN is not reachable) the names are only guessed
 * and the page falls back to the next candidate, at last to the Docker icon.
 */
class DockerIconService
{
    private const DASHBOARD_ICONS = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/';

    private const SELFHST_ICONS = 'https://cdn.jsdelivr.net/gh/selfhst/icons/';

    private const CACHE_KEY = 'docker-icons:index';

    /** Image names (last path segment) whose icon is named quite differently. */
    private const ALIASES = [
        'postgres' => 'postgresql',
        'mongo' => 'mongodb',
    ];

    /** @var array<string, array<string, string>>|null */
    private static ?array $index = null;

    /**
     * Candidate icon names for an image, most specific first:
     * `lscr.io/linuxserver/bookstack:latest` => [bookstack], `zabbix/zabbix-web-nginx-mysql` => [zabbix-web-nginx-mysql, zabbix].
     *
     * @return list<string>
     */
    public static function candidates(string $image): array
    {
        $image = strtolower(trim(explode('@', $image)[0]));
        $parts = explode('/', preg_replace('/:[^\/]*$/', '', $image) ?? $image); // without the tag, a registry port stays
        $name = end($parts);
        if ($name === '') {
            return [];
        }

        $candidates = [self::ALIASES[$name] ?? $name];

        // `zabbix-web-nginx-mysql`, `php_unitedfront_v2`: the first word is the product.
        $first = preg_split('/[-_.]/', $name)[0] ?? '';
        if ($first !== '' && $first !== $name) {
            $candidates[] = self::ALIASES[$first] ?? $first;
        }

        // The organisation (`amir20/dozzle` is the name, `gamerclassn7/ai-retard` is not): last, the least certain.
        if (count($parts) >= 2 && ! in_array($parts[count($parts) - 2], ['library', 'linuxserver'], true)) {
            $candidates[] = $parts[count($parts) - 2];
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => preg_match('/^[a-z0-9][a-z0-9._-]*$/', $c) === 1)));
    }

    /** URLs to try, in order. Empty: there is no icon for the image (the Docker icon stays). @return list<string> */
    public static function urls(string $image): array
    {
        $index = self::index();

        return $index === [] ? self::guess($image) : self::match($index, $image);
    }

    /**
     * The icons of the image that exist in the index: the most specific name first, within a name the
     * databases in their order.
     *
     * @param  array<string, array<string, string>>  $index  source => [name without punctuation => URL]
     * @return list<string>
     */
    public static function match(array $index, string $image): array
    {
        $urls = [];
        foreach (self::candidates($image) as $name) {
            foreach ($index as $icons) {
                if (isset($icons[self::key($name)])) {
                    $urls[] = $icons[self::key($name)];
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** Without the index: the SVG of each candidate in each database, it may not exist. @return list<string> */
    private static function guess(string $image): array
    {
        $urls = [];
        foreach (self::candidates($image) as $name) {
            $urls[] = self::DASHBOARD_ICONS.'svg/'.$name.'.svg';
            $urls[] = self::SELFHST_ICONS.'svg/'.$name.'.svg';
        }

        return $urls;
    }

    /** `Pi-Hole`, `pihole` and `pi_hole` are the same icon. */
    private static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    /**
     * The names of the icons of both databases with the file of each (SVG, a PNG only where there is no SVG).
     * Cached; a failed download is retried after a few minutes, the other database is used meanwhile.
     *
     * @return array<string, array<string, string>>
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = Cache::get(self::CACHE_KEY);
        if (! is_array($index)) {
            $failed = false;
            $index = array_filter([
                'dashboard-icons' => self::download(fn () => self::dashboardIcons(), $failed),
                'selfh.st' => self::download(fn () => self::selfhstIcons(), $failed),
            ]);
            Cache::put(self::CACHE_KEY, $index, $failed || $index === [] ? now()->addMinutes(5) : now()->addDay());
        }

        return self::$index = $index;
    }

    /** @return array<string, string> */
    private static function download(callable $read, bool &$failed): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            $failed = true;

            return [];
        }
    }

    /** @return array<string, string> */
    private static function dashboardIcons(): array
    {
        $tree = Http::timeout(10)->connectTimeout(3)->get(self::DASHBOARD_ICONS.'tree.json')->throw()->json();

        $icons = [];
        foreach (['svg', 'png'] as $extension) {
            foreach ($tree[$extension] ?? [] as $file) {
                // The first one stays: an SVG before a PNG.
                $icons[self::key(pathinfo($file, PATHINFO_FILENAME))] ??= self::DASHBOARD_ICONS.$extension.'/'.$file;
            }
        }

        return $icons;
    }

    /** @return array<string, string> */
    private static function selfhstIcons(): array
    {
        $rows = Http::timeout(10)->connectTimeout(3)->get(self::SELFHST_ICONS.'index.json')->throw()->json();

        $icons = [];
        foreach ($rows ?? [] as $row) {
            $reference = (string) ($row['Reference'] ?? '');
            $extension = ($row['SVG'] ?? '') === 'Yes' ? 'svg' : (($row['PNG'] ?? '') === 'Yes' ? 'png' : null);
            if ($reference !== '' && $extension !== null) {
                $icons[self::key($reference)] ??= self::SELFHST_ICONS.$extension.'/'.$reference.'.'.$extension;
            }
        }

        return $icons;
    }
}
