<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A background job of the agent (Start-AgentJob) is another PowerShell process: it only has the
 * functions in its -Functions list, a function that is missing fails only there, at runtime, on the
 * device (Get-SecurityShortHash called Get-Sha256Hex, which the security job did not have, and the
 * web logs failed silently). This reads app.ps1 and checks that everything a job runs is in its list.
 */
class AgentJobFunctionsTest extends TestCase
{
    /** @var array<string, string> name => text of the function (from "function Name" to the "}" in the first column) */
    private array $functions = [];

    private string $agent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = (string) file_get_contents(dirname(__DIR__, 3).'/powershell/app.ps1');
        $this->functions = [];
        $lines = explode("\n", $this->agent);
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (! preg_match('/^function ([A-Za-z][A-Za-z0-9\-]*)/', $lines[$i], $matches)) {
                continue;
            }
            $end = $i + 1;
            while ($end < $count && ! str_starts_with($lines[$end], '}')) {
                $end++;
            }
            $this->functions[$matches[1]] = implode("\n", array_slice($lines, $i, $end - $i + 1));
            $i = $end;
        }
    }

    /** @return array<string, array{functions: array<int, string>, body: string}> job name => its -Functions list and script block */
    private function jobs(): array
    {
        $jobs = [];
        $pattern = "/Start-AgentJob -Name '(\\w+)' -Functions ([^\\n]*?) (?:-ArgumentList[^\\n]*?)?-ScriptBlock \\{/";
        preg_match_all($pattern, $this->agent, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($found as $match) {
            preg_match_all("/'([^']+)'/", $match[2][0], $names);
            // The script block: up to the brace that closes it.
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $end = $start;
            for ($length = strlen($this->agent); $end < $length && $depth > 0; $end++) {
                $depth += match ($this->agent[$end]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
            }
            $jobs[$match[1][0]] = ['functions' => $names[1], 'body' => substr($this->agent, $start, $end - $start)];
        }

        return $jobs;
    }

    /** The agent functions a text mentions (whole-line comments left out). @return array<int, string> */
    private function called(string $text): array
    {
        $text = (string) preg_replace('/^\s*#.*$/m', '', $text);

        return array_values(array_filter(
            array_keys($this->functions),
            fn (string $name) => preg_match('/(?<![\w\-])'.preg_quote($name, '/').'(?![\w\-])/', $text) === 1,
        ));
    }

    public function test_the_jobs_are_found(): void
    {
        $this->assertGreaterThanOrEqual(6, count($this->jobs()), 'inventory, health, security, scan, portscan and updates');
    }

    public function test_every_listed_function_exists(): void
    {
        foreach ($this->jobs() as $job => $parts) {
            foreach ($parts['functions'] as $name) {
                $this->assertArrayHasKey($name, $this->functions, "The $job job lists $name, which is not a function of app.ps1");
            }
        }
    }

    public function test_everything_a_job_runs_is_in_its_functions_list(): void
    {
        foreach ($this->jobs() as $job => $parts) {
            $listed = $parts['functions'];
            $missing = array_diff($this->called($parts['body']), $listed);
            $this->assertSame([], array_values($missing), "The script block of the $job job calls functions that are not in its -Functions list (they do not exist in the job): ".implode(', ', $missing));

            foreach ($listed as $name) {
                $missing = array_diff($this->called($this->functions[$name] ?? ''), $listed, [$name]);
                $this->assertSame([], array_values($missing), "$name (in the $job job) calls functions that are not in its -Functions list: ".implode(', ', $missing));
            }
        }
    }
}
