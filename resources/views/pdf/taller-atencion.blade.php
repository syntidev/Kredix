<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 50px 36px 80px 36px; }
        body { font-family: sans-serif; font-size: 11px; color: #101010; }
        h2 { font-size: 13px; margin: 16px 0 6px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 14px; }
        .ficha { margin-bottom: 10px; }
        .ficha table { width: 100%; border-collapse: collapse; }
        .ficha td { padding: 2px 0; font-size: 10px; vertical-align: top; }
        .ficha .label { color: #666; width: 130px; }
        .rojo { color: #c00000; }
        .verde { color: #15803d; }
        .saldo-grande { font-size: 22px; font-weight: bold; }
        .resumen-arriba { width: 100%; border-collapse: collapse; margin-bottom: 14px; background: #f7f7f5; border-radius: 4px; }
        .resumen-arriba td { padding: 10px 12px; }
        .resumen-arriba .total-label { margin: 0; font-size: 9px; text-transform: uppercase; color: #666; }
        table.repuestos { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.repuestos th, table.repuestos td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        table.repuestos th { background: #f2f1ee; text-transform: uppercase; font-size: 9px; color: #666; }
        table.repuestos td.monto, table.repuestos th.monto { text-align: right; }
        .nota { color: #666; font-size: 9px; margin-bottom: 8px; }
        .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .encabezado td { vertical-align: top; padding: 0; }
        .encabezado .col-empresa { width: 60%; }
        .encabezado .col-documento { width: 40%; text-align: right; }
        .encabezado .logo-empresa { max-height: 80px; margin-bottom: 6px; }
        .razon-social { margin: 0 0 3px; font-size: 13px; font-weight: bold; }
        .empresa-linea { margin: 0 0 1px; font-size: 10px; color: #666; }
        .titulo-documento { margin: 0 0 4px; font-size: 22px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .fotos { width: 100%; border-collapse: collapse; }
        .fotos td { width: 25%; padding: 4px; vertical-align: top; text-align: center; }
        .fotos img { max-width: 100%; max-height: 130px; border: 1px solid #ddd; }
        .sin-fotos { color: #666; font-size: 10px; }
    </style>
</head>
<body>
    @php
        if (! function_exists('formatMoneyPdfTaller')) {
            function formatMoneyPdfTaller($valor) {
                return '$' . number_format((float) $valor, 2);
            }
        }
        $categoriaLabel = ['ruta' => 'Ruta', 'mtb' => 'MTB', 'otro' => 'Otro'];
        $tipoServicioLabel = ['basico' => 'Basico', 'full' => 'Full', 'vip' => 'VIP', 'otro' => 'Otro'];
        $totalRepuestos = $ticket->totalRepuestos();
        $totalTicket = $ticket->totalTicket();
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
                <p class="titulo-documento">Atencion de taller</p>
                <p class="meta">Ticket #{{ $ticket->id }} — Emitido: {{ $fechaEmision }}</p>
            </td>
        </tr>
    </table>

    <div class="ficha">
        <table>
            <tr>
                <td class="label">Cliente</td>
                <td>{{ $ticket->tipo_servicio === 'vip' ? '[VIP] ' : '' }}{{ $ticket->cliente->nombre ?? 'Armado interno' }}</td>
                <td class="label">Estado</td>
                <td>{{ $ticket->estado === 'atendido' ? 'Atendido' : 'En proceso' }}</td>
            </tr>
            <tr>
                <td class="label">Bici</td>
                <td>{{ $ticket->bici_marca_modelo }}</td>
                <td class="label">Categoria</td>
                <td>
                    {{ $categoriaLabel[$ticket->categoria_bici] ?? $ticket->categoria_bici }}
                    @if ($ticket->talla_rin) · Rin {{ $ticket->talla_rin }} @endif
                    @if ($ticket->es_electrica) · E-BIKE @endif
                </td>
            </tr>
            <tr>
                <td class="label">Tipo de servicio</td>
                <td>{{ $ticket->tipo_servicio ? ($tipoServicioLabel[$ticket->tipo_servicio] ?? $ticket->tipo_servicio) : 'Armado interno' }}</td>
                <td class="label">Mecanico</td>
                <td>{{ $ticket->mecanico->name ?? '-' }}</td>
            </tr>
            @if ($ticket->tipo_servicio === 'vip' && $ticket->domicilio_direccion)
                <tr>
                    <td class="label">Domicilio</td>
                    <td colspan="3">{{ $ticket->domicilio_direccion }}</td>
                </tr>
            @endif
            @if ($ticket->motivo_ingreso)
                <tr>
                    <td class="label">Motivo de ingreso</td>
                    <td colspan="3">{{ $ticket->motivo_ingreso }}</td>
                </tr>
            @endif
            @if ($ticket->trabajo_realizado)
                <tr>
                    <td class="label">Trabajo realizado</td>
                    <td colspan="3">{{ $ticket->trabajo_realizado }}</td>
                </tr>
            @endif
            <tr>
                <td class="label">Fecha de ingreso</td>
                <td colspan="3">{{ $ticket->created_at->format('d/m/Y') }}</td>
            </tr>
        </table>
    </div>

    <table class="resumen-arriba">
        <tr>
            <td>
                <p class="total-label">Total del ticket (servicio + repuestos)</p>
                <p class="saldo-grande">{{ formatMoneyPdfTaller($totalTicket) }}</p>
            </td>
        </tr>
    </table>

    @if ($ticket->repuestos->isNotEmpty())
        <h2>Repuestos</h2>
        <table class="repuestos">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="monto">Cant.</th>
                    <th class="monto">Precio</th>
                    <th class="monto">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ticket->repuestos as $repuesto)
                    <tr>
                        <td>{{ $repuesto->producto }}</td>
                        <td class="monto">{{ $repuesto->cantidad }}</td>
                        <td class="monto">{{ formatMoneyPdfTaller($repuesto->precio) }}</td>
                        <td class="monto">{{ formatMoneyPdfTaller($repuesto->cantidad * $repuesto->precio) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="nota">Total repuestos: {{ formatMoneyPdfTaller($totalRepuestos) }}</p>
    @endif

    <h2>Fotos de entrada</h2>
    @if ($fotosEntrada->isEmpty())
        <p class="sin-fotos">Sin fotos de entrada.</p>
    @else
        <table class="fotos">
            <tr>
                @foreach ($fotosEntrada as $foto)
                    <td><img src="{{ $foto }}"></td>
                @endforeach
            </tr>
        </table>
    @endif

    <h2>Fotos de salida</h2>
    @if ($fotosSalida->isEmpty())
        <p class="sin-fotos">Sin fotos de salida.</p>
    @else
        <table class="fotos">
            <tr>
                @foreach ($fotosSalida as $foto)
                    <td><img src="{{ $foto }}"></td>
                @endforeach
            </tr>
        </table>
    @endif
</body>
</html>
