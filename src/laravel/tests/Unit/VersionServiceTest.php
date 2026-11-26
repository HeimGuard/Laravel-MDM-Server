<?php

namespace Tests\Unit;

use App\Services\VersionService;
use App\Support\SecurityCves;
use PHPUnit\Framework\TestCase;

class VersionServiceTest extends TestCase
{
    /** The same file as tests/version-vectors.json of detection-policy, which builds the ranges: change both. */
    public function test_the_order_of_the_shared_vectors(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/../fixtures/version-vectors.json'), true)['order'];
        $this->assertNotEmpty($vectors);

        foreach ($vectors as [$a, $op, $b]) {
            if ($op === 'null') {
                $this->assertTrue(VersionService::compare($a, $b) === null || VersionService::compare($b, $a) === null, "$a / $b");

                continue;
            }
            $want = ['<' => -1, '=' => 0, '>' => 1][$op];
            $this->assertSame($want, VersionService::compare($a, $b) <=> 0, "$a $op $b");
            $this->assertSame(-$want, VersionService::compare($b, $a) <=> 0, "$b reversed $op $a");
        }
    }

    public function test_a_package_of_a_distribution_is_judged_by_the_version_of_the_project(): void
    {
        // OpenSSL 1.1.1k of Ubuntu is below the 1.1.1w the CVE is fixed in, whatever the revision says.
        $cve = ['id' => 'CVE-2000-1', 'severity' => 'high', 'ranges' => [['1.1.1', '1.1.1w', null]]];
        $item = ['Name' => 'openssl', 'Source' => 'dpkg', 'Version' => '1.1.1k-1ubuntu1', 'Origin' => 'dpkg/status'];
        $this->assertArrayHasKey('CVE-2000-1', SecurityCves::affecting([$cve], $item, null));

        $fixed = ['Name' => 'openssl', 'Source' => 'dpkg', 'Version' => '1.1.1w-0+deb11u1', 'Origin' => 'dpkg/status'];
        $this->assertSame([], SecurityCves::affecting([$cve], $fixed, null));

        $rc = ['Name' => 'foo', 'Source' => 'dpkg', 'Version' => '2.0~rc1-1', 'Origin' => 'dpkg/status'];
        $this->assertArrayHasKey('CVE-2000-2', SecurityCves::affecting([['id' => 'CVE-2000-2', 'ranges' => [[null, '2.0', null]]]], $rc, null));
    }
}
