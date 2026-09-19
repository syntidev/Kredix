<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRepuesto extends Model
{
    protected $table = 'ticket_repuestos';

    protected $fillable = [
        'ticket_id',
        'producto',
        'cantidad',
        'precio',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio' => 'decimal:2',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketTaller::class, 'ticket_id');
    }
}
