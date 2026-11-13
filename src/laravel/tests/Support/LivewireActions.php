<?php

namespace Tests\Support;

use App\Livewire as L;

/**
 * Who may call which public action of a Livewire component.
 *
 * There are no roles: every signed-in user is a trusted operator, system admins (Gate "is-system-admin")
 * additionally manage users, rules, scripts and the system pages. This map writes that decision down;
 * LivewireActionsClassifiedTest fails when an action is added, removed or changes its level without
 * an entry here. A new action: decide who may call it, add it here, and when only system admins may,
 * add Gate::authorize('is-system-admin') to the method (and mark it ADMIN).
 *
 * Not listed (not actions of their own): the lifecycle and rendering hooks (render, mount, updated*, ...),
 * the hooks of the DataTable / Form base classes (query, headers, actions, renderColumn*, fields, ...) and
 * #[Computed] methods; see LivewireActionsClassifiedTest::HOOKS.
 */
final class LivewireActions
{
    /** Only changes the state of the component, reads, or opens a modal (the modal component has its own rules). */
    public const UI = 'ui';

    /** Changes data; any signed-in user may call it, by design (no roles). */
    public const USER = 'user';

    /** The method itself checks Gate::authorize('is-system-admin') (or $this->authorize(...)). */
    public const ADMIN = 'admin';

    /**
     * Component class => [public action => level].
     *
     * @return array<class-string, array<string, string>>
     */
    public static function map(): array
    {
        $ui = self::UI;
        $user = self::USER;
        $admin = self::ADMIN;

        return [
            L\DeviceAlerts::class => [
                // The fix an alert offers: restart, update, run a remediation script ... on the device.
                'runAlert' => $user,            // any signed-in user by design: no roles (restart, update ... on the device)
                'dismiss' => $user,
                'updateAgent' => $user,         // any signed-in user by design: no roles (updates the agent, runs code on the device)
                'deviceChanged' => $ui,
            ],
            L\DeviceCommands::class => [
                'sendCommandToDevice' => $user, // any signed-in user by design: no roles (restart, turn off, install updates)
                'pingNow' => $user,
                'scan' => $user,
                'scanPorts' => $user,
                'wake' => $user,
                'cancel' => $user,
                'deleteDevice' => $user,        // any signed-in user by design: no roles (deletes the device and its data)
                'deviceChanged' => $ui,
            ],
            L\DeviceDetail::class => [
                'saveFriendlyName' => $user,
                'rename' => $ui,
                'startEditTags' => $ui,
                'saveTags' => $user,
                'installUpdate' => $user,       // any signed-in user by design: no roles (installs software on the device)
                'installAll' => $user,          // any signed-in user by design: no roles (installs all updates)
                'resetDeviceKey' => $admin,
                'deviceChanged' => $ui,
            ],
            L\DeviceMetrics::class => [
                'setRange' => $ui,
                'deviceChanged' => $ui,
            ],
            L\DeviceSecurity::class => [
                'acknowledge' => $user,
                'reopen' => $user,
                'exportInventory' => $user,     // only reads, but the whole inventory of the device leaves as a file
                'explainWithAi' => $user,       // sends the finding to the AI provider
                'remediateWithAi' => $user,     // any signed-in user by design: no roles (writes a script; only a system admin runs it)
                'deviceChanged' => $ui,
            ],
            L\Job\DataTable::class => [
                'runNow' => $admin,
                'stop' => $admin,
                'trace' => $admin,
                'retry' => $admin,
            ],
            L\Job\Form::class => [
                'getOptions' => $ui,
                'submit' => $admin,
            ],
            L\Networks\Page::class => [
                'scan' => $user,
                'scanAll' => $user,
                'scanAddress' => $user,
                'scanPorts' => $user,
                'togglePortScan' => $admin,
                'add' => $user,
                'ignore' => $user,
                'restore' => $user,
                'toggleDiscovery' => $admin,
                'devicesChanged' => $ui,
            ],
            L\Networks\Show::class => [
                'ignoreAll' => $user,
            ],
            L\Notifications\Page::class => [
                // The rules and channels of the signed-in user themselves.
                'addUrl' => $ui,
                'removeUrl' => $ui,
                'save' => $user,
                'testUrl' => $user,             // sends a request to the URL typed in (webhook / push)
                'testEmail' => $user,           // sends an e-mail to the addresses typed in
                'addRule' => $ui,
                'editRule' => $ui,
                'toggleRule' => $user,
                'deleteRule' => $user,
                'refresh' => $ui,
                'devicesChanged' => $ui,
            ],
            L\Notifications\RuleForm::class => [
                'save' => $user,
                'delete' => $user,
            ],
            L\PingMonitor::class => [
                'setRange' => $ui,
                'refresh' => $ui,
                'deviceChanged' => $ui,
            ],
            L\PingSettings::class => [
                'save' => $user,
            ],
            L\Script\DataTable::class => [
                // The pages of the scripts are for system admins (route and mount); run and edit open the modals.
                'run' => $ui,
                'edit' => $ui,
                'remove' => $admin,
            ],
            L\Script\Detail::class => [
                'run' => $ui,
                'schedule' => $ui,
                'edit' => $ui,
                'refresh' => $ui,
                'rerunFailed' => $admin,
                'devicesChanged' => $ui,
            ],
            L\Script\Form::class => [
                'save' => $admin,
                'generate' => $admin,
            ],
            L\Script\Run::class => [
                'start' => $admin,
            ],
            L\Script\Schedule::class => [
                'save' => $admin,
            ],
            L\ScriptRun\DataTable::class => [
                'remediate' => $admin,
            ],
            L\ScriptRun\Output::class => [
                'aiFix' => $admin,
            ],
            L\Security\Permission::class => [
                // Not mounted by any view today. Assigns roles and scopes to a user with no check at all:
                // any signed-in user by design (no roles), but it must get a decision when it is used.
                'toggleRole' => $user,
                'addScope' => $user,
                'removeScope' => $user,
            ],
            L\SecurityScan\Page::class => [
                'showMoreEvents' => $ui,
                'acknowledge' => $user,
                'reopen' => $user,
                'acknowledgeAll' => $user,
                'toggleRule' => $admin,
                'addRule' => $admin,
                'editRule' => $admin,
                'addParser' => $admin,
                'duplicateRule' => $admin,
                'updateFeed' => $admin,
                'rescan' => $admin,
                'refresh' => $ui,
                'devicesChanged' => $ui,
                'explainWithAi' => $user,       // sends the finding to the AI provider
                'remediateWithAi' => $user,     // any signed-in user by design: no roles (writes a script; only a system admin runs it)
            ],
            L\SecurityScan\RuleForm::class => [
                'save' => $admin,
                'delete' => $admin,
            ],
            L\Session\DataTable::class => [
                'logout' => $user,              // only the sessions of the signed-in user (request()->user()->sessions())
            ],
            L\ShowDevices::class => [
                'selectDevice' => $ui,
                'createPingDevice' => $user,
                'deviceDeleted' => $ui,
                'loadDevices' => $ui,
                'filterTag' => $ui,
                'devicesChanged' => $ui,
            ],
            L\SmartAlerts::class => [
                'runAlert' => $user,            // any signed-in user by design: no roles (restart, update ... on the device)
                'dismiss' => $user,
                'runForAll' => $user,           // any signed-in user by design: no roles (the same action on every device that has the alert)
                'devicesChanged' => $ui,
            ],
            L\User\DataTable::class => [
                'edit' => $ui,                  // opens the modal; its submit is checked
                'remove' => $admin,
                'change' => $admin,
            ],
            L\User\Form::class => [
                'submit' => $admin,
            ],
            L\User\Language::class => [
                // The language of the user the component was mounted with (the profile mounts the signed-in one).
                'store' => $user,
            ],
            L\User\Limitation::class => [
                // The items per page of the user the component was mounted with.
                'store' => $user,
            ],
            L\WakeSettings::class => [
                'save' => $user,
            ],
        ];
    }

