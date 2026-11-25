<?php

namespace Tests\Feature;

use App\Casts\Ip;
use App\Services\IpAddressService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The cast `Ip` against a real table (SQLite or PostgreSQL, whichever the tests run on): a network of either family is one range of the column. */
class IpAddressQueryTest extends TestCase
{
    use RefreshDatabase;

    private function model(): Model
    {
        Schema::create('ip_cast_test', function ($table) {
            $table->id();
            $table->char('ip', 32)->index();
        });

        return new class extends Model
        {
            protected $table = 'ip_cast_test';

            public $timestamps = false;

            protected $guarded = [];

            protected $casts = ['ip' => Ip::class];
        };
    }

    public function test_the_addresses_are_found_by_network_of_either_family(): void
    {
        $model = $this->model();
        foreach (['192.168.1.1', '192.168.1.254', '192.168.2.1', '10.0.0.5', 'fe80::1', 'fe80::dead:beef', 'fe80:0:0:1::1', '2001:db8::1'] as $ip) {
            $model->newQuery()->create(['ip' => $ip]);
        }
        $in = fn (string $cidr) => IpAddressService::whereWithin($model->newQuery(), 'ip', $cidr)->orderBy('ip')->pluck('ip')->all();

        // What the model gives is the text, not the 32 characters.
        $this->assertSame(['192.168.1.1', '192.168.1.254'], $in('192.168.1.0/24'));
        $this->assertSame(['192.168.1.1', '192.168.1.254', '192.168.2.1'], $in('192.168.0.0/16'));
        $this->assertSame(['fe80::1', 'fe80::dead:beef'], $in('fe80::/64'));
        $this->assertSame([], $in('172.16.0.0/12'));
        // The family of the network decides: 0.0.0.0/0 is every IPv4 address and no IPv6 one.
        $this->assertCount(4, $in('0.0.0.0/0'));
        $this->assertSame([], $in('nonsense'));
        // One host.
        $this->assertSame(['10.0.0.5'], $in('10.0.0.5'));
    }

    public function test_the_column_holds_the_32_characters_and_sorts_by_the_address(): void
    {
        $model = $this->model();
        foreach (['10.0.0.10', '10.0.0.2', '9.255.255.255'] as $ip) {
            $model->newQuery()->create(['ip' => $ip]);
        }

        $this->assertSame('00000000000000000000ffff0a000002', \DB::table('ip_cast_test')->orderBy('ip')->skip(1)->value('ip'));
        $this->assertSame(['9.255.255.255', '10.0.0.2', '10.0.0.10'], $model->newQuery()->orderBy('ip')->pluck('ip')->all());
    }
}
