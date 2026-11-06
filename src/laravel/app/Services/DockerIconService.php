<?php

namespace App\Services;

/**
 * Icon of a container from the dashboard-icons database (https://github.com/homarr-labs/dashboard-icons),
 * served by the jsDelivr CDN. The icon is guessed from the image name, the page falls back to the next
 * candidate (and at last to the Docker icon) when the CDN has no such file.
 */
class DockerIconService
{
    public const CDN = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/svg/';

    /** Image names (last path segment) whose icon has another name. */
    private const ALIASES = [
        'postgres' => 'postgresql',
        'mongo' => 'mongodb',
    ];

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

    /** URLs of the candidates, in the order they are tried. @return list<string> */
    public static function urls(string $image): array
    {
        return array_map(fn ($name) => self::CDN.$name.'.svg', self::candidates($image));
    }
}
