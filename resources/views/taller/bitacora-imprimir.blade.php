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
            font-size: 14px;
        }
        th, td {
            border: 1px solid #999;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #eee;
            font-size: 13px;
            text-transform: uppercase;
        }
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
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Bici</th>
                    <th>Motivo de ingreso</th>
                    <th>Mecanico</th>
                    <th>Estado</th>
                    <th>Fecha de ingreso</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    <tr>
                        <td>{{ $ticket->id }}</td>
                        <td>{{ $ticket->cliente->nombre ?? 'Armado interno' }}</td>
                        <td>{{ $ticket->bici_marca_modelo }}{{ $ticket->talla_rin ? ' (Rin '.$ticket->talla_rin.')' : '' }}</td>
                        <td>{{ $ticket->motivo_ingreso ? \Illuminate\Support\Str::limit($ticket->motivo_ingreso, 60) : '-' }}</td>
                        <td>{{ $ticket->mecanico->name ?? '-' }}</td>
                        <td>En proceso</td>
                        <td>{{ $ticket->created_at->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
