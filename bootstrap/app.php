<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Sadece "Otomatik senkronizasyonu aktif et" checkbox'i acikken her dakika tetiklenir.
        // Kapaliyken scheduler hicbir sey yapmaz, ekrana "Running" da yazmaz.
        $schedule->call(function () {
            \App\Console\SyncTick::run();
        })
            ->name('karavankids_sync_tick')
            ->everyMinute()
            ->withoutOverlapping()
            ->when(function () {
                try {
                    return (bool) \App\Models\SyncSetting::get('otomatik_aktif', false);
                } catch (\Throwable) {
                    return false; // DB henuz hazir degilse calistirma
                }
            });
    })
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
