<?php

namespace Tests\Unit;

use App\Support\AptUpdate;
use PHPUnit\Framework\TestCase;

/** B1: the problems of `apt-get update` from its output. */
class AptUpdateTest extends TestCase
{
    private const G6 = <<<'TXT'
Err:7 http://deb.anydesk.com all InRelease
  The following signatures couldn't be verified because the public key is not available: NO_PUBKEY 1234ABCD
Ign:9 https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute InRelease
Err:10 https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release
  404  Not Found [IP: 185.125.190.80 443]
Reading package lists...
W: GPG error: http://deb.anydesk.com all InRelease: The following signatures couldn't be verified because the public key is not available: NO_PUBKEY 1234ABCD
E: The repository 'http://deb.anydesk.com all InRelease' is not signed.
N: Updating from such a repository can't be done securely, and is therefore disabled by default.
E: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute Release' does not have a Release file.
N: See apt-secure(8) manpage for repository creation and user configuration details.
Notice: Skipping acquire of configured file 'main/binary-i386/Packages' as repository 'http://archive.ubuntu.com/ubuntu resolute InRelease' doesn't support architecture 'i386'
TXT;

    public function test_the_unsigned_and_the_release_less_repository_of_g6_are_found(): void
    {
        $problems = AptUpdate::problems(self::G6);

        $this->assertContains(['repository' => 'http://deb.anydesk.com', 'type' => AptUpdate::UNSIGNED], $problems);
        $this->assertContains(['repository' => 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu', 'type' => AptUpdate::NO_RELEASE], $problems);
    }

    public function test_the_architecture_notice_is_not_an_error(): void
    {
        $notice = "Notice: Skipping acquire of configured file 'main/binary-i386/Packages' as repository 'http://archive.ubuntu.com/ubuntu noble InRelease' doesn't support architecture 'i386'\nW: Skipping acquire of configured file 'x' as repository 'http://r noble InRelease' doesn't support architecture 'i386'";

        $this->assertSame([], AptUpdate::problems($notice));
        $this->assertSame([], AptUpdate::problems(''));
        $this->assertSame([], AptUpdate::problems(null));
    }

    public function test_an_unreachable_repository(): void
    {
        $problems = AptUpdate::problems("Err:3 https://repo.example.org/apt stable InRelease\n  Could not resolve 'repo.example.org'\nW: Failed to fetch https://repo.example.org/apt/dists/stable/InRelease  Could not resolve 'repo.example.org'");

        $this->assertContains(['repository' => 'https://repo.example.org/apt', 'type' => AptUpdate::UNREACHABLE], $problems);
    }

    public function test_advice_depends_on_the_kind_of_problem(): void
    {
        $unsigned = AptUpdate::advice(['repository' => 'http://deb.anydesk.com', 'type' => AptUpdate::UNSIGNED]);
        $this->assertStringContainsString('Signed-By', $unsigned);

        $ppa = AptUpdate::advice(['repository' => 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu', 'type' => AptUpdate::NO_RELEASE]);
        $this->assertStringContainsString('sudo add-apt-repository --remove ppa:ondrej/php', $ppa);
    }

    public function test_the_fields_of_the_finding_say_the_cve_lists_may_be_out_of_date(): void
    {
        $fields = AptUpdate::fields(self::G6);

        $this->assertStringContainsString('deb.anydesk.com', $fields['AptUpdateSummary']);
        $this->assertStringContainsString('ondrej/php', $fields['AptUpdateSummary']);
        $this->assertStringContainsString('out of date', $fields['Remediation']);
    }
}
