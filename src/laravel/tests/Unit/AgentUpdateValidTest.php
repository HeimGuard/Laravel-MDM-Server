<?php

namespace Tests\Unit;

use App\Support\PowerShellCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The agent checks the script it downloads before it takes it (Update-Agent): the PowerShell parser must find no error and the
 * script must have `function Start-Agent`, otherwise the update is refused ("the downloaded agent is not valid") and the device stays on
 * its old version. A script that does not parse never reaches a device, but it blocks every update until a fixed one is released
 * (a typed `foreach ([int]$v in ...)` did). This runs the same check on app.ps1 before it is merged: with the parser of pwsh when there
 * is one (the runners of GitHub have it), and always with the checks below that need no PowerShell.
 */
class AgentUpdateValidTest extends TestCase
{
    private function agent(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/powershell/app.ps1');
    }

    public function test_the_agent_parses_with_the_powershell_parser_like_update_agent_checks_it(): void
    {
        $pwsh = getenv('MDM_PWSH') ?: (new ExecutableFinder)->find('pwsh');
        if (! $pwsh) {
            $this->markTestSkipped('pwsh is not installed: the parser of PowerShell is not available.');
        }
        // The same call as Update-Agent: ParseInput of the text, the errors must be empty.
        $command = '$text = [Console]::In.ReadToEnd(); $errors = $null; '
            .'[void][System.Management.Automation.Language.Parser]::ParseInput($text, [ref]$null, [ref]$errors); '
            .'foreach ($e in $errors) { "line $($e.Extent.StartLineNumber): $($e.Message)" }';
        $process = new Process([$pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $command]);
        $process->setInput($this->agent())->setTimeout(60)->run();

        $this->assertTrue($process->isSuccessful(), 'pwsh failed: '.$process->getErrorOutput());
        $this->assertSame('', trim($process->getOutput()), "app.ps1 does not parse, the agents would refuse the update:\n".$process->getOutput());
    }

    public function test_the_agent_has_what_update_agent_wants_to_see(): void
    {
        $agent = $this->agent();

        $this->assertMatchesRegularExpression('/(?m)^function Start-Agent/', $agent);
        $this->assertMatchesRegularExpression("/(?m)^\\\$AgentVersion = '\\d+\\.\\d+\\.\\d+'/", $agent);
    }

    /** Brackets, strings, here-strings and block comments pair up (the light check the server also uses for the scripts, no pwsh needed). */
    public function test_the_structure_of_the_agent_is_sound(): void
    {
        $this->assertSame([], PowerShellCheck::lex($this->agent())['errors']);
    }

    /** `foreach ([int]$v in $list)` does not parse in PowerShell ("Missing variable name after foreach"): type a copy in the body. */
    public function test_no_foreach_variable_is_typed(): void
    {
        $found = [];
        foreach (explode("\n", $this->agent()) as $number => $line) {
            if (preg_match('/^\s*foreach\s*\(\s*\[/i', $line)) {
                $found[] = 'app.ps1 line '.($number + 1).': '.trim($line);
            }
        }

        $this->assertSame([], $found, "The variable of a foreach cannot be typed:\n".implode("\n", $found));
    }
}
