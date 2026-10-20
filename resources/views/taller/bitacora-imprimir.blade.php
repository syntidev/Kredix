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
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .total { font-size: 20px; display: block; }
        .etiqueta-total { font-size: 11px; color: #555; letter-spacing: .5px; }
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
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
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
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
