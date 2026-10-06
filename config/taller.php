<?php

// BORRADOR -- catalogo de la Revision tecnica (Fase 1, PLAN_TALLER_SMART).
// Carlos lo valida con los tecnicos (tarea 0.5) antes de darlo por cerrado.
// Las claves son contrato compartido con la IA (CLI-C): cambiar etiquetas es
// libre, cambiar claves rompe revisiones guardadas y el prompt de la IA.
return [

    'grupos' => [
        'transmision' => [
            'etiqueta' => 'Transmisión',
            'componentes' => [
                'cadena' => 'Cadena',
                'pinones' => 'Piñones/Cassette',
                'platos_bielas' => 'Platos y bielas',
                'cambio_trasero' => 'Cambio trasero',
                'cambio_delantero' => 'Cambio delantero',
                'guayas_cambio' => 'Guayas y fundas de cambio',
            ],
        ],
        'frenos' => [
            'etiqueta' => 'Frenos',
            'componentes' => [
                'pastillas' => 'Pastillas/Zapatas',
                'discos' => 'Discos',
                'mordazas' => 'Mordazas/Calipers',
                'sistema_freno' => 'Guayas o sistema hidráulico',
            ],
        ],
        'ruedas' => [
            'etiqueta' => 'Ruedas',
            'componentes' => [
                'cauchos' => 'Cauchos',
                'tripa_tubeless' => 'Tripa/Tubeless',
                'rayos_centrado' => 'Rayos y centrado',
                'mazas' => 'Mazas',
            ],
        ],
        'direccion_cuadro' => [
            'etiqueta' => 'Dirección y cuadro',
            'componentes' => [
                'direccion' => 'Juego de dirección',
                'pedalier' => 'Caja de pedalier',
                'cuadro' => 'Cuadro (fisuras)',
                'horquilla' => 'Horquilla/Suspensión',
                'cockpit' => 'Manubrio, potencia y tija',
                'pedales' => 'Pedales',
            ],
        ],
        'ebike' => [
            'etiqueta' => 'E-bike',
            'solo_electrica' => true,
            'componentes' => [
                'bateria' => 'Batería',
                'motor' => 'Motor',
                'cableado' => 'Conectores y cableado',
            ],
        ],
    ],

    // chip = boton en pantalla, texto = sustantivo para el informe
    'acciones' => [
        'ok' => ['chip' => 'OK', 'texto' => 'revisado sin novedad'],
        'ajustado' => ['chip' => 'Ajustado', 'texto' => 'ajuste'],
        'lubricado' => ['chip' => 'Lubricado', 'texto' => 'lubricación'],
        'limpiado' => ['chip' => 'Limpiado', 'texto' => 'limpieza'],
        'cambiado' => ['chip' => 'Cambiado', 'texto' => 'cambio'],
        'recomendar' => ['chip' => 'Recomendar cambio', 'texto' => 'se recomienda cambio'],
    ],

    'motivos' => [
        'desgaste' => 'Desgaste',
        'holgura' => 'Holgura',
        'ruido' => 'Ruido',
        'fisura' => 'Fisura/daño',
        'fuga' => 'Fuga',
    ],

    'tareas' => [
        'lubricar_cadena' => 'Lubricar cadena',
        'ajustar_frenos' => 'Ajustar frenos',
        'ajustar_cambios' => 'Ajustar cambios',
        'presion_cauchos' => 'Presión de cauchos',
        'limpieza_transmision' => 'Limpieza de transmisión',
        'centrado_ruedas' => 'Centrado de ruedas',
        'revision_rolineras' => 'Revisión de rolineras',
        'lavado' => 'Lavado',
    ],

    'paquetes' => [
        'basico' => ['lubricar_cadena', 'ajustar_frenos', 'ajustar_cambios', 'presion_cauchos'],
        'full' => ['lubricar_cadena', 'ajustar_frenos', 'ajustar_cambios', 'presion_cauchos', 'limpieza_transmision', 'centrado_ruedas', 'revision_rolineras', 'lavado'],
        'vip' => ['lubricar_cadena', 'ajustar_frenos', 'ajustar_cambios', 'presion_cauchos', 'limpieza_transmision', 'centrado_ruedas', 'revision_rolineras', 'lavado'],
        'otro' => [],
    ],

];
