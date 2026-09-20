<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cuota extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'plan_financiamiento_id',
        'numero',
        'monto_pactado',
        'fecha_vencimiento',
        'monto_abonado',
    ];

    protected $casts = [
        'monto_pactado' => 'decimal:2',
        'fecha_vencimiento' => 'date',
        'monto_abonado' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanFinanciamiento::class, 'plan_financiamiento_id');
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class, 'cuota_id');
    }

    // monto_abonado es un valor persistido (no calculado al vuelo) -- se
    // recalcula por SUM real cada vez que un abono ligado a esta cuota se
    // crea/edita/borra, nunca por suma incremental (evita drift si algo falla
    // a mitad de camino)
    public function recalcular(): void
    {
        $this->monto_abonado = $this->abonos()->where('tipo', 'abono')->sum('monto');
        $this->save();
    }

    public function estado(): string
    {
        $pactado = (float) $this->monto_pactado;
        $abonado = (float) $this->monto_abonado;

        if ($abonado >= $pactado) {
            return 'cubierta';
        }

        if ($abonado > 0) {
            return 'parcial';
        }

        if ($this->fecha_vencimiento->isPast()) {
            return 'vencida';
        }

        return 'pendiente';
    }
}
