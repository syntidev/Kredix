<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ventas_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->decimal('monto_total', 12, 2);
            $table->enum('moneda', ['usd', 'ves']);
            $table->decimal('tasa_cambio', 12, 4);
            $table->decimal('abono_inicial', 12, 2)->default(0);
            $table->unsignedTinyInteger('plazo_meses');
            $table->enum('frecuencia_pago', ['semanal', 'quincenal', 'mensual']);
            $table->date('fecha_venta');
            $table->enum('estado', ['activo', 'pagado', 'renegociado'])->default('activo');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas_credito');
    }
};
