<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

// Fecha de compromiso de un ticket: fuente unica del defecto, de la etiqueta que se muestra
// ("Atrasado 2 d", "Vence hoy", "Mañana", "Sáb 24", "Sin fecha"), del grupo de la lista y del
// orden. Todo se calcula al mostrar, en la zona horaria de la app (America/Caracas): nunca se
// guarda un contador de dias.
class FechaCompromiso
{
    private const DIAS_ABREV = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    private const DIAS_LARGOS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private const DIAS_DE_ATAJO = 3;

    // grupos de la lista de Taller, en el orden en que se muestran
    public const GRUPOS = [
        'atrasados' => 'Atrasados',
        'hoy' => 'Hoy',
        'manana' => 'Mañana',
        'semana' => 'Esta semana',
        'despues' => 'Después',
        'sin_fecha' => 'Sin fecha',
    ];

    /** @return list<int> dias en que el taller no abre (0 = domingo ... 6 = sabado). */
    public static function diasCerrados(): array
    {
        return array_values(array_unique(array_map('intval', (array) config('taller.dias_cerrados', []))));
    }

    public static function esDiaCerrado(CarbonInterface $fecha): bool
    {
        return in_array($fecha->dayOfWeek, self::diasCerrados(), true);
    }

    private static function hoy(?CarbonInterface $hoy): Carbon
    {
        return Carbon::instance(($hoy ?? Carbon::today())->toDateTime())->startOfDay();
    }

    /** Dia siguiente a $hoy, saltando los dias cerrados. */
    public static function porDefecto(?CarbonInterface $hoy = null): Carbon
    {
        $fecha = self::hoy($hoy)->addDay();

        // si todos los dias estuvieran cerrados no hay a donde saltar: queda mañana
        if (count(self::diasCerrados()) >= 7) {
            return $fecha;
        }

        while (self::esDiaCerrado($fecha)) {
            $fecha->addDay();
        }

        return $fecha;
    }

    /**
     * Atajos del selector: +1, +2 y +3 dias con su fecha real, mas el defecto si cae mas lejos
     * (cuando hay varios dias cerrados seguidos).
     *
     * @return list<array{dias: int, fecha: string, dia: string, numero: int, nombre_dia: string, cerrado: bool, por_defecto: bool}>
     */
    public static function opciones(?CarbonInterface $hoy = null): array
    {
        $hoy = self::hoy($hoy);
        $defecto = self::porDefecto($hoy);
        $dias = range(1, self::DIAS_DE_ATAJO);
        $diasDefecto = (int) $hoy->diffInDays($defecto);

        if (! in_array($diasDefecto, $dias, true)) {
            $dias[] = $diasDefecto;
        }

        return array_map(function (int $n) use ($hoy, $defecto) {
            $fecha = $hoy->copy()->addDays($n);

            return [
                'dias' => $n,
                'fecha' => $fecha->toDateString(),
                'dia' => self::DIAS_ABREV[$fecha->dayOfWeek],
                'numero' => $fecha->day,
                'nombre_dia' => ucfirst(self::DIAS_LARGOS[$fecha->dayOfWeek]),
                'cerrado' => self::esDiaCerrado($fecha),
                'por_defecto' => $fecha->isSameDay($defecto),
            ];
        }, $dias);
    }

    /** Todo lo que necesita una pantalla para pintar el selector. */
    public static function configuracion(?CarbonInterface $hoy = null): array
    {
        $hoy = self::hoy($hoy);

        return [
            'hoy' => $hoy->toDateString(),
            'hoy_texto' => self::DIAS_LARGOS[$hoy->dayOfWeek].' '.$hoy->day.' de '.$hoy->locale('es')->monthName,
            'defecto' => self::porDefecto($hoy)->toDateString(),
            'opciones' => self::opciones($hoy),
            'dias_cerrados' => self::diasCerrados(),
        ];
    }

    // dias desde hoy hasta la fecha (negativo = ya paso)
    private static function diferencia(CarbonInterface $fecha, Carbon $hoy): int
    {
        return (int) $hoy->diffInDays(Carbon::instance($fecha->toDateTime())->startOfDay(), false);
    }

    /** "Sáb 24" */
    public static function diaAbreviado(CarbonInterface $fecha): string
    {
        return self::DIAS_ABREV[$fecha->dayOfWeek].' '.$fecha->day;
    }

