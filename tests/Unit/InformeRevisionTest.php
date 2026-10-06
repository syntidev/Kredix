<?php

namespace Tests\Unit;

use App\Models\TicketTaller;
use App\Services\Taller\InformeRevision;
use Tests\TestCase;

class InformeRevisionTest extends TestCase
{
    private function texto(?string $paquete, array $revision, string $bici = ''): string
    {
        $ticket = new TicketTaller(['tipo_servicio' => $paquete, 'bici_marca_modelo' => $bici]);
        $ticket->revision_tecnica = ['version' => 1, ...$revision];

        return (new InformeRevision)->generarTexto($ticket);
    }

    private function c(array $acciones, array $motivos = [], string $nota = ''): array
    {
        return ['acciones' => $acciones, 'motivos' => $motivos, 'nota' => $nota, 'origen' => 'manual'];
    }

    // frases que prometen algo que nadie marco (backlog D3 / Entrega A3)
    private function assertSinPromesas(string $texto): void
    {
        foreach (['el resto', 'componentes más', 'completo', 'servicio Otro'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    public function test_solo_ok_nombra_lo_revisado_con_concordancia(): void
    {
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'pastillas' => $this->c(['ok']),
            'discos' => $this->c(['ok']),
        ]]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici. Revisamos las pastillas y los discos: están en buen estado.', $texto);
    }

    public function test_un_componente_marcado_de_23_no_produce_resto(): void
    {
        $this->assertCount(23, InformeRevision::componentes());

        $texto = $this->texto('full', ['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]);

        $this->assertSame('Le hicimos el servicio Full a tu bici. Revisamos la cadena: está en buen estado.', $texto);
        $this->assertSinPromesas($texto);
    }

    public function test_cambios_y_ajustes_con_el_verbo_de_cada_componente(): void
    {
        $texto = $this->texto('basico', [
            'tareas' => array_fill_keys(config('taller.paquetes.basico'), true),
            'componentes' => [
                'cadena' => $this->c(['cambiado']),
                'pinones' => $this->c(['lubricado']),
                'pastillas' => $this->c(['cambiado', 'ajustado']),
                'discos' => $this->c(['ajustado']),
                'mazas' => $this->c(['ok']),
            ],
        ], 'Trek Marlin');

        $this->assertSame(
            'Le hicimos el servicio Básico a tu Trek Marlin: lubricamos la cadena, ajustamos los frenos, los cambios y calibramos la presión de los cauchos. '
            .'Cambiamos la cadena y las pastillas. Lubricamos los piñones. Ajustamos los discos. Revisamos las mazas: están en buen estado.',
            $texto,
        );
        $this->assertSinPromesas($texto);
    }

    public function test_muchos_ajustes_se_enumeran_sin_resumir(): void
    {
        $texto = $this->texto('full', ['tareas' => [], 'componentes' => [
            'pinones' => $this->c(['ajustado', 'lubricado']),
            'cambio_trasero' => $this->c(['ajustado']),
            'discos' => $this->c(['ajustado']),
            'pedales' => $this->c(['lubricado']),
        ]]);

        $this->assertSame(
            'Le hicimos el servicio Full a tu bici. Ajustamos y lubricamos los piñones. Ajustamos el cambio trasero y los discos. Lubricamos los pedales.',
            $texto,
        );
    }

    public function test_paquete_otro_no_se_nombra_como_servicio(): void
    {
        $texto = $this->texto('otro', ['tareas' => [], 'componentes' => ['cadena' => $this->c(['lubricado'])]]);

        $this->assertSame('Trabajamos en tu bici. Lubricamos la cadena.', $texto);
    }

    public function test_sistema_de_frenos_nunca_dice_cambiado(): void
    {
        // revision vieja con "cambiado" en el sistema de frenos: se ignora esa accion
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'sistema_freno' => $this->c(['cambiado', 'ajustado']),
        ]]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici. Ajustamos el sistema de frenos.', $texto);
    }

    public function test_recomendacion_normal_con_motivo_para_cliente(): void
    {
        $texto = $this->texto('full', [
            'tareas' => ['lubricar_cadena' => true],
            'componentes' => [
                'pinones' => $this->c(['recomendar'], ['desgaste']),
                'cadena' => $this->c(['recomendar'], ['holgura', 'ruido']),
            ],
        ]);

        $this->assertSame(
            'Le hicimos el servicio Full a tu bici: lubricamos la cadena. '
            .'Te recomendamos cambiar la cadena en el próximo servicio porque tiene juego y presenta ruido. '
            .'También te recomendamos cambiar los piñones porque muestran desgaste.',
            $texto,
        );
    }

    public function test_fisura_nunca_se_recomienda_cambiar_y_la_nota_no_sale(): void
    {
        $texto = $this->texto('basico', ['tareas' => [], 'componentes' => [
            'cadena' => $this->c(['lubricado']),
            'discos' => $this->c(['ok']),
            'cuadro' => $this->c(['recomendar'], ['fisura', 'desgaste'], 'NOTA-INTERNA cerca del pedalier'),
        ]]);

        $this->assertSame(
            'Le hicimos el servicio Básico a tu bici. '
            .'Por seguridad, te recomendamos no rodar hasta que un especialista evalúe el cuadro: encontramos una fisura. '
            .'Lubricamos la cadena. Revisamos los discos: están en buen estado.',
            $texto,
        );
        $this->assertStringNotContainsString('cambiar el cuadro', $texto);
        $this->assertStringNotContainsString('NOTA-INTERNA', $texto);
    }

    public function test_fisura_y_fuga_en_una_sola_frase(): void
    {
        $texto = $this->texto(null, ['tareas' => [], 'componentes' => [
            'sistema_freno' => $this->c(['recomendar'], ['fuga']),
            'cuadro' => $this->c(['recomendar'], ['fisura']),
            'horquilla' => $this->c(['recomendar'], ['fuga']),
        ]]);

        $this->assertSame(
            'Trabajamos en tu bici. Por seguridad, te recomendamos no rodar hasta que un especialista evalúe '
            .'el sistema de frenos, el cuadro y la horquilla: encontramos una fuga y una fisura.',
            $texto,
        );
    }

    public function test_frases_fijas_son_las_mismas_para_la_ia(): void
    {
        $ticket = new TicketTaller(['tipo_servicio' => 'basico']);
        $ticket->revision_tecnica = ['version' => 1, 'tareas' => [], 'componentes' => [
            'cuadro' => $this->c(['recomendar'], ['fisura']),
            'pinones' => $this->c(['recomendar'], ['holgura']),
        ]];

        $fijas = \App\Jobs\RedactarInforme::frasesFijas($ticket);
        $texto = (new InformeRevision)->generarTexto($ticket);

        $this->assertStringContainsString($fijas['seguridad'], $texto);
        $this->assertStringContainsString($fijas['recomendaciones'][0], $texto);
        $this->assertStringContainsString('porque tienen juego', $fijas['recomendaciones'][0]);
    }

    public function test_tareas_con_el_mismo_verbo_sin_cadena_de_y(): void
    {
        $uno = $this->texto('basico', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true], 'componentes' => []]);
        $dos = $this->texto('full', ['tareas' => ['ajustar_frenos' => true, 'ajustar_cambios' => true, 'lavado' => true], 'componentes' => []]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos y los cambios.', $uno);
        $this->assertSame('Le hicimos el servicio Full a tu bici: ajustamos los frenos, los cambios y lavamos la bici.', $dos);
    }

