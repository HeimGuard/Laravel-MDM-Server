<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use SteelAnts\LaravelBoilerplate\Facades\Menu;
use Symfony\Component\HttpFoundation\Response;

class GenerateMenus
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guest()) {
            return $next($request);
        }

        if ($request->route()->getName() === 'livewire.message') {
            return $next($request);
        }

        $menuRoutes = [
            'Devices' => [
                'fas fa-desktop',
                'devices',
            ],
            'Networks' => [
                'fas fa-project-diagram',
                'networks',
            ],
            'Security' => [
                'fas fa-shield-alt',
                'security',
            ],
            'Notifications' => [
                'fas fa-bell',
                'notifications',
            ],
        ];

        if (Gate::allows('is-system-admin')) {
            $menuRoutes['Scripts'] = [
                'fas fa-scroll',
                'script.index',
            ];
        }

        $systemRoutes = [
            'Settings' => [
                'fas fa-cog',
                'system.setting.index',
            ],
            'Audit' => [
                'fas fa-eye',
                'system.audit.index',
            ],
            'User' => [
                'fas fa-users',
                'system.user.index',
            ],
            'Logs' => [
                'fas fa-bug',
                'system.log.index',
            ],
            'Jobs' => [
                'fas fa-business-time',
                'system.jobs.index',
            ],
            'Cache' => [
                'fas fa-box',
                'system.cache.index',
            ],
            'Backup' => [
                'fas fa-file-archive',
                'system.backup.index',
            ],
        ];

        if (file_exists(base_path().'/routes/api.php')) {
            $systemRoutes['Api'] = [
                'fas fa-rocket',
                'system.api.index',
            ];
        }

        // One flat menu (navigation.blade.php): the system pages after the others, for system admins only.
        $menus = [
            'main-menu' => $menuRoutes,
            'system-menu' => Gate::allows('is-system-admin') ? $systemRoutes : [],
        ];

        foreach ($menus as $menuKey => $MenuItems) {
            $menu = Menu::get($menuKey) ?? Menu::make($menuKey, function () {});
            foreach ($MenuItems as $title => $route_data) {
                $icon = $route_data[0];
                $route = $route_data[1];

                $menu->add($title, [
                    'id' => strtolower($title),
                    'icon' => $icon,
                    'route' => $route,
                ]);
            }
        }

        return $next($request);
    }
}
