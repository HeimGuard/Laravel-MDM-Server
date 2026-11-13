# Dashboard

## Dashboard

`/dashboard` (menu **Dashboard**) is a configurable dashboard from
[steelants/laravel-boilerplate.dashboard](https://packistry.sa-dev.cz/public): every user can create
their own dashboards, share them, and add, resize and move widgets in the editor. A shared
**Overview** dashboard is created by a migration with the **Devices** widget (online and offline
devices right now, the offline ones listed, updated live when a device changes). Owners edit their
dashboards; system admins (`APP_SYSTEM_ADMINS`) can edit every dashboard
(`App\Policies\DashboardPolicy`).

Other widgets: **Smart alerts** (what needs attention on the devices, with the action that fixes it) and
**Security findings** (open findings of the [security scanner](security-scanner.md) that nobody
acknowledged: the count per severity, the most severe ones listed with their device, linking to
**Security**). Its settings are the least severe finding listed (`severity`, default medium) and how
many to list (`list`, default 5; 0 hides the list).

New widgets are Blade components in `app/View/Components/Widgets` and show up in the editor
automatically. The package comes from the `packistry` Composer repository configured in
`composer.json`.
