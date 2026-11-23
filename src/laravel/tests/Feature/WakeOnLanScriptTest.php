<?php

namespace Tests\Feature;

use App\Models\Script;
use App\Support\PowerShellCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The remediation script every installation starts with that makes a Windows PC wake on a magic packet. */
class WakeOnLanScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_installations_start_with_a_script_that_enables_wake_on_lan_on_windows(): void
    {
        $script = Script::query()->where('name', 'Enable Wake-on-LAN (Windows)')->firstOrFail();

        // Detection only on a run: the remediation waits for Remediate.
        $this->assertTrue($script->manual_remediation);
        $this->assertSame('windows', $script->platform);
        $this->assertSame($script->fingerprint, Script::fingerprintOf('windows', 120, $script->detection, $script->remediation));
        // It passes the checks of the script form.
        $this->assertSame([], PowerShellCheck::errors($script->detection));
        $this->assertSame([], PowerShellCheck::errors($script->remediation));
        $this->assertTrue(PowerShellCheck::exits($script->detection, 0));
        $this->assertTrue(PowerShellCheck::exits($script->detection, 1));
        $this->assertStringContainsString('Set-NetAdapterPowerManagement', $script->remediation);
        // Fast Startup stays as it is: Turn off of the agent is a hybrid shutdown.
        $this->assertStringNotContainsString('HiberbootEnabled', $script->remediation);
    }
}
