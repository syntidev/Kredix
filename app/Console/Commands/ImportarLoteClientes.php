<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportarLoteClientes extends Command
{
    /**
     * Lee el CSV auditado por Carlos (mismo formato que genera el parser de
     * DataClientes) y crea Cliente + MovimientoCuenta via Eloquent -- nunca SQL
     * crudo. Corre en modo dry-run por defecto: sin --ejecutar solo reporta lo que
     * haria, no escribe nada.
     */
    protected $signature = 'app:importar-lote-clientes
        {csv=DataClientes/lote_completo_importacion.csv : Ruta al CSV auditado}
        {--ejecutar : Sin este flag no se escribe nada en la base de datos}';

    protected $description = 'Importa clientes y movimientos desde el CSV auditado del lote de 428 archivos';

    private const COMENTARIO_BASE = 'Importado de registro en papel, tasa historica no disponible.';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        $ejecutar = $this->option('ejecutar');

        if (! file_exists($csvPath)) {
            $this->error("No se encontro el archivo: {$csvPath}");

            return self::FAILURE;
        }

        $sistema = User::where('email', 'sistema@kredix.local')->first();
        if (! $sistema) {
            $this->error("No existe el usuario 'sistema@kredix.local' -- se necesita para registrado_por.");

            return self::FAILURE;
        }

        $filas = $this->leerCsv($csvPath);
        $porArchivo = [];
        foreach ($filas as $fila) {
            $porArchivo[$fila['archivo_origen']][] = $fila;
        }

        $this->info(($ejecutar ? 'EJECUTANDO' : 'DRY-RUN (sin --ejecutar, no se escribe nada)') . ': '
            . count($porArchivo) . ' clientes, ' . count($filas) . ' filas en el CSV.');

        $clientesCreados = 0;
        $movimientosCreados = 0;
        $clientesConAdvertencia = 0;

        foreach ($porArchivo as $archivo => $filasCliente) {
            $primera = $filasCliente[0];
            $notas = $this->construirNotas($filasCliente);
            if (array_filter($filasCliente, fn ($f) => $f['ADVERTENCIA'] !== '')) {
                $clientesConAdvertencia++;
            }

            if (! $ejecutar) {
                $clientesCreados++;
                $movimientosCreados += count(array_filter($filasCliente, fn ($f) => $f['tipo'] !== 'nota'));

                continue;
            }

            DB::transaction(function () use ($primera, $filasCliente, $notas, $sistema, &$clientesCreados, &$movimientosCreados) {
                $cliente = Cliente::create([
                    'nombre' => $primera['cliente_nombre'],
                    'telefono' => $primera['cliente_telefono'] ?: null,
                    'cedula' => $primera['cliente_ci'] ?: null,
                    'notas' => $notas ?: null,
                ]);
                $clientesCreados++;

                foreach ($filasCliente as $fila) {
                    if ($fila['tipo'] === 'nota') {
                        continue;
                    }

                    $esCargo = $fila['tipo'] === 'cargo';
                    $comentario = self::COMENTARIO_BASE;
                    if ($fila['ADVERTENCIA'] !== '') {
                        $comentario .= ' ADVERTENCIA: ' . $fila['ADVERTENCIA'];
                    }
                    if (($fila['cuenta_interna'] ?? '') !== '') {
                        $comentario = "[Cuenta: {$fila['cuenta_interna']}] " . $comentario;
                    }

                    MovimientoCuenta::create([
                        'cliente_id' => $cliente->id,
                        'fecha' => $fila['fecha'] ?: null,
                        'tipo' => $fila['tipo'],
                        'descripcion' => $fila['descripcion'] ?: $this->descripcionPorDefecto($fila['tipo']),
                        'cantidad' => $esCargo ? 1 : null,
                        'precio_unitario' => $esCargo ? $fila['monto'] : null,
                        'modalidad_precio' => $esCargo ? ($fila['modalidad_precio'] ?: 'divisa') : null,
                        'monto' => $fila['monto'],
                        'moneda' => 'usd',
                        'tasa_cambio' => null,
                        'metodo_pago' => $fila['metodo_pago_mapeado'] ?: null,
                        'comentario' => $comentario,
                        'registrado_por' => $sistema->id,
                    ]);
                    $movimientosCreados++;
                }
            });
        }

        $this->info("Clientes: {$clientesCreados} | Movimientos: {$movimientosCreados} | Con advertencia: {$clientesConAdvertencia}");

        if (! $ejecutar) {
            $this->comment('Nada se escribio en la base de datos. Corre con --ejecutar para aplicar.');
        }

        return self::SUCCESS;
    }

    private function leerCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        // BOM utf-8-sig en la primera columna del encabezado
        $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);

        $filas = [];
        while (($row = fgetcsv($handle)) !== false) {
            $fila = array_combine($header, $row);
            $fila['monto'] = $fila['monto'] !== '' ? (float) $fila['monto'] : null;
            $filas[] = $fila;
        }
        fclose($handle);

        return $filas;
    }

    private function construirNotas(array $filasCliente): string
    {
        $lineas = [];
        foreach ($filasCliente as $fila) {
            if ($fila['ADVERTENCIA'] === '') {
                continue;
            }
            $fecha = $fila['fecha'] !== '' ? $fila['fecha'] : 'fecha desconocida';
            $lineas[] = "\u{26A0} IMPORTADO CON ADVERTENCIA — revisar movimiento del {$fecha}: {$fila['ADVERTENCIA']}";
        }
        foreach ($filasCliente as $fila) {
            if ($fila['tipo'] !== 'nota') {
                continue;
            }
            $fecha = $fila['fecha'] !== '' ? $fila['fecha'] : 'fecha desconocida';
            $lineas[] = "Nota del registro en papel ({$fecha}): {$fila['descripcion']}";
        }

        return implode("\n", $lineas);
    }

    private function descripcionPorDefecto(string $tipo): string
    {
        return match ($tipo) {
            'abono' => 'Abono',
            'ajuste_devolucion' => 'Ajuste / devolucion',
            default => 'Movimiento importado',
        };
    }
}
