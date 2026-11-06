<?php

namespace Tests\Unit;

use App\Services\DockerIconService;
use PHPUnit\Framework\TestCase;

class DockerIconServiceTest extends TestCase
{
    private const INDEX = [
        'dashboard-icons' => ['pihole' => 'D/svg/pi-hole.svg', 'dockhand' => 'D/png/dockhand.png', 'zabbix' => 'D/svg/zabbix.svg'],
        'selfh.st' => ['pihole' => 'S/svg/pi-hole.svg', 'bookstack' => 'S/svg/bookstack.svg'],
    ];

    public function test_names_are_compared_without_punctuation(): void
    {
        $this->assertSame(['D/svg/pi-hole.svg', 'S/svg/pi-hole.svg'], DockerIconService::match(self::INDEX, 'pihole/pihole:latest'));
    }

    public function test_a_file_that_is_only_a_png_is_used_as_it_is(): void
    {
        $this->assertSame(['D/png/dockhand.png'], DockerIconService::match(self::INDEX, 'fnsys/dockhand:latest'));
    }

    public function test_the_first_word_of_the_name_is_tried_after_the_whole_name(): void
    {
        $this->assertSame(['D/svg/zabbix.svg'], DockerIconService::match(self::INDEX, 'zabbix/zabbix-web-nginx-mysql:alpine-trunk'));
    }

    public function test_the_registry_and_the_tag_are_ignored(): void
    {
        $this->assertSame(['S/svg/bookstack.svg'], DockerIconService::match(self::INDEX, 'lscr.io/linuxserver/bookstack:latest'));
        $this->assertSame(['S/svg/bookstack.svg'], DockerIconService::match(self::INDEX, 'localhost:5000/bookstack'));
    }

    public function test_an_image_without_an_icon_has_no_url(): void
    {
        $this->assertSame([], DockerIconService::match(self::INDEX, 'alpine'));
    }

    public function test_the_stored_file_is_named_by_its_source(): void
    {
        $name = DockerIconService::fileName('https://cdn.jsdelivr.net/gh/selfhst/icons/png/dockhand.png');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.png$/', $name);
        $this->assertNotSame($name, DockerIconService::fileName('https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/dockhand.png'));
    }
}
