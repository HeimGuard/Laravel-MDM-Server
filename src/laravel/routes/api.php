<?php

use App\Http\Controllers\Api\DeviceCommandController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceScriptController;
use App\Http\Controllers\Api\DeviceSecurityController;
use App\Http\Controllers\Api\DeviceTelemetryController;
use Illuminate\Support\Facades\Route;

// Every device endpoint is signed in both directions (App\Http\Middleware\DeviceSignature). What an endpoint
// does, and the agents it needs, is in the comment of its controller method; the contract is docs/agent-api.md.
Route::middleware(['device.signature', 'auth:api'])->post('/device', [DeviceController::class, 'report']);

Route::middleware(['device.signature', 'throttle:10,1'])->post('/device/register', [DeviceController::class, 'register']);

Route::middleware(['device.signature', 'auth:api'])->group(function () {
    Route::get('/device/realtime', [DeviceController::class, 'realtime']);
    Route::post('/device/heartbeat', [DeviceController::class, 'heartbeat']);
    Route::post('/device/key', [DeviceController::class, 'key']);

    Route::post('/device/security', [DeviceSecurityController::class, 'collection']);
    Route::get('/device/security/policy', [DeviceSecurityController::class, 'policy']);

    Route::post('/device/metrics/backfill', [DeviceTelemetryController::class, 'backfill']);
    Route::post('/device/errors', [DeviceTelemetryController::class, 'errors']);
    Route::post('/device/pings', [DeviceTelemetryController::class, 'pings']);
    Route::post('/device/port-scan', [DeviceTelemetryController::class, 'portScan']);

    // take, ack and {command} share a prefix: the order stays, {command} is numeric only.
    Route::post('/device/commands/take', [DeviceCommandController::class, 'take']);
    Route::post('/device/commands/{command}', [DeviceCommandController::class, 'result'])->whereNumber('command');

    Route::get('/device/scripts', [DeviceScriptController::class, 'pending']);
    Route::post('/device/scripts/runs/{run}', [DeviceScriptController::class, 'result']);

    Route::post('/device/commands/ack', [DeviceCommandController::class, 'ack']);
});
