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
        Schema::create('abonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_credito_id')->constrained('ventas_credito')->restrictOnDelete();
            $table->decimal('monto', 12, 2);
            $table->enum('moneda', ['usd', 'ves']);
            $table->decimal('tasa_cambio', 12, 4);
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia']);
            $table->enum('tipo', ['abono', 'ajuste_devolucion'])->default('abono');
            $table->text('comentario');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abonos');
    }
};
