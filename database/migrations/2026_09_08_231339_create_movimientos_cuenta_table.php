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
        Schema::create('movimientos_cuenta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['cargo', 'abono', 'ajuste_devolucion']);
            $table->text('descripcion');
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->decimal('monto', 12, 2);
            $table->enum('moneda', ['usd', 'ves']);
            $table->decimal('tasa_cambio', 12, 4);
            $table->enum('metodo_pago', ['efectivo', 'zelle', 'binance', 'transferencia'])->nullable();
            $table->text('comentario')->nullable();
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
        Schema::dropIfExists('movimientos_cuenta');
    }
};
