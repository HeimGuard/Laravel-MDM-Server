<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * A ratchet: every route has a guard (a signed-in user, a signing device, a guest-only page, system admins) or is
 * in the list of the public routes below, each with the reason. A new route without a guard fails here until
 * somebody puts a guard on it or writes down why it is public.
 */
class RoutesRequireAuthTest extends TestCase
{
    /** Middleware that makes a route private (the part before a ":"; also the classes the aliases stand for). */
    private const GUARDS = [
        'auth',
        'device.signature',
        'guest',
        'is-system-admin',
        \Illuminate\Auth\Middleware\Authenticate::class,
        \App\Http\Middleware\DeviceSignature::class,
    ];

    /**
     * Routes that are public on purpose: uri pattern (fnmatch, without the leading slash) => why.
     *
     * @return array<string, string>
     */
    private static function allowlist(): array
    {
        return [
            'up' => 'Health check (bootstrap/app.php): the load balancer and Docker ask without a session.',
            'agent/app.ps1' => 'The agent script for the install command: a device has no token yet; it is signed (agent/app.ps1.sig).',
            'agent/app.ps1.sig' => 'The signature of the agent script, fetched with it.',
            'agent/signing-key' => 'The public key of the server (a public key by definition), the agent verifies the script with it.',
            'login' => 'Sign-in form (Route::auth()).',
            'logout' => 'Sign-out (Route::auth()); it only ends the session of the caller.',
            'password/*' => 'Password reset by e-mail (Route::auth()): the caller is not signed in.',
            'livewire/*' => 'Livewire internals (update, scripts, file upload and preview): every component checks its own rules.',
            'livewire-*' => 'Livewire internals with the hash in the path (newer versions of Livewire).',
            'sanctum/csrf-cookie' => 'Laravel Sanctum: gives the CSRF cookie, nothing else.',
            'storage/*' => 'Laravel serves the local disk of the framework; the disk is private, only signed URLs are answered.',
        ];
    }

    /** @return array<int, string> the names of the middleware of the route (controller middleware included) */
    private function middlewareOf(Route $route): array
    {
        $names = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware)) {
                $names[] = explode(':', $middleware, 2)[0];
            }
        }

        return $names;
    }

    private function describe(Route $route): string
    {
        $methods = implode('|', array_diff($route->methods(), ['HEAD']));

        return $methods.' /'.ltrim($route->uri(), '/').' ('.($route->getName() ?? 'no name').')';
    }

    private function isAllowed(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');
        foreach (array_keys(self::allowlist()) as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_route_has_a_guard_or_is_a_known_public_route(): void
    {
        $offenders = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (array_intersect($this->middlewareOf($route), self::GUARDS) !== [] || $this->isAllowed($route)) {
                continue;
            }
            $offenders[] = $this->describe($route);
        }

        sort($offenders);
        $this->assertSame([], $offenders, "Routes without a guard (auth, device.signature, guest, is-system-admin) that are not in the allowlist of tests/Feature/RoutesRequireAuthTest.php:\n - ".implode("\n - ", $offenders));
    }

    public function test_the_system_routes_are_for_system_admins(): void
    {
        $offenders = [];
        $seen = 0;
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'system.')) {
                continue;
            }
            $seen++;
            if (! in_array('is-system-admin', $this->middlewareOf($route), true)) {
                $offenders[] = $this->describe($route);
            }
        }

        $this->assertGreaterThan(0, $seen, 'No route named system.* found: the check looks at nothing.');
        sort($offenders);
        $this->assertSame([], $offenders, "Routes named system.* without is-system-admin:\n - ".implode("\n - ", $offenders));
    }

    public function test_the_allowlist_has_no_dead_entries(): void
    {
        // An entry that matches no route is a reason that is no longer true: remove it. Only the routes of this
        // application are checked; the ones of the packages (and of the version of a package) may be absent.
        $own = ['up', 'agent/app.ps1', 'agent/app.ps1.sig', 'agent/signing-key'];
        $uris = array_map(fn (Route $route) => ltrim($route->uri(), '/'), Router::getRoutes()->getRoutes());

        $dead = [];
        foreach (array_keys(self::allowlist()) as $pattern) {
            if (! in_array($pattern, $own, true)) {
                continue;
            }
            if (array_filter($uris, fn ($uri) => fnmatch($pattern, $uri)) === []) {
                $dead[] = $pattern;
            }
        }

        $this->assertSame([], $dead, 'Entries of the allowlist that match no route: '.implode(', ', $dead));
    }
}
