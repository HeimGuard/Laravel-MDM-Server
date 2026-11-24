<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The SNMP code of the agent (BER, SNMP v1 and v2c messages), run by pwsh against bytes made by another encoder: a request has to be
 * the bytes a device understands, an answer has to be read into the lines the server reads. Skipped without pwsh (the runners of GitHub
 * have it). The functions are taken from app.ps1 as they are, from ConvertTo-BerLength to Invoke-LanProbe; the walk itself (a UDP
 * socket) is not run.
 */
class AgentSnmpCodecTest extends TestCase
{
    /** GetBulk, SNMP v2c, community public, request id 200 (a number with the high bit set), 1.3.6.1.2.1.1.5, 20 repetitions. */
    private const BULK = '302602010104067075626c6963a519020200c8020100020114300d300b06072b0601020101050500';

    /** GetNext, SNMP v1. */
    private const NEXT = '302602010004067075626c6963a119020200c8020100020100300d300b06072b0601020101050500';

    /** A v2c answer: a string, an integer, a Counter32 above 2^31, a Gauge32 and an OID of a vendor (8072 takes 2 bytes), a negative integer, endOfMibView, an empty string. */
    private const RESPONSE = '30819502010104067075626c6963a28187020200c8020100020100307b301206082b060102010105000406726f75746572300f060a2b0601020102020108010201013013060a2b060102010202010b01410500ffffffff3011060b2b06010401bf0803020a00420203e83010060b2b060102011f0101010f010201fe300c06082b060102010106008200300c06082b060102010107000400';

    /** An SNMP v1 answer with the error noSuchName (2): how v1 ends a walk. */
    private const NO_SUCH_NAME = '302702010004067075626c6963a21a020200c8020102020101300e300c06082b060102010105000500';

    private function pwsh(string $driver): array
    {
        $pwsh = getenv('MDM_PWSH') ?: (new ExecutableFinder)->find('pwsh');
        if (! $pwsh) {
            $this->markTestSkipped('pwsh is not installed.');
        }
        $agent = (string) file_get_contents(dirname(__DIR__, 3).'/powershell/app.ps1');
        $start = strpos($agent, 'function ConvertTo-BerLength');
        $end = strpos($agent, 'function Invoke-LanProbe');
        $this->assertNotFalse($start);
        $this->assertGreaterThan($start, $end);

        $base = tempnam(sys_get_temp_dir(), 'snmp');
        $script = $base.'.ps1';
        file_put_contents($script, "\$ErrorActionPreference = 'Stop'\n".substr($agent, $start, $end - $start)."\n".$driver);
        try {
            $process = new Process([$pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $script]);
            $process->setTimeout(60)->run();
        } finally {
            @unlink($script);
            @unlink($base);
        }
        $this->assertTrue($process->isSuccessful(), "pwsh failed:\n".$process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_requests_are_the_bytes_a_device_understands(): void
    {
        $out = $this->pwsh(<<<'PS'
function ToHex([byte[]]$Bytes) { return [System.BitConverter]::ToString($Bytes).Replace('-', '').ToLowerInvariant() }
[ordered]@{
    bulk = ToHex (New-SnmpRequest -Version 1 -Community 'public' -RequestId 200 -Oid '1.3.6.1.2.1.1.5' -Repetitions 20)
    next = ToHex (New-SnmpRequest -Version 0 -Community 'public' -RequestId 200 -Oid '1.3.6.1.2.1.1.5' -Repetitions 20)
} | ConvertTo-Json -Compress
PS);

        $this->assertSame(self::BULK, $out['bulk']);
        $this->assertSame(self::NEXT, $out['next']);
    }

    public function test_an_answer_is_read_into_the_lines_the_server_reads(): void
    {
        $out = $this->pwsh('$answer = ConvertFrom-SnmpResponse -Bytes ([Convert]::FromHexString(\''.self::RESPONSE.'\')) -Version 1
[ordered]@{
    id = $answer.Id
    items = @($answer.Items | ForEach-Object { [ordered]@{ oid = $_.Oid; value = $_.Value; end = $_.End } })
} | ConvertTo-Json -Compress -Depth 4');

        $this->assertSame(200, $out['id']);
        $this->assertSame([
            ['1.3.6.1.2.1.1.5.0', 'x:726f75746572', false],
            ['1.3.6.1.2.1.2.2.1.8.1', '1', false],
            ['1.3.6.1.2.1.2.2.1.11.1', '4294967295', false],
            ['1.3.6.1.4.1.8072.3.2.10.0', '1000', false],
            ['1.3.6.1.2.1.31.1.1.1.15.1', '-2', false],
            ['1.3.6.1.2.1.1.6.0', '', true],
            ['1.3.6.1.2.1.1.7.0', 'x:', false],
        ], array_map(fn (array $item) => [$item['oid'], $item['value'], $item['end']], $out['items']));
    }

    public function test_snmp_v1_ends_a_walk_with_no_such_name_and_v2c_fails_on_an_error(): void
    {
        $out = $this->pwsh('$bytes = [Convert]::FromHexString(\''.self::NO_SUCH_NAME.'\')
$v1 = ConvertFrom-SnmpResponse -Bytes $bytes -Version 0
$message = $null
try { [void](ConvertFrom-SnmpResponse -Bytes $bytes -Version 1) } catch { $message = "$($_.Exception.Message)" }
[ordered]@{ id = $v1.Id; count = @($v1.Items).Count; v2c = $message } | ConvertTo-Json -Compress');

        $this->assertSame([200, 0, 'SNMP error 2'], [$out['id'], $out['count'], $out['v2c']]);
    }
}
