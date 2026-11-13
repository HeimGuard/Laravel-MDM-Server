# Development: where things are, how to test

A map of the code for people and for an AI working on it, so a change starts in the right file. The rules for
changes are in [`CLAUDE.md`](../CLAUDE.md), the data model in [data-model.md](data-model.md).

## Map of the code

| Area | Where |
|---|---|
| Agent (one PowerShell 7 script, Windows and Linux) | `src/powershell/app.ps1`, version in `$AgentVersion` (line ~137), read by `App\Support\AgentScript::version()`; names of the installation in `$AgentName`, `$AgentService`, `$DefaultAgentDir` (HeimGuard; `$Legacy*` = Laravel-MDM of agents before 1.32.0, moved by `Start-AgentMigration` / `Complete-AgentMigration`) |
| Background jobs of the agent | `Start-AgentJob -Name inventory / health / security / scan / portscan / updates -Functions ...`: a function a job calls must be in its `-Functions` list (`tests/Unit/AgentJobFunctionsTest.php` reads `app.ps1` and fails when one is missing; not in a list: `Write-AgentLog`, `Write-AgentFile`, anything that uses `$script:` state of the agent). A collection that runs for hours raises an alert (`Test-JobOverdue`) |
| State files of the agent | `Write-AgentFile` (temporary file moved over the file; `-Protect` for keys and tokens): config.json, the caches, backlogs and state files are never half written. Jobs write their own result files with plain `Set-Content` |
| Agent API contract (what is sent, stored and shown, per endpoint and command) | [agent-api.md](agent-api.md) |
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

## UI components

Anonymous Blade components in `src/laravel/resources/views/components`. Use them instead of writing the markup again;
other attributes (`class`, `title`, `wire:*`, `x-*`) are passed to the outer element. New texts in them need the
translations like any other text.

| Component | Props | When |
|---|---|---|
| `<x-icon-tile icon="fas fa-robot" tone="primary" />` | `icon`, `tone` (a Bootstrap colour, `null` = neutral grey), `size` (`lg`) | The square icon before the title of a row, a card or an alert (`.icon-tile`) |
| `<x-notice type="danger">...</x-notice>` | `type` (`danger`, `warning`, `info`, `success`), `icon` (default of the type, `false` for none) | A message inside a page: the error of an action, a hint, a warning about the data. Small, the icon on the left; `role="alert"` for `danger`, otherwise `status` |
| `<x-search-input x-model="search" />` | `width` (max width, `20rem`; `null` for none); `placeholder` (default `Search`, also the `aria-label`) | The search field above a list or a table |
| `<x-empty-state>...</x-empty-state>` | `icon`, `colspan` (a table row instead of a `list-group-item`) | The row of an empty list or table, grey and centred |
| `<x-status-label icon="far fa-clock">...</x-status-label>` | `icon`, `tone` (`muted`, `primary`, `success`, ...), `spinner`, `compact` (on phones only the icon: give a `title`) | Where something stands: Queued, Installing 40 %, Done, Connected |
| `<x-button icon="fas fa-download" wire:click="...">Label</x-button>` | `icon`, `variant` (`light`, `primary`, `outline-primary`, ...), `tone` (icon colour, `null` = none), `compact` (label only from `sm` up: give a `title`), `loading` (the `wire:target`: a spinner replaces the icon while it runs), `busy` (the same spinner for an action that is already running), `href` (renders an `<a>`) | The small button with an icon and a label (`btn btn-sm`) |
| `<x-card-header icon="fas fa-robot" tone="primary" title="Agent">actions</x-card-header>` | `icon`, `tone`, `title` and `subtitle` (a text or `<x-slot:title>` for markup), `tag` (`h2`, ... makes the title a small heading) | The header of a card or section: tile, bold title, subtitle, and the actions on the right in the default slot. On phones the actions wrap below the title |
| `<x-list-row icon="fas fa-globe" tone="primary" title="..." subtitle="...">` | `icon`, `tone`, `title` (a text or `<x-slot:title>` for a title with a badge), `subtitle`, `icon-title`; slots: default (details), `actions` (right) | A row of a `<ul class="list-group">`: tile, title, grey subtitle, details, actions. Not where the buttons have to go under the text on phones (the finding row, smart alerts keep their own layout) |