    public static function etiqueta(?CarbonInterface $fecha, string $estado = 'en_proceso', ?CarbonInterface $hoy = null): string
    {
        if ($fecha === null) {
            return 'Sin fecha';
        }

        // un ticket atendido ya no esta atrasado ni vence: solo la fecha
        if ($estado !== 'en_proceso') {
            return self::diaAbreviado($fecha);
        }

        $dif = self::diferencia($fecha, self::hoy($hoy));

        return match (true) {
            $dif < 0 => 'Atrasado '.abs($dif).' d',
            $dif === 0 => 'Vence hoy',
            $dif === 1 => 'Mañana',
            default => self::diaAbreviado($fecha),
        };
    }

    /** Grupo de la lista (null si el ticket no esta en proceso). */
    public static function grupo(?CarbonInterface $fecha, string $estado = 'en_proceso', ?CarbonInterface $hoy = null): ?string
    {
        if ($estado !== 'en_proceso') {
            return null;
        }
        if ($fecha === null) {
            return 'sin_fecha';
        }

        $hoy = self::hoy($hoy);
        $dif = self::diferencia($fecha, $hoy);

        return match (true) {
            $dif < 0 => 'atrasados',
            $dif === 0 => 'hoy',
            $dif === 1 => 'manana',
            $fecha->copy()->startOfDay()->lte($hoy->copy()->endOfWeek()) => 'semana',
            default => 'despues',
        };
    }

    /** Datos listos para las pantallas. */
    public static function datos(?CarbonInterface $fecha, string $estado = 'en_proceso', ?CarbonInterface $hoy = null): array
    {
        return [
            'fecha' => $fecha?->toDateString(),
            'etiqueta' => self::etiqueta($fecha, $estado, $hoy),
            'grupo' => self::grupo($fecha, $estado, $hoy),
            'cerrado' => $fecha !== null && self::esDiaCerrado($fecha),
        ];
    }

    /** Texto para el mensaje de WhatsApp: "viernes 23/10", o "por confirmar" sin fecha. */
    public static function textoEntrega(?CarbonInterface $fecha): string
    {
        return $fecha === null
            ? 'por confirmar'
            : self::DIAS_LARGOS[$fecha->dayOfWeek].' '.$fecha->format('d/m');
    }

    /**
     * Los tres numeros de arriba de la lista: tickets EN PROCESO atrasados, que vencen hoy y que
     * vencen mañana (de todos, no solo de la pagina que se ve). Una sola consulta.
     *
     * @return array{atrasados: int, hoy: int, manana: int}
     */
    public static function contadores(?CarbonInterface $hoy = null): array
    {
        $hoy = self::hoy($hoy);
        $hoyTexto = $hoy->toDateString();
        $mananaTexto = $hoy->copy()->addDay()->toDateString();

        $fila = TicketTaller::query()
            ->where('estado', 'en_proceso')
            ->whereNotNull('fecha_compromiso')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN date(fecha_compromiso) < ? THEN 1 ELSE 0 END), 0) as atrasados, '
                .'COALESCE(SUM(CASE WHEN date(fecha_compromiso) = ? THEN 1 ELSE 0 END), 0) as hoy, '
                .'COALESCE(SUM(CASE WHEN date(fecha_compromiso) = ? THEN 1 ELSE 0 END), 0) as manana',
                [$hoyTexto, $hoyTexto, $mananaTexto],
            )
            ->toBase()
            ->first();

        return ['atrasados' => (int) $fila->atrasados, 'hoy' => (int) $fila->hoy, 'manana' => (int) $fila->manana];
    }

    /** Solo tickets en proceso: con fecha primero (la mas cercana), sin fecha al final; a igual fecha, el mas viejo. */
    public static function ordenarEnProceso(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN fecha_compromiso IS NULL THEN 1 ELSE 0 END')
            ->orderBy('fecha_compromiso')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** Listado mixto: los en proceso arriba por prioridad; los atendidos conservan su orden (mas recientes primero). */
    public static function ordenarListado(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN estado = 'en_proceso' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN estado = 'en_proceso' THEN (CASE WHEN fecha_compromiso IS NULL THEN 1 ELSE 0 END) ELSE 0 END")
            ->orderByRaw("CASE WHEN estado = 'en_proceso' THEN fecha_compromiso END")
            ->orderByRaw("CASE WHEN estado = 'en_proceso' THEN created_at END")
            ->orderByDesc('created_at')
            ->orderByRaw("CASE WHEN estado = 'en_proceso' THEN id END")
            ->orderByDesc('id');
    }
}
