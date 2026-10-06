<?php

namespace Tests\Unit;

use App\Models\TicketTaller;
use App\Services\Taller\InformeRevision;
use Tests\TestCase;

class InformeRevisionTest extends TestCase
{
    private function texto(?string $paquete, array $revision): string
    {
        $ticket = new TicketTaller(['tipo_servicio' => $paquete]);
        $ticket->revision_tecnica = ['version' => 1, ...$revision];

        return (new InformeRevision)->generarTexto($ticket);
    }

    private function c(array $acciones, array $motivos = [], string $nota = ''): array
    {
        return ['acciones' => $acciones, 'motivos' => $motivos, 'nota' => $nota, 'origen' => 'manual'];
    }

    public function test_solo_ok(): void
    {
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'pastillas' => $this->c(['ok']),
            'discos' => $this->c(['ok']),
        ]]);

        $this->assertSame("Servicio Basico.\nRevisado sin novedad: 2 componentes.", $texto);
    }

    public function test_con_intervenciones_y_tareas(): void
    {
        $texto = $this->texto('full', [
            'tareas' => ['lubricar_cadena' => true, 'lavado' => true, 'ajustar_frenos' => false],
            'componentes' => [
                'cadena' => $this->c(['lubricado', 'ajustado']),
                'pastillas' => $this->c(['cambiado']),
                'discos' => $this->c(['ok']),
            ],
        ]);

        $this->assertSame(
            "Servicio Full.\nTareas realizadas: Lubricar cadena; Lavado.\n"
            ."Intervenido: Cadena (lubricación y ajuste); Pastillas/Zapatas (cambio).\n"
            .'Revisado sin novedad: 1 componente.',
            $texto,
        );
    }

    public function test_con_recomendaciones_y_sin_paquete(): void
    {
        $texto = $this->texto(null, ['tareas' => [], 'componentes' => [
            'pinones' => $this->c(['recomendar'], ['desgaste']),
            'cauchos' => $this->c(['ajustado', 'recomendar'], ['fisura', 'desgaste'], 'trasero'),
        ]]);

        $this->assertSame(
            "Intervenido: Cauchos (ajuste).\n"
            .'Recomendaciones: Piñones/Cassette — desgaste: se recomienda cambio en el próximo servicio; '
            .'Cauchos — fisura/daño, desgaste: se recomienda cambio en el próximo servicio.',
            $texto,
        );
    }

    public function test_revision_enorme_queda_bajo_el_tope_y_sin_notas(): void
    {
        $nota = str_repeat('NOTA-LARGA ', 45);
        $componentes = [];
        foreach (array_keys(InformeRevision::componentes()) as $i => $clave) {
            $componentes[$clave] = $this->c($i % 2 ? ['recomendar'] : ['cambiado', 'ajustado', 'limpiado', 'lubricado', 'recomendar'], array_keys(config('taller.motivos')), $nota);
        }

        $texto = $this->texto('full', ['tareas' => array_fill_keys(config('taller.paquetes.full'), true), 'componentes' => $componentes]);

        $this->assertCount(23, $componentes);
        $this->assertLessThanOrEqual(InformeRevision::TOPE_TEXTO, mb_strlen($texto));
        $this->assertStringNotContainsString('NOTA-LARGA', $texto);
        $this->assertMatchesRegularExpression('/…y \d+ componentes más; ver detalle en la revisión técnica\.$/u', $texto);
        // corta en componente completo: ninguna entrada queda a medias
        $this->assertStringContainsString('Intervenido: Cadena (cambio, ajuste, limpieza y lubricación)', $texto);
    }

    public function test_componente_sin_acciones_no_cuenta_como_contenido(): void
    {
        $this->assertFalse(InformeRevision::tieneContenido(['tareas' => ['lavado' => false], 'componentes' => ['cadena' => $this->c([])]]));
        $this->assertTrue(InformeRevision::tieneContenido(['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]));
        $this->assertFalse(InformeRevision::tieneContenido(null));
    }
}
