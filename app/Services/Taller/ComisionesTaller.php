<?php

namespace App\Services\Taller;

use App\Models\TicketTaller;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Comisiones del tecnico por rango (KPI de Taller). Base = tickets atendidos con atendido_en
// en el rango; solo los comisionables suman a la base:
//  - servicio base = monto_servicio + monto_armado
//  - repuestos base = totalRepuestos()
//  - cortesia = comisionable con servicio+armado = 0: sin comision de servicio, si de repuestos
// Todo en centavos enteros; la comision se redondea UNA vez por tecnico, nunca por ticket
class ComisionesTaller
{
    public static function calcular(CarbonInterface $desde, CarbonInterface $hasta, float $pctServicio, float $pctRepuestos): array
    {
        $tickets = TicketTaller::query()
            ->where('estado', 'atendido')
            ->whereBetween('atendido_en', [$desde->copy()->startOfDay()->toDateTimeString(), $hasta->copy()->endOfDay()->toDateTimeString()])
            ->with(['mecanico:id,name', 'repuestos'])
            ->get();

        $filas = $tickets->groupBy('mecanico_id')
            ->map(fn (Collection $grupo) => self::fila($grupo, $pctServicio, $pctRepuestos))
            ->sortByDesc('tickets_atendidos')
            ->values();

        return [
            'porMecanico' => $filas,
            'totales' => self::totales($filas),
            'porcentajes' => ['servicio' => $pctServicio, 'repuestos' => $pctRepuestos],
            // atendido_en estimado (no registrado en el momento): el rango puede no ser exacto para ellos
            'tickets_fecha_aprox' => $tickets->where('atendido_en_aprox', true)->count(),
        ];
    }

    private static function fila(Collection $grupo, float $pctServicio, float $pctRepuestos): array
    {
        $comisionables = $grupo->where('comisionable', true);
        $servicioCent = (int) $comisionables->sum(fn (TicketTaller $t) => self::centavos((float) $t->monto_servicio + (float) $t->monto_armado));
        $repuestosCent = (int) $comisionables->sum(fn (TicketTaller $t) => self::centavos($t->totalRepuestos()));
        $comServicioCent = self::porcentaje($servicioCent, $pctServicio);
        $comRepuestosCent = self::porcentaje($repuestosCent, $pctRepuestos);
        $cortesias = $comisionables->filter(fn (TicketTaller $t) => self::centavos((float) $t->monto_servicio + (float) $t->monto_armado) === 0)->count();

        return [
            'mecanico' => $grupo->first()->mecanico?->name ?? 'Sin asignar',
            'tickets_atendidos' => $grupo->count(),
            'con_cobro' => $comisionables->count() - $cortesias,
            'cortesias' => $cortesias,
            'de_la_tienda' => $grupo->count() - $comisionables->count(),
            'servicio_base' => $servicioCent / 100.0,
            'repuestos_base' => $repuestosCent / 100.0,
            'comision_servicio' => $comServicioCent / 100.0,
            'comision_repuestos' => $comRepuestosCent / 100.0,
            'total' => ($comServicioCent + $comRepuestosCent) / 100.0,
        ];
    }

    private static function totales(Collection $filas): array
    {
        $suma = fn (string $campo) => (int) round($filas->sum($campo) * 100);

        return [
            'tickets_atendidos' => $filas->sum('tickets_atendidos'),
            'con_cobro' => $filas->sum('con_cobro'),
            'cortesias' => $filas->sum('cortesias'),
            'de_la_tienda' => $filas->sum('de_la_tienda'),
            'servicio_base' => $suma('servicio_base') / 100.0,
            'repuestos_base' => $suma('repuestos_base') / 100.0,
            'comision_servicio' => $suma('comision_servicio') / 100.0,
            'comision_repuestos' => $suma('comision_repuestos') / 100.0,
            'total' => $suma('total') / 100.0,
        ];
    }

    private static function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }

    // media hacia arriba sobre centavos enteros
    private static function porcentaje(int $centavos, float $pct): int
    {
        return (int) round($centavos * $pct / 100.0, 0, PHP_ROUND_HALF_UP);
    }
}
