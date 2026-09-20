<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_financiamiento_id')->constrained('planes_financiamiento')->cascadeOnDelete();
            $table->unsignedTinyInteger('numero');
            $table->decimal('monto_pactado', 12, 2);
            $table->date('fecha_vencimiento');
            $table->decimal('monto_abonado', 12, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas');
    }
};