    /**
     * Admin actions that LivewireAdminActionsForbiddenTest calls as a normal user: component =>
     * [action => [mount params, call arguments]]. Only where the component can be mounted by a normal user (the
     * ones that check the Gate in mount() never get to the action) and the Gate is the first thing the action
     * does. '@device' in the params is the id of a device the test creates.
     *
     * @return array<class-string, array<string, array{0: array<string, mixed>, 1: array<int, mixed>}>>
     */
    public static function forbiddenCalls(): array
    {
        $rules = ['tab' => 'rules'];

        return [
            L\SecurityScan\Page::class => [
                'toggleRule' => [$rules, [1]],
                'addRule' => [$rules, []],
                'editRule' => [$rules, [1]],
                'addParser' => [$rules, []],
                'duplicateRule' => [$rules, [1]],
                'updateFeed' => [$rules, []],
                'rescan' => [$rules, []],
            ],
            L\Networks\Page::class => [
                'togglePortScan' => [[], []],
                'toggleDiscovery' => [[], []],
            ],
            L\DeviceDetail::class => [
                'resetDeviceKey' => [['selectedDeviceId' => '@device'], []],
            ],
            L\ScriptRun\DataTable::class => [
                // With a device the runs are shown to whoever sees it; the Gate is in the action.
                'remediate' => [['deviceId' => '@device'], [1]],
            ],
        ];
    }

    /** Replaces '@device' in mount params with the id of the device. */
    public static function resolve(array $params, int $deviceId): array
    {
        return array_map(fn ($value) => $value === '@device' ? $deviceId : $value, $params);
    }
}