    public function test_tarea_sin_texto_de_cliente_usa_su_etiqueta(): void
    {
        config([
            'taller.tareas.revisar_luces' => 'Revisar luces',
            'taller.tareas.purgado' => 'Purgado',
            'taller.paquetes.basico' => [...config('taller.paquetes.basico'), 'revisar_luces', 'purgado'],
        ]);

        $texto = $this->texto('basico', ['tareas' => ['ajustar_frenos' => true, 'revisar_luces' => true, 'purgado' => true], 'componentes' => []]);

        $this->assertSame('Le hicimos el servicio Básico a tu bici: ajustamos los frenos, revisar luces y purgado.', $texto);
    }

    public function test_revision_enorme_queda_bajo_el_tope_sin_notas_ni_cortes(): void
    {
        $nota = str_repeat('NOTA-LARGA ', 45);
        $componentes = [];
        foreach (array_keys(InformeRevision::componentes()) as $i => $clave) {
            $componentes[$clave] = $this->c($i % 2 ? ['recomendar'] : ['cambiado', 'ajustado', 'recomendar'], ['desgaste', 'holgura', 'ruido'], $nota);
        }
        $componentes['cuadro'] = $this->c(['recomendar'], ['fisura'], $nota);

        $texto = $this->texto('full', ['tareas' => array_fill_keys(config('taller.paquetes.full'), true), 'componentes' => $componentes]);

        $this->assertLessThanOrEqual(InformeRevision::TOPE_TEXTO, mb_strlen($texto));
        $this->assertStringNotContainsString('NOTA-LARGA', $texto);
        $this->assertStringNotContainsString('el resto', $texto);
        // lo de seguridad es lo ultimo que se recorta
        $this->assertStringContainsString('un especialista evalúe el cuadro: encontramos una fisura.', $texto);
        // corta en componente completo: la frase anterior termina entera
        $this->assertMatchesRegularExpression('/\. …y \d+ componentes más; ver detalle en la revisión técnica\.$/u', $texto);
    }

    public function test_componente_sin_acciones_no_cuenta_como_contenido(): void
    {
        $this->assertFalse(InformeRevision::tieneContenido(['tareas' => ['lavado' => false], 'componentes' => ['cadena' => $this->c([])]]));
        $this->assertTrue(InformeRevision::tieneContenido(['tareas' => [], 'componentes' => ['cadena' => $this->c(['ok'])]]));
        $this->assertFalse(InformeRevision::tieneContenido(null));
    }
}
