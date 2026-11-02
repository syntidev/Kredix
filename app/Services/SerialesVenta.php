<?php

namespace App\Services;

use App\Models\MovimientoCuenta;
use App\Models\SerialProducto;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// Seriales por unidad de las lineas de una venta: normalizacion, validacion (cantidad exacta,
// distintos entre si, no repetidos entre los VIGENTES), alta, liberacion y correccion, todo con ActivityLog
class SerialesVenta
{
    public static function normalizar(string $serial): string
    {
        return mb_strtoupper(trim($serial), 'UTF-8');
    }

    // "venta del 02/11/2026 (cargo #12, «GARMIN») a Ana Perez": donde ya vive un serial vigente
    private static function dondeVive(SerialProducto $existente): string
    {
        $linea = $existente->movimiento;
        $fecha = $linea?->fecha?->format('d/m/Y') ?? 'sin fecha';

        return "venta del {$fecha} (cargo #{$existente->movimiento_cuenta_id}, «{$linea?->descripcion}») a {$existente->cliente?->nombre}";
    }

    /**
     * Valida las lineas que llevan serial. $lineas = productos del request.
     * Cada linea marcada exige tantos seriales como unidades (cantidad entera), distintos entre si
     * y sin repetir un serial vigente. Lanza ValidationException con el mensaje en el campo de la linea.
     */
    public static function validar(array $lineas): void
    {
        $errores = [];
        $vistos = [];

        foreach ($lineas as $i => $linea) {
            if (empty($linea['lleva_serial'])) {
                continue;
            }

            $cantidad = (float) ($linea['cantidad'] ?? 0);
            if ($cantidad < 1 || floor($cantidad) !== $cantidad) {
                $errores["productos.$i.cantidad"] = 'Un artículo con serial se vende por unidades enteras (un serial por unidad).';

                continue;
            }

            $unidades = (int) $cantidad;
            $seriales = array_map(fn ($s) => self::normalizar((string) $s), array_values($linea['seriales'] ?? []));
            if (count($seriales) !== $unidades || in_array('', $seriales, true)) {
                $errores["productos.$i.seriales"] = "Indica {$unidades} serial(es) para «".($linea['descripcion'] ?? '')."» (uno por unidad).";

                continue;
            }

            foreach ($seriales as $j => $serial) {
                if (isset($vistos[$serial])) {
                    $errores["productos.$i.seriales.$j"] = "El serial {$serial} está repetido en esta venta.";

                    continue;
                }
                $vistos[$serial] = true;

                $existente = SerialProducto::with(['movimiento:id,descripcion,fecha', 'cliente:id,nombre'])->where('serial', $serial)->first();
                if ($existente) {
                    $errores["productos.$i.seriales.$j"] = "El serial {$serial} ya está registrado en la ".self::dondeVive($existente).'.';
                }
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    // Alta de los seriales de UNA linea de cargo (ya validados). Un renglon por unidad
    public static function registrar(MovimientoCuenta $linea, array $seriales, ?int $usuarioId): void
    {
        foreach ($seriales as $serial) {
            $fila = SerialProducto::create([
                'movimiento_cuenta_id' => $linea->id,
                'cliente_id' => $linea->cliente_id,
                'serial' => self::normalizar((string) $serial),
                'registrado_por' => $usuarioId,
            ]);

            activity()->causedBy(User::find($usuarioId))->performedOn($fila)
                ->withProperties(['serial' => $fila->serial, 'movimiento_cuenta_id' => $linea->id])
                ->log('serial_creado');
        }
    }

    // Soft-delete de los seriales vigentes de una linea (al eliminarla): quedan libres
    public static function liberarDeLinea(MovimientoCuenta $linea, ?User $usuario, string $motivo): void
    {
        SerialProducto::where('movimiento_cuenta_id', $linea->id)->get()->each(function (SerialProducto $fila) use ($usuario, $motivo) {
            $fila->delete();

            activity()->causedBy($usuario)->performedOn($fila)
                ->withProperties(['serial' => $fila->serial, 'motivo' => $motivo])
                ->log('serial_liberado');
        });
    }

    // Corregir un serial ya registrado (typo): mismas reglas de unicidad, con huella del antes y el despues
    public static function corregir(SerialProducto $fila, string $nuevo, ?User $usuario): SerialProducto
    {
        $nuevo = self::normalizar($nuevo);
        if ($nuevo === '') {
            throw ValidationException::withMessages(['serial' => 'El serial no puede quedar vacío.']);
        }
        if ($nuevo === $fila->serial) {
            return $fila;
        }

        $existente = SerialProducto::with(['movimiento:id,descripcion,fecha', 'cliente:id,nombre'])
            ->where('serial', $nuevo)->where('id', '!=', $fila->id)->first();
        if ($existente) {
            throw ValidationException::withMessages(['serial' => "El serial {$nuevo} ya está registrado en la ".self::dondeVive($existente).'.']);
        }

        $antes = $fila->serial;
        $fila->update(['serial' => $nuevo]);

        activity()->causedBy($usuario)->performedOn($fila)
            ->withProperties(['antes' => $antes, 'despues' => $nuevo])
            ->log('serial_editado');

        return $fila;
    }
}
