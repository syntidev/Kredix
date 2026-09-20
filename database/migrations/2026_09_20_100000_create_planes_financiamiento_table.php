<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_financiamiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_cuenta_id')->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->decimal('monto_inicial', 12, 2)->default(0);
            $table->decimal('porcentaje_mora', 5, 2)->default(10.00);
            $table->string('frecuencia_pago');
            $table->unsignedTinyInteger('numero_cuotas');
            $table->boolean('aplicado_mora')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_financiamiento');
    }
};
