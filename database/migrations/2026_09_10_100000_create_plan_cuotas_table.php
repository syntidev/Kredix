<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_cuenta_id')->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->unsignedTinyInteger('numero_cuota');
            $table->decimal('monto_sugerido', 12, 2);
            $table->date('fecha_esperada');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_cuotas');
    }
};
