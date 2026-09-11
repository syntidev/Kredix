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
        // BCV publica su tasa oficial en un momento variable dentro de la tarde
        // (no un punto fijo) -- en vez de intentos aislados, se revisa cada 30 min
        // durante toda la ventana de publicacion (2pm-7pm Caracas). 07:00 se
        // mantiene como respaldo matutino unico (trae el valor del dia anterior).
        $horas = ['07:00'];
        for ($minutos = 14 * 60; $minutos <= 19 * 60; $minutos += 30) {
            $horas[] = sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
        }

        foreach ($horas as $hora) {
            $schedule->call(fn () => app(TasaBcvService::class)->fetchAndStore())
                ->dailyAt($hora)
                ->timezone('America/Caracas')
                ->name('tasa-bcv-fetch-'.str_replace(':', '', $hora))
                ->onOneServer();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'es_admin' => \App\Http\Middleware\EsAdmin::class,
        ]);

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
