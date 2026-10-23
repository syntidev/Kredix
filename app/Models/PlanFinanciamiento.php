<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class PlanFinanciamiento extends Model
{
    use SoftDeletes;

    protected $table = 'planes_financiamiento';

    protected $fillable = [
        'movimiento_cuenta_id',
        'monto_inicial',
        'porcentaje_mora',
        'frecuencia_pago',
        'numero_cuotas',
        'aplicado_mora',
    ];

    protected $casts = [
        'monto_inicial' => 'decimal:2',
        'porcentaje_mora' => 'decimal:2',
        'aplicado_mora' => 'boolean',
    ];

    // withTrashed: si se anula el cargo que sostiene el plan, el plan no debe
    // quedar huerfano -- sin esto la relacion devuelve null y la mora revienta
    // (y el plan desaparecia de la ficha mientras sus cuotas seguian vivas)
    public function cargo(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class, 'movimiento_cuenta_id')->withTrashed();
    }

    // Total pactado de la compra que este plan financia: la suma de las lineas VIVAS
    // del carrito (compra_id). Una linea anulada o editada cambia el total y el plan se
    // recalcula (ver SincronizaPlanConCargos); el saldo del cliente ya la excluia.
    // Sin compra_id (compras anteriores al hotfix): el monto del unico cargo.
    public static function montoTotalDeCompra(MovimientoCuenta $cargo): float
    {
        if (blank($cargo->compra_id)) {
            return (float) $cargo->monto;
        }

        return (float) MovimientoCuenta::where('compra_id', $cargo->compra_id)
            ->where('tipo', 'cargo')
            ->sum('monto');
    }

    // El plan activo que financia la compra de este cargo (con compra_id, el plan anclado a
    // cualquiera de sus lineas, vivas o anuladas; sin compra_id, el anclado a este cargo).
    // Los cargos de mora no llevan compra_id ni plan: devuelven null.
    public static function deCargo(MovimientoCuenta $cargo): ?self
    {
        $anclas = blank($cargo->compra_id)
            ? [$cargo->id]
            : MovimientoCuenta::withTrashed()->where('compra_id', $cargo->compra_id)->where('tipo', 'cargo')->pluck('id')->all();

        return self::whereIn('movimiento_cuenta_id', $anclas)->first();
    }

    // Maximo UN plan activo por compra: si ya hay uno (de cualquier linea del carrito) no se crea otro.
    // Un plan anulado (soft-delete) no cuenta.
    public static function crearParaCargo(MovimientoCuenta $cargo, array $datos): self
    {
        if (self::deCargo($cargo)) {
            throw ValidationException::withMessages(['plan' => 'Esta compra ya tiene un plan de financiamiento activo']);
        }

        return self::create(['movimiento_cuenta_id' => $cargo->id, ...$datos]);
    }

    // Reparto de un monto en N cuotas: piso a centavos y la ultima absorbe el resto.
    // Mismo calculo para crear el plan y para recalcularlo.
    /** @return list<float> */
    public static function repartirCuotas(float $montoAFinanciar, int $numeroCuotas): array
    {
        $base = floor(($montoAFinanciar / $numeroCuotas) * 100) / 100;
        $acumulado = 0;
        $montos = [];

        for ($numero = 1; $numero <= $numeroCuotas; $numero++) {
            $montoPactado = $numero === $numeroCuotas ? round($montoAFinanciar - $acumulado, 2) : $base;
            $acumulado += $montoPactado;
            $montos[] = $montoPactado;
        }

        return $montos;
    }

    // Suma de abonos vigentes del plan sin la inicial: filas nuevas (plan_financiamiento_id) y
    // viejas partidas por cuota (cuota_id), una sola vez cada una
    public function totalAbonado(): float
    {
        return round((float) MovimientoCuenta::where('tipo', 'abono')
            ->where('es_inicial', false)
            ->where(fn ($q) => $q->where('plan_financiamiento_id', $this->id)->orWhereIn('cuota_id', $this->cuotas()->pluck('id')))
            ->sum('monto'), 2);
    }

    // Todo abono vigente ligado al plan, inicial incluida: con alguno, el plan no se puede anular
    public function tieneAbonos(): bool
    {
        return MovimientoCuenta::where('tipo', 'abono')
            ->where(fn ($q) => $q->where('plan_financiamiento_id', $this->id)->orWhereIn('cuota_id', $this->cuotas()->pluck('id')))
            ->exists();
    }

    public function montoTotalCompra(): float
    {
        return $this->cargo ? self::montoTotalDeCompra($this->cargo) : 0.0;
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }

    // Cascada: la suma de abonos vigentes del plan (sin la inicial) se reparte en
    // orden sobre monto_pactado y se persiste en cada cuota -- siempre desde la
    // suma real, nunca incremental. Cuenta las filas nuevas (plan_financiamiento_id)
    // y las viejas partidas por cuota (cuota_id), una sola vez cada una. Lo que
    // sobra de cubrir todas las cuotas simplemente queda sin asignar
    public function recalcularCascada(): void
    {
        $cuotas = $this->cuotas()->get();

        $restante = (float) MovimientoCuenta::where('tipo', 'abono')
            ->where('es_inicial', false)
            ->where(fn ($q) => $q->where('plan_financiamiento_id', $this->id)->orWhereIn('cuota_id', $cuotas->modelKeys()))
            ->sum('monto');

        foreach ($cuotas as $cuota) {
            $aplicado = round(min($restante, (float) $cuota->monto_pactado), 2);
            $cuota->update(['monto_abonado' => $aplicado]);
            $restante = round($restante - $aplicado, 2);
        }
    }
}
