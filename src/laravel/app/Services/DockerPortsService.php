<?php

namespace App\Services;

/**
 * The ports of a container for the table, short: only those published to the outside (a host port mapped to the container, "->"
 * in what Docker says), by the host port. The ports that are only inside the container are not listed.
 *
 *   0.0.0.0:8080->80/tcp, [::]:8080->80/tcp, 6379/tcp   =>   ["8080"]
 *   0.0.0.0:53->53/udp                                  =>   ["53/udp"]
 *   127.0.0.1:5432->5432/tcp                            =>   ["127.0.0.1:5432"]
 */
class DockerPortsService
{
    /** Addresses that mean every interface: they are not shown. */
    private const ANY = ['', '0.0.0.0', '::', '[::]'];

    /** @return array<int, string> */
    public static function published(?string $ports): array
    {
        $labels = [];
        foreach (explode(',', (string) $ports) as $entry) {
            $entry = trim($entry);
            if (! str_contains($entry, '->')) {
                continue;
            }
            [$host, $container] = explode('->', $entry, 2);
            $split = strrpos($host, ':');
            $address = $split === false ? '' : substr($host, 0, $split);
            $port = $split === false ? $host : substr($host, $split + 1);
            if (! preg_match('/^\d+(-\d+)?$/', $port)) {
                continue;
            }
            $protocol = str_contains($container, '/') ? substr($container, strrpos($container, '/') + 1) : 'tcp';
            $label = (in_array($address, self::ANY, true) ? '' : $address.':').$port.($protocol === 'tcp' ? '' : '/'.$protocol);
            // The same port on IPv4 and IPv6 is one.
            $labels[$label] = true;
        }

        // A port is a number, so PHP makes the keys integers: the labels are texts.
        return array_map('strval', array_keys($labels));
    }
}
