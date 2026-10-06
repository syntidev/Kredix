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

        $this->assertSame('Le hicimos el servicio Básico a tu bici. Revisamos 2 componentes y están en buen estado.', $texto);
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

        // la tarea "lubricamos la cadena" sobra: el componente ya lo dice
        $this->assertSame(
            'Le hicimos el servicio Full a tu bici: lavamos la bici, lubricamos y ajustamos la cadena y cambiamos las pastillas. '
            .'Revisamos 1 componente más y está en buen estado.',
            $texto,
        );
    }

    public function test_mismo_verbo_se_agrupa(): void
    {
        $texto = $this->texto('basico', [
            'tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true],
            'componentes' => ['discos' => $this->c(['ajustado'])],
        ]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos, los cambios y los discos.', $texto);
    }

    public function test_con_recomendaciones_y_sin_paquete(): void
    {
        $texto = $this->texto(null, ['tareas' => [], 'componentes' => [
            'pinones' => $this->c(['recomendar'], ['desgaste']),
            'cauchos' => $this->c(['ajustado', 'recomendar'], ['fisura', 'desgaste'], 'trasero'),
        ]]);

        $this->assertSame(
            'Trabajamos en tu bici: ajustamos los cauchos. '
            .'Te recomendamos cambiar los piñones en el próximo servicio por desgaste. '
            .'También te recomendamos cambiar los cauchos por fisura o daño y desgaste.',
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
        $this->assertStringNotContainsString('Intervenido', $texto);
        $this->assertStringNotContainsString('sin novedad', $texto);
        // corta en componente completo: la ultima recomendacion termina entera
        // justo antes de la linea de omitidos
        $this->assertStringContainsString('cambiamos, ajustamos, limpiamos y lubricamos la cadena', $texto);
        $this->assertMatchesRegularExpression('/fisura o daño y fuga\. …y \d+ componentes más; ver detalle en la revisión técnica\.$/u', $texto);
    }

    public function test_componente_sin_acciones_no_cuenta_como_contenido(): void
    {
        $this->assertFalse(InformeRevision::tieneContenido(['tareas' => ['lavado' => false], 'componentes' => ['cadena' => $this->c([])]]));
        $this->assertTrue(InformeRevision::tieneContenido(['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]));
        $this->assertFalse(InformeRevision::tieneContenido(null));
    }
}
