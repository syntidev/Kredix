<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_taller', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['servicio_cliente', 'armado_interno']);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->string('bici_marca_modelo');
            $table->string('talla_rin');
            $table->string('tipo_servicio')->nullable();
            $table->decimal('monto_servicio', 10, 2)->nullable();
            // [{item: "Cadena", estado: "bien"|"atencion", nota: "texto opcional"}, ...]
            // JSON en vez de columnas fijas -- agregar un item nuevo al checklist
            // despues no debe requerir migracion de esquema
            $table->json('diagnostico')->nullable();
            $table->enum('estado', ['en_proceso', 'atendido'])->default('en_proceso');
            $table->foreignId('mecanico_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_taller');
    }
};
