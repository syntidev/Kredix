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
        Schema::create('prospectos_200k', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ci')->nullable()->index();
            $table->string('telefono')->nullable()->index();
            $table->string('correo')->nullable();
            $table->string('lote');
            $table->boolean('procesado')->default(false)->index();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospectos_200k');
    }
};
