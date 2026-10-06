<?php

use App\Services\Ia\ClienteIa;
use App\Services\Ia\EstructuradorRevision;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ia:modelos {--buscar=}', function (ClienteIa $ia) {
    try {
        $ids = collect($ia->modelos())
            ->when($this->option('buscar'), fn ($c, $q) => $c->filter(fn ($id) => str_contains(strtolower($id), strtolower($q))));
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $ids->each(fn ($id) => $this->line($id));
    $this->info($ids->count().' modelos.');
})->purpose('Lista los IDs de modelos disponibles en la cuenta NVIDIA');

Artisan::command('ia:probar {--modelo=}', function (ClienteIa $ia) {
    try {
        $r = $ia->chat(
            [['role' => 'user', 'content' => 'Responde únicamente: OK']],
            array_filter(['model' => $this->option('modelo'), 'max_tokens' => 20]),
        );
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->line("Respuesta: {$r['texto']}");
    $this->line("Modelo:    {$r['modelo']}");
    $this->line("Latencia:  {$r['latencia_ms']} ms");
})->purpose('Prueba de humo contra la API de IA');

Artisan::command('ia:estructurar {texto} {--modelo=}', function (EstructuradorRevision $e) {
    try {
        $r = $e->estructurar($this->argument('texto'), $this->option('modelo'));
    } catch (RuntimeException $ex) {
        $this->error($ex->getMessage());

        return 1;
    }
    $this->line($r['revision'] ? json_encode($r['revision'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $r['crudo']);
    $this->line("Modelo: {$r['modelo']} | Latencia: {$r['latencia_ms']} ms");
    foreach ($r['errores'] as $err) {
        $this->error($err);
    }

    return $r['errores'] ? 1 : 0;
})->purpose('Convierte un dictado del técnico en JSON de revisión (catálogo provisional)');
