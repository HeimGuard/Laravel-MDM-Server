<?php

namespace App\Services;

use App\Jobs\RefreshDockerIcons;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Icon of a container from the dashboard-icons (https://github.com/homarr-labs/dashboard-icons) and
 * selfh.st/icons (https://selfh.st/icons) databases.
 *
 * Nothing is loaded from the CDN by the browser: the index of the databases and the icons of the images
 * the devices run are downloaded by `refresh()` (job RefreshDockerIcons, `php artisan mdm:docker-icons`) into
 * the cache (CACHE_STORE, whatever store is chosen there) and served by the application (route docker-icon).
 * The names are compared without case and punctuation, so the image `pihole` finds `pi-hole`; a file that
 * exists only as a PNG is used as it is. An image without a stored icon shows the Docker icon, and a missing
 * icon (new image, cleared cache) asks for a refresh.
 */
class DockerIconService
{
    private const DASHBOARD_ICONS = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/';

    private const SELFHST_ICONS = 'https://cdn.jsdelivr.net/gh/selfhst/icons/';

    private const INDEX_KEY = 'docker-icons:index';

    private const REQUESTED_KEY = 'docker-icons:requested';

    /** A refresh asked for by a missing icon is not asked again for this many seconds. */
    private const REQUEST_EVERY = 600;

    private const MAX_BYTES = 1_000_000;

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

    /**
     * URLs (of this application) of the icons of the image that are stored, the most specific name first.
     * Empty: no icon (the Docker icon stays). An icon that exists in the databases but is not stored yet
     * asks for a refresh.
     *
     * @return list<string>
     */
    public static function urls(string $image): array
    {
        $index = self::index();
        $urls = [];
        $missing = $index === [];
        foreach (self::match($index, $image) as $remote) {
            if (self::file(self::fileName($remote)) !== null) {
                $urls[] = route('docker-icon', ['file' => self::fileName($remote)]);
            } else {
                $missing = true;
            }
        }
        if ($missing) {
            self::requestRefresh();
        }

        return $urls;
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

    /** `Pi-Hole`, `pihole` and `pi_hole` are the same icon. */
    private static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    /** Name of the stored file of an icon (the URL it comes from, so a changed source is a new file). */
    public static function fileName(string $remote): string
    {
        return sha1($remote).'.'.pathinfo($remote, PATHINFO_EXTENSION);
    }

    /** The stored icon: ['type' => 'image/svg+xml', 'body' => ...], null when it is not stored. */
    public static function file(string $file): ?array
    {
        $stored = Cache::get('docker-icons:file:'.$file);

        return is_array($stored) && isset($stored['type'], $stored['body']) && ($body = base64_decode($stored['body'], true)) !== false
            ? ['type' => $stored['type'], 'body' => $body]
            : null;
    }

    /** The names of the icons of both databases with the URL of each file; empty before the first refresh. @return array<string, array<string, string>> */
    public static function index(): array
    {
        $index = self::$index ?? Cache::get(self::INDEX_KEY);

        return self::$index = is_array($index) ? $index : [];
    }

    /** Asks the queue for a refresh, at most once in a while (an unreachable CDN must not make every page load try). */
    private static function requestRefresh(): void
    {
        if (Cache::add(self::REQUESTED_KEY, 1, self::REQUEST_EVERY)) {
            RefreshDockerIcons::dispatch();
        }
    }

    /**
     * Reads the index of both databases and stores the icons of the images the devices run.
     * $force downloads the icons that are stored again too (the databases fix and change their files).
     * The index of a database that cannot be read is kept as it was.
     *
     * @return array{icons: int, downloaded: int, failed: list<string>}
     */
    public static function refresh(bool $force = false): array
    {
        $failed = [];
        $old = Cache::get(self::INDEX_KEY);
        $old = is_array($old) ? $old : [];
        $index = [];
        foreach (['dashboard-icons' => fn () => self::dashboardIcons(), 'selfh.st' => fn () => self::selfhstIcons()] as $source => $read) {
            try {
                $index[$source] = $read();
            } catch (\Throwable $e) {
                $failed[] = "$source: ".$e->getMessage();
                $index[$source] = $old[$source] ?? [];
            }
        }
        $index = array_filter($index);
        if ($index !== []) {
            Cache::forever(self::INDEX_KEY, $index);
        }
        self::$index = $index;

        $downloaded = 0;
        $wanted = [];
        foreach (self::usedImages() as $image) {
            foreach (self::match($index, $image) as $remote) {
                $wanted[$remote] = true;
            }
        }
        foreach (array_keys($wanted) as $remote) {
            $file = self::fileName($remote);
            if (! $force && self::file($file) !== null) {
                continue;
            }
            try {
                $response = Http::timeout(15)->connectTimeout(3)->get($remote)->throw();
                $body = $response->body();
                if ($body === '' || strlen($body) > self::MAX_BYTES) {
                    throw new \RuntimeException('empty or too large');
                }
                Cache::forever('docker-icons:file:'.$file, ['type' => str_ends_with($remote, '.svg') ? 'image/svg+xml' : 'image/png', 'body' => base64_encode($body)]);
                $downloaded++;
            } catch (\Throwable $e) {
                $failed[] = "$remote: ".$e->getMessage();
            }
        }

        return ['icons' => count($wanted), 'downloaded' => $downloaded, 'failed' => $failed];
    }

    /** Images of the containers of all devices. @return list<string> */
    private static function usedImages(): array
    {
        $images = [];
        Device::query()->each(function (Device $device) use (&$images) {
            foreach ($device->docker['containers'] ?? [] as $container) {
                if (($image = (string) ($container['Image'] ?? '')) !== '') {
                    $images[$image] = true;
                }
            }
        });

        return array_keys($images);
    }

    /** @return array<string, string> */
    private static function dashboardIcons(): array
    {
        $tree = Http::timeout(15)->connectTimeout(3)->get(self::DASHBOARD_ICONS.'tree.json')->throw()->json();

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
        $rows = Http::timeout(15)->connectTimeout(3)->get(self::SELFHST_ICONS.'index.json')->throw()->json();

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
