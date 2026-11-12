# Development: where things are, how to test

A map of the code for people and for an AI working on it, so a change starts in the right file. The rules for
changes are in [`CLAUDE.md`](../CLAUDE.md), the data model in [data-model.md](data-model.md).

## Map of the code

| Area | Where |
|---|---|
| Agent (one PowerShell 7 script, Windows and Linux) | `src/powershell/app.ps1`, version in `$AgentVersion` (line ~137), read by `App\Support\AgentScript::version()`; names of the installation in `$AgentName`, `$AgentService`, `$DefaultAgentDir` (HeimGuard; `$Legacy*` = Laravel-MDM of agents before 1.32.0, moved by `Start-AgentMigration` / `Complete-AgentMigration`) |
| Background jobs of the agent | `Start-AgentJob -Name inventory / health / security / scan / portscan / updates -Functions ...`: a function a job calls must be in its `-Functions` list |
| Agent API (signed requests) | `src/laravel/routes/api.php`: `/device` (report), `/device/heartbeat`, `/device/commands/take`, `/device/commands/{id}` (progress, result), `/device/security`, `/device/scripts` |
| Device model | `app/Models/Device.php`: platform, type, updates (`updates`, `apps_packages_updates`, `moduleUpdates`), `*_VERSION` constants that gate commands |
| Commands for the agent | `app/Models/DeviceCommand.php`: `STATUSES` (`queued` → `sent` → `running` → `succeeded` / `failed` / `expired` ...), `ACTIVE`, `TIMEOUTS`, `targetOf()` (`installUpdate` target `kind:edition:id:user`, e.g. `winget::Git.Git:`), `statusLabel`, `label` |
| Update rows | `Device::updateTarget()` (the `installUpdate` params of a row), `updateGroupKey()` (`apt`, `windows`, `winget`, `snap`, `powershell` ...), `updateRowState()` (a row while `doUpdates` runs: `queued` / `running` / `done`) |
| Device page | `app/Livewire/DeviceDetail.php` + `resources/views/livewire/device-detail.blade.php` (tabs, updates list); one row's button and its state: `resources/views/partials/device/update-button.blade.php`; a command's progress bar: `partials/device/command-progress.blade.php` |
| Commands menu, alerts | `app/Livewire/DeviceCommands.php`, `DeviceAlerts.php`, `App\Support\SmartAlerts` |
| Security scanner | `App\Support\SecurityScanner`, `SecurityRules` (sources and fields of the inventory), `SecurityCves`, `SecurityFeed`; findings `App\Models\SecurityFinding`, inventory `SecurityInventory` (`items('os')`, `items('posture')`) |
| Findings UI | `app/Livewire/DeviceSecurity.php`, `SecurityScan/Page.php`, shared `Concerns/RemediatesFindings.php` (Remediate / Explain with AI); one finding: `resources/views/livewire/security-scan/partials/finding.blade.php` |
| AI | `App\Services\AiService` (provider, `available()`, `ask()`), `ScriptGeneratorService` (remediation scripts), `FindingExplanationService` (`explain()`, `describe()`: what is sent about a finding, `SAFE_FIELDS`); settings in [remediation-scripts.md](remediation-scripts.md) |
| First user | `AuthController::login` redirects to `/setup` while the `users` table is empty (checked on every request) |
| Translations | `src/laravel/lang/{cs,de,es,fr,it}.json`, every UI text in all of them (`LocaleTest`) |

## The updates of a device

- **Install all** issues one `doUpdates` command. The agent's `updates` job works in this order: apt / snap /
  flatpak / pip on Linux, winget, Google Chrome and Windows Update on Windows, then the PowerShell 7 release
  (`Install-PowerShellRelease`), then the PowerShell modules (`Invoke-PowerShellModules`, each edition in its own
  `pwsh` / `powershell.exe` process). It reports `current` and `done` (group or row targets), which
  `updateRowState()` turns into Queued / Installing / Done.
- **Update** on a row issues one `installUpdate` command with the row's target. The row shows the same Queued /
  Installing (with percent) as a row of Install all; the command and its state are in the tooltip.
- A new PowerShell release is not started before the restart that finishes its MSI (agents 1.31.2+); the modules
  wait for the next run.

## Tests

- `cd src/laravel && php artisan test` (PHPUnit, SQLite in memory); CI (`.github/workflows/docker.yml`, PHP 8.4)
  runs them on every push and pull request and builds the image only when they pass.
- Also PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=... php artisan test` (see `CLAUDE.md` for what only PostgreSQL rejects).
- The PowerShell check of the scripts (`App\Support\PowerShellCheck`) uses `pwsh` when there is one (`mdm.pwsh`),
  otherwise a lighter check in PHP; the agent itself is never run by the tests.
- The cloud sessions of Claude have PHP 8.3 and no access to `git.steelants.cz` (SteelAnts packages), so
  `composer install` fails there: the tests run only in CI. Push, then read the job log of the `tests` check.

Things that have broken tests before:

- **Livewire tests rethrow exceptions.** A `findOrFail` of another device's record is a `ModelNotFoundException`
  in the test (`assertThrows`), not a 404 response; `abort(403)` is `assertForbidden()`.
- **`ModelNotFoundException` is a `RuntimeException`.** A `catch (RuntimeException)` around a service call (the AI
  services throw it for the user's message) also catches a missing record: look the record up before the `try`.
- **Seeded rows.** Some tables have rows after the migrations (scripts): count before and after, never expect 0.
- **The agent version moves.** A test device with an outdated `AgentVersion` gets an "agent outdated" alert; use
  `AgentScript::version()` where the test does not care.
- **Blade:** prefer one `{{ ... }}` expression over an `@if` written inline right after `{{ }}` for a piece of a
  text (a percent after a label).
- A view test that looks for a text (`assertSee`) breaks when the text moves into an attribute; assert the tooltip
  (`title`) then.
