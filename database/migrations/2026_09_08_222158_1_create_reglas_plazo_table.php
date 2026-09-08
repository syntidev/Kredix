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
        Schema::create('reglas_plazo', function (Blueprint $table) {
            $table->id();
            $table->decimal('monto_min', 12, 2);
            $table->decimal('monto_max', 12, 2);
            $table->unsignedTinyInteger('plazo_min_meses');
            $table->unsignedTinyInteger('plazo_max_meses');
            $table->boolean('requiere_abono')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reglas_plazo');
    }
};
