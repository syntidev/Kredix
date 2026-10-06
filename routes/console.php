<?php

use App\Jobs\RedactarInforme;
use App\Jobs\SugerirRevision;
use App\Models\TicketTaller;
use App\Services\Ia\BitacoraIa;
use App\Services\Ia\ClienteIa;
use App\Services\Ia\EstructuradorRevision;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
})->purpose('Convierte un dictado del técnico en JSON de revisión (catálogo de config/taller.php)');

// Comandos de prueba: corren el job en el momento, sin cola, aunque IA_ACTIVA=false.
$correrJob = function ($comando, string $clase, callable $mostrar) {
    $ticket = TicketTaller::find($comando->argument('ticket'));
    if (! $ticket) {
        $comando->error('Ticket no encontrado.');

        return 1;
    }
    $inicio = hrtime(true);
    try {
        $clase::dispatchSync($ticket);
    } catch (Throwable $e) {
        $comando->error($e->getMessage());

        return 1;
    } finally {
        $comando->line('Latencia: '.BitacoraIa::ms($inicio).' ms');
    }
    $mostrar($ticket->fresh());
};

Artisan::command('ia:sugerir {ticket}', function () use ($correrJob) {
    return $correrJob($this, SugerirRevision::class, fn ($t) => $this->line(json_encode($t->sugerencias_ia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
})->purpose('Corre SugerirRevision sobre un ticket, sin cola');

Artisan::command('ia:redactar {ticket}', function () use ($correrJob) {
    return $correrJob($this, RedactarInforme::class, function ($t) {
        $this->line("Estado: {$t->informe_ia_estado}");
        $this->line((string) $t->informe_ia);
    });
})->purpose('Corre RedactarInforme sobre un ticket, sin cola');

Artisan::command('ia:estado {--ultimas=50}', function () {
    $filas = collect(BitacoraIa::ultimas((int) $this->option('ultimas')))
        ->groupBy(fn ($l) => "{$l['job']}|{$l['modelo']}|{$l['prompt_version']}")
        ->map(fn ($g, $k) => [
            ...explode('|', $k),
            $g->count(),
            $g->where('resultado', 'ok')->count(),
            $g->where('resultado', 'error')->count(),
            $g->where('resultado', 'requiere_revision')->count(),
            (int) $g->avg('ms'),
            $g->max('ms'),
        ])->values();
    $this->table(['Job', 'Modelo', 'Prompt', 'Cantidad', 'OK', 'Error', 'Req. revisión', 'Prom. ms', 'Máx. ms'], $filas);
})->purpose('Resumen del log de IA (storage/logs/ia.jsonl)');

// Cola de IA: una llamada a la vez. Los jobs de IA tardan hasta 300 s, por eso:
// - runInBackground(): un job largo no bloquea las demas tareas del scheduler (tasa BCV).
// - retry_after=360 en config/queue.php: mayor que el timeout de 300 s, si no el job se re-entrega mientras corre y duplica la llamada.
Schedule::command('queue:work --queue=ia,default --stop-when-empty --max-time=55')
    ->everyMinute()->withoutOverlapping()->runInBackground();
