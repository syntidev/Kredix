<?php

namespace App\Observers;

use App\Jobs\RedactarInforme;
use App\Jobs\SugerirRevision;
use App\Models\TicketTaller;
use App\Services\Taller\InformeRevision;

// Enganche de la IA del taller sin tocar controladores. Con IA_ACTIVA=false no se encola nada.
class TicketTallerObserver
{
    public function created(TicketTaller $t): void
    {
        if (config('ia.activa') && filled($t->motivo_ingreso)) {
            SugerirRevision::dispatch($t);
        }
    }

    public function updated(TicketTaller $t): void
    {
        if (! $t->wasChanged('revision_tecnica') || ! InformeRevision::tieneContenido($t->revision_tecnica)) {
            return;
        }
        $distinta = $t->informe_ia_hash !== RedactarInforme::hash($t->revision_tecnica);

        // aprobado por una persona: no se reescribe solo, solo se avisa (y se limpia si la revision vuelve a lo aprobado)
        if ($t->informe_ia_estado === 'aprobado') {
            if ($t->informe_ia_desactualizado !== $distinta) {
                $t->updateQuietly(['informe_ia_desactualizado' => $distinta]);
            }

            return;
        }

        if ($distinta && config('ia.activa')) {
            $t->updateQuietly(['informe_ia_estado' => 'pendiente']);
            RedactarInforme::encolar($t);
        }
    }
}
