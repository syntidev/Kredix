<?php

use App\Services\TasaBcvService;
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
    ->withSchedule(function (Schedule $schedule): void {
        // BCV publica su tasa oficial entre 4pm y 6pm hora Venezuela; 07:00 solo
        // trae el valor del dia anterior como respaldo seguro.
        foreach (['07:00', '15:00', '16:30', '18:00'] as $hora) {
            $schedule->call(fn () => app(TasaBcvService::class)->fetchAndStore())
                ->dailyAt($hora)
                ->timezone('America/Caracas')
                ->name('tasa-bcv-fetch-'.str_replace(':', '', $hora))
                ->onOneServer();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
