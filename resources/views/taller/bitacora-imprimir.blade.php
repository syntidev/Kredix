<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bitacora Taller — {{ $fechaImpresion }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 24px;
            color: #111;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px;
        }
        .subtitulo {
            font-size: 13px;
            color: #555;
            margin: 0 0 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 17px;
        }
        th, td {
            border: 1px solid #999;
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #eee;
            font-size: 14px;
            text-transform: uppercase;
        }
        .marca-armado {
            background: #000;
            color: #fff;
            padding: 1px 6px;
            font-size: 13px;
            white-space: nowrap;
            border: 2px solid #000;
        }
        .total { font-size: 20px; display: block; }
        .etiqueta-total { font-size: 11px; color: #555; letter-spacing: .5px; }
        .grupo td {
            background: #ddd;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 6px 12px;
        }
        .entrega-detalle { display: block; font-size: 13px; color: #555; }
        .casilla-ok {
            display: inline-block;
            width: 26px;
            height: 26px;
            border: 2px solid #000;
        }
        .col-ok { width: 48px; text-align: center; }
        .sin-tickets {
            padding: 16px;
            font-size: 14px;
            color: #555;
        }

        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }
            body {
                padding: 0;
            }
            /* la marca se lee aunque la impresora no pinte fondos: borde y texto
            negros en negrita, sin depender de la opcion de imprimir fondos */
            .marca-armado {
                background: transparent;
                color: #000;
                font-weight: 700;
            }
        }
    </style>
</head>
<body>
    <h1>Bitacora de Taller — Tickets en proceso</h1>
    <p class="subtitulo">Impreso: {{ $fechaImpresion }} — {{ $tickets->count() }} ticket(s)</p>

    @if ($tickets->isEmpty())
        <p class="sin-tickets">No hay tickets en proceso.</p>
    @else
        @php
            $categoriaLabel = config('taller.categorias');
            // la columna de total solo existe si algun ticket tiene cliente; los
            // trabajos de la tienda no llevan cifra ni encabezado de cifra
            $hayTotales = $tickets->contains(fn ($t) => ! $t->esTrabajoTienda());
            $columnas = 8 + ($hayTotales ? 1 : 0);
            $grupoActual = null;
        @endphp
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Bici</th>
                    <th>Mecanico</th>
                    <th>Estado</th>
                    @if ($hayTotales)
                        <th>Total</th>
                    @endif
                    <th>Fecha de ingreso</th>
                    <th>Entrega</th>
                    <th class="col-ok">OK</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    @php($grupo = \App\Services\Taller\FechaCompromiso::grupo($ticket->fecha_compromiso, $ticket->estado))
                    @if ($grupo !== $grupoActual)
                        @php($grupoActual = $grupo)
                        <tr class="grupo"><td colspan="{{ $columnas }}">{{ $grupos[$grupo] }}</td></tr>
                    @endif
                    <tr>
                        <td>{{ $ticket->id }}</td>
                        <td>{{ $ticket->tipo_servicio === 'vip' ? '[VIP] ' : '' }}{{ $ticket->cliente->nombre ?? 'Trabajo de la tienda' }}{!! $ticket->incluye_armado ? ' <strong class="marca-armado">+ ARMADO</strong>' : '' !!}</td>
                        <td>
                            {{ $ticket->bici_marca_modelo }}
                            · {{ $categoriaLabel[$ticket->categoria_bici] ?? $ticket->categoria_bici }}
                            @if ($ticket->talla_rin)
                                · Rin {{ $ticket->talla_rin }}
                            @endif
                            @if ($ticket->es_electrica)
                                · E-BIKE
                            @endif
                        </td>
                        <td>{{ $ticket->mecanico->name ?? '-' }}</td>
                        <td>En proceso</td>
                        @if ($hayTotales)
                            <td>
                                {{-- trabajo de la tienda: la celda queda vacia --}}
                                @if ($ticket->totalVisible() !== null)
                                    <strong class="total">${{ number_format($ticket->totalVisible(), 2) }}</strong>
                                    <span class="etiqueta-total">{{ strtoupper(str_replace('_', ' ', $ticket->etiquetaTotal())) }}</span>
                                @endif
                            </td>
                        @endif
                        <td>{{ $ticket->created_at->format('d/m/Y') }}</td>
                        <td>
                            <strong>{{ \App\Services\Taller\FechaCompromiso::etiqueta($ticket->fecha_compromiso, $ticket->estado) }}</strong>
                            @if ($ticket->fecha_compromiso)
                                <span class="entrega-detalle">{{ $ticket->fecha_compromiso->format('d/m') }}</span>
                            @endif
                        </td>
                        <td class="col-ok"><span class="casilla-ok"></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