Also `x-badge` (`color`, `variant="subtle"`, `size`, `icon`), `x-stat`, `x-tag` / `x-tags`, `x-mac`.

Rules for the look:
- Grey secondary text is `text-body-secondary` everywhere. `text-muted` is not used: the theme gives it the fixed colour
  `#a7abc3`, while `text-body-secondary` follows the light and dark theme.
- A button hides its label on phones (`compact`) only in a row of a table or list; toolbar and page-header buttons keep it,
  and buttons next to each other behave the same. The icon of a light button has no tint, except the primary action of a
  row (Update, Install: `tone="primary"`).
- A spinner next to a label is `spinner-border spinner-border-sm me-1` (`me-sm-1` for a compact label); `x-button` and
  `x-status-label` render their own.

## Tests

- `cd src/laravel && php artisan test` (PHPUnit, SQLite in memory); CI (`.github/workflows/docker.yml`, PHP 8.4)
  runs them on every push and pull request and builds the image only when they pass.
- **`php artisan test` ignores `--exclude-group` / `--group`**: to filter by group call `vendor/bin/phpunit` directly.
- Also PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=... php artisan test` (see `CLAUDE.md` for what only PostgreSQL rejects).
- CI jobs (`.github/workflows/docker.yml`, all call `vendor/bin/phpunit`): `tests` (SQLite in a file, migrated once per
  process, `--exclude-group=slow`), `slow` (`--group=slow`: `SecurityScanPerformanceTest`, in parallel), `tests-pgsql`
  (PostgreSQL 17, `--exclude-group=slow,fresh-db`, `continue-on-error`: it does not block yet), `lint` (`php -l` of
  all PHP files, `pint --test` non-blocking) and `build` (needs `tests`, `slow`, `lint`, not `tests-pgsql`).
- Locally: `vendor/bin/phpunit --group=slow` for the slow group, `vendor/bin/phpunit --exclude-group=slow` without it;
  PostgreSQL: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit --exclude-group=slow,fresh-db`.
  A test that asserts the SQLite driver or changes the schema (the SQLite import, a dropped column) gets
  `#[Group('fresh-db')]`: it runs on SQLite only and is excluded from the PostgreSQL job.
- `BladeViewsCompileTest` compiles every `resources/views/**/*.blade.php` and parses the PHP: a broken directive (an
  inline `@php(...)` paired with a later `@endphp`) fails there with the view and the line, not in 34 other tests.
- Who may do what is a ratchet, not a convention: no roles, every signed-in user is a trusted operator; an action that
  only system admins may call has `Gate::authorize('is-system-admin')` and is marked `admin` in `tests/Support/LivewireActions.php`.
  - `LivewireActionsClassifiedTest`: every public action of every Livewire component is in that map as `ui`, `user` or
    `admin` (a new action fails until somebody decides; `admin` must call the Gate, `ui` must not write data).
    `LivewireAdminActionsForbiddenTest` calls the admin actions as a normal user (403).
  - `RoutesRequireAuthTest`: every route has `auth`, `device.signature`, `guest` or `is-system-admin`, or is in the allowlist
    of public routes in the test with the reason; every route named `system.*` has `is-system-admin`.
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
- **Blade: no inline `@php(...)` in a view that also has `@php ... @endphp` blocks.** Blade pairs every `@php` with the
  next `@endphp`, so the inline form swallows everything up to the next block (it is printed as raw text and the view
  fails with `unexpected token "endforeach"`). Write `@php $x = ...; @endphp` on one line, as the views do.
- A view test that looks for a text (`assertSee`) breaks when the text moves into an attribute; assert the tooltip
  (`title`) then.
