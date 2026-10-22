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
                'sistema_freno' => 'Sistema de frenos (guayas o hidráulico)',
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
        'ajustado' => ['chip' => 'Ajustado', 'texto' => 'ajuste', 'verbo' => 'ajustamos'],
        'lubricado' => ['chip' => 'Lubricado', 'texto' => 'lubricación', 'verbo' => 'lubricamos'],
        'limpiado' => ['chip' => 'Limpiado', 'texto' => 'limpieza', 'verbo' => 'limpiamos'],
        'cambiado' => ['chip' => 'Cambiado', 'texto' => 'cambio', 'verbo' => 'cambiamos'],
        'recomendar' => ['chip' => 'Recomendar cambio', 'texto' => 'se recomienda cambio'],
    ],

    // solo para el texto al cliente (GUIA_VOZ_ONBIKE): texto corto del
    // componente con su articulo, sin "y" ni comas propias para que cada
    // enumeracion lleve una sola "y"; y tarea en primera persona plural. Las
    // etiquetas de arriba siguen siendo las de la pantalla y el PDF
    'componentes_cliente' => [
        'cadena' => 'la cadena',
        'pinones' => 'los piñones',
        'platos_bielas' => 'los platos',
        'cambio_trasero' => 'el cambio trasero',
        'cambio_delantero' => 'el cambio delantero',
        'guayas_cambio' => 'las guayas de cambio',
        'pastillas' => 'las pastillas',
        'discos' => 'los discos',
        'mordazas' => 'las mordazas',
        'sistema_freno' => 'el sistema de frenos',
        'cauchos' => 'los cauchos',
        'tripa_tubeless' => 'la tripa',
        'rayos_centrado' => 'los rayos',
        'mazas' => 'las mazas',
        'direccion' => 'el juego de dirección',
        'pedalier' => 'la caja de pedalier',
        'cuadro' => 'el cuadro',
        'horquilla' => 'la horquilla',
        'cockpit' => 'el manubrio',
        'pedales' => 'los pedales',
        'bateria' => 'la batería',
        'motor' => 'el motor',
        'cableado' => 'el cableado',
    ],

    'tareas_cliente' => [
        'lubricar_cadena' => 'lubricamos la cadena',
        'ajustar_frenos' => 'ajustamos los frenos',
        'ajustar_cambios' => 'ajustamos los cambios',
        'presion_cauchos' => 'calibramos la presión de los cauchos',
        'limpieza_transmision' => 'limpiamos la transmisión',
        'centrado_ruedas' => 'centramos las ruedas',
        'revision_rolineras' => 'revisamos las rolineras',
        'lavado' => 'lavamos la bici',
    ],

    // motivo en el texto al cliente: [singular, plural] ("la cadena muestra
    // desgaste", "los piñones muestran desgaste"). Fisura y fuga son hallazgos
    // de seguridad: "encontramos una fisura". La etiqueta del boton no se usa ahi
    'motivos_cliente' => [
        'desgaste' => ['muestra desgaste', 'muestran desgaste'],
        'holgura' => ['tiene juego', 'tienen juego'],
        'ruido' => ['presenta ruido', 'presentan ruido'],
        'fisura' => ['una fisura', 'fisuras'],
        'fuga' => ['una fuga', 'fugas'],
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
        // armados de cliente o de evento: sin tareas de servicio
        'armado' => [],
    ],

    // fuente unica de etiquetas: pantallas (via payload), PDF, texto automatico e IA
    'paquetes_etiqueta' => ['basico' => 'Básico', 'full' => 'Full', 'vip' => 'VIP', 'otro' => 'Otro', 'armado' => 'Armado'],
    'categorias' => ['ruta' => 'Ruta', 'mtb' => 'MTB', 'otro' => 'Otro'],

    // hallazgos que no se recomiendan cambiar: los evalua un especialista
    'motivos_seguridad' => ['fisura', 'fuga'],

    // acciones que no aplican a un componente: el sistema de frenos se ajusta o
    // se recomienda, "cambiado" no dice que pieza se cambio
    'acciones_excluidas' => [
        'sistema_freno' => ['cambiado'],
    ],

    // tope de fotos por coleccion (entrada / salida), pantalla y servidor
    'max_fotos' => 3,

    // dias en que el taller no abre (0 = domingo ... 6 = sabado, como Carbon::dayOfWeek).
    // La fecha de compromiso por defecto (mañana) salta estos dias; elegir uno a mano solo
    // muestra un aviso. Supuesto: el taller cierra los domingos
    'dias_cerrados' => [0],

    // aviso por WhatsApp de "unidad lista" (GUIA_VOZ_ONBIKE: tuteo, emojis solo
    // en WhatsApp). Marcadores: {nombre} {bici} {monto}. Si el ticket ya se pago
    // en el taller no hay monto que pedir. El operador lo edita antes de enviar
    // Son los textos ORIGINALES: el operador los edita en Configuracion > WhatsApp > Taller
    // (PlantillasWhatsapp usa el texto guardado y, si no hay, estos). En proceso con
    // repuestos sin precio se elimina la linea que lleva {monto}: el total acumulado
    // seria enganoso
    'whatsapp_listo' => [
        'por_cobrar' => "Hola {nombre} 👋 Te saludamos de parte del equipo de OnBike Margarita. Tu {bici} ya está lista para retirar 🚲\nEl total es {monto}.\nTe esperamos.",
        'pagado' => "Hola {nombre} 👋 Te saludamos de parte del equipo de OnBike Margarita. Tu {bici} ya está lista para retirar 🚲\nEl servicio ya está pagado.\nTe esperamos.",
    ],

    // otros mensajes rapidos de WhatsApp: sin montos ni cifras. "danos" es generico a
    // proposito (no describe la falla por escrito); "libre" arranca solo con el saludo
    'whatsapp_repuesto' => 'Hola {nombre} 👋 Sobre tu {bici}: para seguir con el trabajo necesitamos un repuesto adicional. ¿Nos autorizas a conseguirlo? Te confirmamos el detalle por aquí.',
    'whatsapp_danos' => 'Hola {nombre} 👋 Revisando tu {bici} encontramos un detalle adicional que conviene atender. ¿Podemos comentártelo por aquí?',
    'whatsapp_libre' => 'Hola {nombre} 👋 ',

];
