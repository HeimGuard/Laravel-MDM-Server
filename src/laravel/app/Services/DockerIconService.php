<?php

namespace App\Services;

/**
 * Icon of a container from the dashboard-icons (https://github.com/homarr-labs/dashboard-icons) and
 * selfh.st/icons (https://selfh.st/icons) databases, served by the jsDelivr CDN. The icon is guessed from the image name, the page falls back to the next
 * candidate (and at last to the Docker icon) when the CDN has no such file.
 */
class DockerIconService
{
    /** Icon sources in the order they are tried for a name (all served by jsDelivr). */
    public const SOURCES = [
        'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/svg/', // dashboard-icons (the one Dozzle uses)
        'https://cdn.jsdelivr.net/gh/selfhst/icons/svg/',               // selfh.st/icons
    ];

    /** Image names (last path segment) whose icon has another name. */
    private const ALIASES = [
        'postgres' => 'postgresql',
        'mongo' => 'mongodb',
        'pihole' => 'pi-hole',
        'adguardhome' => 'adguard-home',
        'homeassistant' => 'home-assistant',
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

    /** URLs to try, in order: each candidate name in every source, the most specific name first. @return list<string> */
    public static function urls(string $image): array
    {
        $urls = [];
        foreach (self::candidates($image) as $name) {
            foreach (self::SOURCES as $source) {
                $urls[] = $source.$name.'.svg';
            }
        }

        return $urls;
    }
}
