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
        Schema::create('items_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_credito_id')->constrained('ventas_credito')->cascadeOnDelete();
            $table->text('descripcion_libre');
            $table->decimal('precio_unitario', 12, 2);
            $table->unsignedInteger('cantidad')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items_venta');
    }
};
