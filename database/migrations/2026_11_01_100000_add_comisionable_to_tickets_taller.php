<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "No comisiona: servicio de la tienda" = false. Todo ticket nuevo comisiona salvo que se marque.
// Los tickets que ya existen sin cliente (trabajos de la tienda) quedan como no comisionables:
// el tecnico no cobra comision por trabajo propio de la tienda.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->boolean('comisionable')->default(true)->after('monto_armado');
        });

        DB::table('tickets_taller')->whereNull('cliente_id')->update(['comisionable' => false]);
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('comisionable');
        });
    }
};
