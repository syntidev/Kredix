<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            // Identificador del carrito: todas las lineas de un mismo envio del
            // formulario de Nueva compra lo comparten. Permite financiar el total
            // de la operacion en vez del primer producto.
            // NULL en todo lo anterior al hotfix -- sin backfill a proposito: ahi
            // no hay forma confiable de reconstruir el carrito (agrupar por
            // created_at confundiria la importacion del lote 1 con compras reales).
            // Con NULL el total sigue siendo el del cargo, identico a antes.
            $table->uuid('compra_id')->nullable()->after('cliente_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_cuenta', function (Blueprint $table) {
            $table->dropIndex(['compra_id']);
            $table->dropColumn('compra_id');
        });
    }
};
