<?php

use App\Http\Middleware\DeviceSignature;
use App\Http\Middleware\RememberPortalUrl;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['api', 'device.signature', 'auth:api'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/devices');
        $middleware->alias(['device.signature' => DeviceSignature::class]);
        $middleware->web(append: [SetLocale::class, RememberPortalUrl::class]);
        // Before authentication, so the 401 of an unknown token is signed as well.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, DeviceSignature::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
