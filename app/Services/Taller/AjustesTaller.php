<?php

namespace App\Services\Taller;

use App\Models\Configuracion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

// Ajustes del Taller guardados en Configuracion (clave/valor): tecnico por defecto
// del formulario Nuevo y porcentajes de comision del KPI
class AjustesTaller
{
    public const CLAVE_TECNICO_DEFECTO = 'taller_tecnico_defecto_id';

    public const CLAVE_COMISION_SERVICIO = 'taller_comision_servicio_pct';

    public const CLAVE_COMISION_REPUESTOS = 'taller_comision_repuestos_pct';

    // lista editable de "servicios rapidos" (JSON [{nombre, monto}]); sin datos sembrados
    public const CLAVE_PRESETS_SERVICIO = 'taller_presets_servicio';

    public const MAX_PRESETS_SERVICIO = 20;

    // si la clave no existe el codigo usa estos valores
    public const COMISION_SERVICIO_DEFECTO = 50.0;

    public const COMISION_REPUESTOS_DEFECTO = 5.0;

    // tecnicos elegibles: rol_taller y no ocultos, orden estable por nombre
    public static function tecnicos(): Collection
    {
        return User::where('rol_taller', true)->where('es_oculto', false)
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    // el configurado si sigue siendo elegible; si no, el primero de la lista; null si no hay ninguno
    public static function tecnicoDefectoId(): ?int
    {
        $tecnicos = self::tecnicos();
        $configurado = (int) Configuracion::valorDe(self::CLAVE_TECNICO_DEFECTO);

        return $tecnicos->firstWhere('id', $configurado)?->id ?? $tecnicos->first()?->id;
    }

    public static function comisionServicioPct(): float
    {
        return (float) (Configuracion::valorDe(self::CLAVE_COMISION_SERVICIO) ?? self::COMISION_SERVICIO_DEFECTO);
    }

    public static function comisionRepuestosPct(): float
    {
        return (float) (Configuracion::valorDe(self::CLAVE_COMISION_REPUESTOS) ?? self::COMISION_REPUESTOS_DEFECTO);
    }

    // [{nombre, monto}] guardados; [] si no hay o el JSON esta danado
    public static function presetsServicio(): array
    {
        $guardado = json_decode((string) Configuracion::valorDe(self::CLAVE_PRESETS_SERVICIO), true);

        return collect(is_array($guardado) ? $guardado : [])
            ->filter(fn ($p) => is_array($p) && filled($p['nombre'] ?? null) && is_numeric($p['monto'] ?? null))
            ->map(fn ($p) => ['nombre' => (string) $p['nombre'], 'monto' => round((float) $p['monto'], 2)])
            ->values()->all();
    }
}
