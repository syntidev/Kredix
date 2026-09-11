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
        .rojo { color: #c00000; }
        .verde { color: #15803d; }
        .saldo-grande { font-size: 22px; font-weight: bold; }
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
        .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .encabezado td { vertical-align: top; padding: 0; }
        .encabezado .col-empresa { width: 60%; }
        .encabezado .col-documento { width: 40%; text-align: right; }
        .encabezado .logo-empresa { max-height: 80px; margin-bottom: 6px; }
        .razon-social { margin: 0 0 3px; font-size: 13px; font-weight: bold; }
        .empresa-linea { margin: 0 0 1px; font-size: 10px; color: #666; }
        .titulo-documento { margin: 0 0 4px; font-size: 18px; font-weight: bold; text-transform: uppercase; }
    </style>
</head>
<body>
    @php
        // dompdf no ejecuta JS -- equivalente de resources/js/lib/formatMoney.js
        // function_exists() evita "Cannot redeclare" si el mismo worker PHP
        // renderiza esta vista mas de una vez (ej. 2 descargas seguidas)
        if (! function_exists('formatMoneyPdf')) {
            function formatMoneyPdf($valor) {
                return '$' . number_format((float) $valor, 2);
            }
        }
        // equivalente de resources/js/lib/formatFecha.js -- AAAA-MM-DD almacenado, DD/MM/AAAA mostrado
        if (! function_exists('formatFechaPdf')) {
            function formatFechaPdf($fecha) {
                if (! $fecha) {
                    return '-';
                }
                return \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y');
            }
        }
    @endphp
    <table class="encabezado">
        <tr>
            <td class="col-empresa">
                @if ($empresa['logo_base64'])
                    <img class="logo-empresa" src="{{ $empresa['logo_base64'] }}">
                @endif
                <p class="razon-social">{{ $empresa['razon_social'] ?: 'Kredix' }}</p>
                @foreach (array_filter([$empresa['rif'], $empresa['direccion'], $empresa['telefono'], $empresa['email']]) as $lineaEmpresa)
                    <p class="empresa-linea">{{ $lineaEmpresa }}</p>
                @endforeach
            </td>
            <td class="col-documento">
                <p class="titulo-documento">Estado de cuenta</p>
                <p class="meta">Emitido: {{ $fechaEmision }}</p>
            </td>
        </tr>
    </table>

    <table class="cliente">
        <tr><td class="label">Cliente</td><td>{{ $cliente->nombre }}</td></tr>
        <tr><td class="label">Telefono</td><td>{{ $cliente->telefono }}</td></tr>
        @if ($cliente->cedula)
            <tr><td class="label">Cedula</td><td>{{ $cliente->cedula }}</td></tr>
        @endif
        <tr><td class="label">Saldo pendiente</td><td class="saldo-grande {{ $saldoPendiente > 0 ? 'rojo' : ($saldoPendiente < 0 ? 'verde' : '') }}">{{ formatMoneyPdf($saldoPendiente) }}</td></tr>
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
                    <td>{{ formatFechaPdf($m['fecha']) }}</td>
                    <td>{{ $m['tipo'] }}</td>
                    <td>{{ $m['descripcion'] }}</td>
                    <td class="monto {{ $m['tipo'] === 'abono' ? 'verde' : ($m['tipo'] === 'ajuste_devolucion' ? 'rojo' : '') }}">{{ $m['monto'] !== null ? formatMoneyPdf($m['monto']) : '-' }}</td>
                    <td class="monto">{{ formatMoneyPdf($m['saldo_acumulado']) }}</td>
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
                <p class="titulo">{{ $cargo['descripcion'] }} — {{ formatMoneyPdf($cargo['monto_total']) }} ({{ formatFechaPdf($cargo['fecha']) }})</p>
                <table class="cuotas">
                    @foreach ($cargo['cuotas'] as $cuota)
                        <tr>
                            <td>Cuota {{ $cuota['numero_cuota'] }}</td>
                            <td class="monto">{{ formatMoneyPdf($cuota['monto_sugerido']) }}</td>
                            <td>{{ formatFechaPdf($cuota['fecha_esperada']) }}</td>
                            <td class="estado-{{ $cuota['estado'] }}">
                                {{ $cuota['estado'] }}
                                @if ($cuota['estado'] === 'parcial')
                                    ({{ formatMoneyPdf($cuota['monto_aplicado']) }} de {{ formatMoneyPdf($cuota['monto_sugerido']) }})
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
