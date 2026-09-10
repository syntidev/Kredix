<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #101010; }
        h1 { font-size: 16px; margin-bottom: 2px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 14px; }
        .cliente { margin-bottom: 14px; }
        .cliente td { padding: 2px 0; }
        .cliente .label { color: #666; width: 100px; }
        table.movimientos { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.movimientos th, table.movimientos td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        table.movimientos th { background: #f2f1ee; text-transform: uppercase; font-size: 9px; color: #666; }
        table.movimientos td.monto, table.movimientos th.monto { text-align: right; }
        .saldo-final { font-weight: bold; }
        .saldo-final .rojo { color: #c00000; }
        h2 { font-size: 13px; margin: 16px 0 6px; }
        .nota { color: #666; font-size: 9px; margin-bottom: 8px; }
        .cargo-cuotas { margin-bottom: 10px; }
        .cargo-cuotas .titulo { font-weight: bold; margin-bottom: 3px; }
        table.cuotas { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.cuotas td { padding: 2px 6px; border-bottom: 1px solid #eee; }
        .estado-cubierta { color: #15803d; }
        .estado-parcial { color: #b45309; }
        .estado-pendiente { color: #666; }
        .mensajes { margin-top: 18px; border-top: 1px solid #ddd; padding-top: 10px; }
        .mensajes p { margin: 0 0 8px; white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>Kredix — Estado de cuenta</h1>
    <p class="meta">Emitido: {{ $fechaEmision }}</p>

    <table class="cliente">
        <tr><td class="label">Cliente</td><td>{{ $cliente->nombre }}</td></tr>
        <tr><td class="label">Telefono</td><td>{{ $cliente->telefono }}</td></tr>
        @if ($cliente->cedula)
            <tr><td class="label">Cedula</td><td>{{ $cliente->cedula }}</td></tr>
        @endif
        <tr><td class="label">Saldo pendiente</td><td><strong>{{ number_format($saldoPendiente, 2) }}</strong></td></tr>
    </table>

    <table class="movimientos">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Descripcion</th>
                <th class="monto">Monto</th>
                <th class="monto">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movimientos as $m)
                <tr>
                    <td>{{ $m['fecha'] }}</td>
                    <td>{{ $m['tipo'] }}</td>
                    <td>{{ $m['descripcion'] }}</td>
                    <td class="monto">{{ $m['monto'] !== null ? number_format($m['monto'], 2) : '-' }}</td>
                    <td class="monto">{{ number_format($m['saldo_acumulado'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin movimientos registrados.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (count($compromisosCuotas) > 0)
        <h2>Compromisos de cuotas</h2>
        <p class="nota">Reconstruccion visual, no es un registro contable — ningun abono queda vinculado a una cuota especifica.</p>

        @foreach ($compromisosCuotas as $cargo)
            <div class="cargo-cuotas">
                <p class="titulo">{{ $cargo['descripcion'] }} — {{ number_format($cargo['monto_total'], 2) }} ({{ $cargo['fecha'] }})</p>
                <table class="cuotas">
                    @foreach ($cargo['cuotas'] as $cuota)
                        <tr>
                            <td>Cuota {{ $cuota['numero_cuota'] }}</td>
                            <td class="monto">{{ number_format($cuota['monto_sugerido'], 2) }}</td>
                            <td>{{ $cuota['fecha_esperada'] }}</td>
                            <td class="estado-{{ $cuota['estado'] }}">
                                {{ $cuota['estado'] }}
                                @if ($cuota['estado'] === 'parcial')
                                    ({{ number_format($cuota['monto_aplicado'], 2) }} de {{ number_format($cuota['monto_sugerido'], 2) }})
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    @endif

    @if ($mensajeCliente || $mensajeGlobal)
        <div class="mensajes">
            @if ($mensajeCliente)
                <p>{{ $mensajeCliente }}</p>
            @endif
            @if ($mensajeGlobal)
                <p>{{ $mensajeGlobal }}</p>
            @endif
        </div>
    @endif
</body>
</html>
