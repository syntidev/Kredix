<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un serial por UNIDAD vendida (garantia). Normalizado en la aplicacion: trim + MAYUSCULAS.
// Unicidad de los VIGENTES: serial_vigente es serial mientras la fila no este liberada
// (soft-delete) y NULL cuando lo esta; un indice unico sobre ella impide repetir un serial
// vigente y deja volver a registrar uno liberado (los NULL no chocan entre si).
// La aplicacion valida ademas, dentro de transaccion, para dar un mensaje claro.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seriales_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_cuenta_id')->constrained('movimientos_cuenta');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('serial', 100);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->string('serial_vigente', 100)->nullable()->virtualAs('CASE WHEN deleted_at IS NULL THEN serial END')->unique();
            $table->index('serial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seriales_producto');
    }
};
