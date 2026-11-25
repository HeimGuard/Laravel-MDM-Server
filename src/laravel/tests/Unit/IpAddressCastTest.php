<?php

namespace Tests\Unit;

use App\Casts\IpAddress;
use App\Services\IpAddressService;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IpAddressCastTest extends TestCase
{
    public function test_an_address_is_stored_as_a_number_and_read_as_text(): void
    {
        $cast = new IpAddress;
        $model = new class extends Model {};

        $this->assertSame(3232235777, $cast->set($model, 'ip', '192.168.1.1', []));
        $this->assertSame('192.168.1.1', $cast->get($model, 'ip', 3232235777, []));
        // From the database it can be a string (a driver that returns the bigint as text).
        $this->assertSame('255.255.255.255', $cast->get($model, 'ip', '4294967295', []));
        $this->assertSame('0.0.0.0', $cast->get($model, 'ip', 0, []));
        $this->assertNull($cast->get($model, 'ip', null, []));
        $this->assertNull($cast->set($model, 'ip', null, []));
        $this->assertNull($cast->set($model, 'ip', '', []));
        // A number that is one already is kept.
        $this->assertSame(167772161, $cast->set($model, 'ip', 167772161, []));
    }

    public function test_what_is_no_ipv4_address_is_refused_not_stored_as_zero(): void
    {
        foreach (['fe80::1', '192.168.1', '256.1.1.1', '192.168.001.1', 'host', '10.0.0.0/8'] as $bad) {
            try {
                (new IpAddress)->set(new class extends Model {}, 'ip', $bad, []);
                $this->fail($bad.' was stored');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_range_of_a_network_is_the_first_and_the_last_address(): void
    {
        $this->assertSame([3232235776, 3232236031], IpAddressService::cidrToRange('192.168.1.0/24'));
        // The address inside the network gives its network.
        $this->assertSame([3232235776, 3232236031], IpAddressService::cidrToRange('192.168.1.77/24'));
        $this->assertSame([3232235777, 3232235777], IpAddressService::cidrToRange('192.168.1.1'));
        $this->assertSame([0, 4294967295], IpAddressService::cidrToRange('0.0.0.0/0'));
        $this->assertSame([167772160, 184549375], IpAddressService::cidrToRange('10.0.0.0/8'));
        $this->assertNull(IpAddressService::cidrToRange('192.168.1.0/33'));
        $this->assertNull(IpAddressService::cidrToRange('fe80::/64'));
        $this->assertNull(IpAddressService::cidrToRange('nonsense'));

        $this->assertTrue(IpAddressService::contains('192.168.1.0/24', '192.168.1.254'));
        $this->assertFalse(IpAddressService::contains('192.168.1.0/24', '192.168.2.1'));
        $this->assertFalse(IpAddressService::contains('bad', '192.168.2.1'));
    }
}
