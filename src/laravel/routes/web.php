<?php

use App\Http\Controllers\System\JobsController;
use App\Support\AgentScript;
use App\Support\Signing;
use App\Livewire\ShowDevices;
use Illuminate\Support\Facades\Route;

/* BOILERPLATE routes */
// Remove surrounding coments if customization code below is needed !!!
Route::auth();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::get('/changelog', [App\Http\Controllers\ChangelogController::class, 'index'])->middleware(['auth'])->name('changelog');

Route::prefix('profile')->name('profile.')->middleware(['auth'])->group(function () {
    Route::get('/', [App\Http\Controllers\Auth\ProfileController::class, 'index'])->middleware('auth')->name('index');
    Route::put('/update', [App\Http\Controllers\Auth\ProfileController::class, 'update'])->middleware('auth')->name('update');
    Route::get('/api', [App\Http\Controllers\Auth\ProfileController::class, 'api'])->middleware('auth')->name('api');
    Route::post('/api/create', [App\Http\Controllers\Auth\ProfileController::class, 'createApiToken'])->middleware('auth')->name('api.create');
    Route::delete('/api/remove', [App\Http\Controllers\Auth\ProfileController::class, 'removeApiToken'])->middleware('auth')->name('api.remove');
});

Route::prefix('system')->name('system.')->middleware(['auth', 'is-system-admin'])->group(function () {
	Route::get('/settings', [App\Http\Controllers\System\SettingController::class, 'index'])->name('setting.index');


    Route::get('/audit', [App\Http\Controllers\System\AuditController::class, 'index'])->name('audit.index');

    Route::get('/user', [App\Http\Controllers\System\UserController::class, 'index'])->name('user.index');

    Route::get('/api', [App\Http\Controllers\System\ApiController::class, 'index'])->name('api.index');

    Route::prefix('jobs')->name('jobs.')->group(function () {
        Route::get('/', [App\Http\Controllers\System\JobsController::class, 'index'])->name('index');
        Route::post('/clear', [App\Http\Controllers\System\JobsController::class, 'clear'])->name('clear');
    });

    Route::prefix('cache')->name('cache.')->group(function () {
        Route::get('/', [App\Http\Controllers\System\CacheController::class, 'index'])->name('index');
        Route::post('/clear', [App\Http\Controllers\System\CacheController::class, 'clear'])->name('clear');
    });

    Route::prefix('log')->name('log.')->group(function () {
        Route::get('/', [App\Http\Controllers\System\LogController::class, 'index'])->name('index');
        Route::get('/detail/{file}', [App\Http\Controllers\System\LogController::class, 'detail'])->name('detail');
        Route::get('/tail/{file}', [App\Http\Controllers\System\LogController::class, 'tail'])->name('tail');
        Route::get('/download/{file}', [App\Http\Controllers\System\LogController::class, 'download'])->name('download');
        Route::post('/delete/{file}', [App\Http\Controllers\System\LogController::class, 'delete'])->name('delete');
        Route::post('/clear', [App\Http\Controllers\System\LogController::class, 'clear'])->name('clear');
    });

    Route::prefix('backup')->name('backup.')->group(function () {
        Route::get('/', [App\Http\Controllers\System\BackupController::class, 'index'])->name('index');
        Route::post('/run', [App\Http\Controllers\System\BackupController::class, 'run'])->name('run');
        Route::post('/delete/{backup_date}', [App\Http\Controllers\System\BackupController::class, 'delete'])->name('delete');
        Route::get('/download/{file_name}', [App\Http\Controllers\System\BackupController::class, 'download'])->name('download');
        Route::get('/download', [App\Http\Controllers\System\BackupController::class, 'download'])->name('download.latest');
        Route::post('/restore/{backup_date}', [App\Http\Controllers\System\BackupController::class, 'restore'])->name('restore');
    });
});

/* BOILERPLATE routes */

// Agent script for the install commands (public, the device has no token yet), with the server's
// public key filled in, its signature and the key itself.
Route::get('/agent/app.ps1', function () {
    $content = AgentScript::content();
    abort_if($content === null, 404);

    return response($content, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('agent.script');

Route::get('/agent/app.ps1.sig', function () {
    $content = AgentScript::content();
    abort_if($content === null, 404);

    return response(AgentScript::signature($content), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('agent.signature');

Route::get('/agent/signing-key', function () {
    return response()->json(Signing::publicKey() + ['fingerprint' => Signing::fingerprint()]);
})->name('agent.signing-key');

// Channel authorization of the portal pages (live updates); agents authorize under /api.
Illuminate\Support\Facades\Broadcast::routes(['middleware' => ['web', 'auth']]);

// First start: the first user is created here while there is no user yet.
Route::middleware('guest')->group(function () {
    Route::get('/setup', [App\Http\Controllers\SetupController::class, 'index'])->name('setup');
    Route::post('/setup', [App\Http\Controllers\SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/devices');
    Route::get('/devices', ShowDevices::class)->name('devices');
    Route::get('/networks', App\Livewire\Networks\Page::class)->name('networks');
    Route::get('/notifications', App\Livewire\Notifications\Page::class)->name('notifications');
    Route::get('/security', App\Livewire\SecurityScan\Page::class)->name('security');
    Route::get('/security/export', [App\Http\Controllers\SecurityExportController::class, 'findings'])->name('security.export');
});

// Remediation scripts: system admins only (they run as SYSTEM / root on the devices).
Route::middleware(['auth', 'is-system-admin'])->group(function () {
    Route::get('/scripts', [App\Http\Controllers\ScriptController::class, 'index'])->name('script.index');
    Route::get('/scripts/{script}', App\Livewire\Script\Detail::class)->name('script.show');
});

// Configurable dashboards (steelants/laravel-boilerplate.dashboard): /dashboard and its editor.
Route::dashboard(['middleware' => ['web', 'auth']]);

// Missing from the boilerplate routes stub, used by the system jobs page. Everything that changes
// something is POST (CSRF token), never a link an other page can open.
Route::prefix('system/jobs')->name('system.jobs.')->middleware(['auth', 'is-system-admin'])->group(function () {
    Route::post('/rerun', [JobsController::class, 'rerun'])->name('rerun');
    Route::post('/stop', [JobsController::class, 'stop'])->name('stop');
});
