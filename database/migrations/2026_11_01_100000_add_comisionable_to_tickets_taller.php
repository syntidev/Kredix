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

        // REGLA: todo ticket SIN cliente (cliente_id NULL = trabajo de la tienda) queda como no comisionable
        DB::table('tickets_taller')->whereNull('cliente_id')->update(['comisionable' => false]);

        // el deploy avisa cuantos quedaron de cada tipo (incluye eliminados: la regla corre sobre todas las filas)
        if (! app()->runningUnitTests()) {
            $tienda = DB::table('tickets_taller')->where('comisionable', false)->count();
            $comisionables = DB::table('tickets_taller')->where('comisionable', true)->count();
            (new \Symfony\Component\Console\Output\ConsoleOutput())->writeln("  comisionable: {$tienda} ticket(s) quedaron como TIENDA (sin cliente, no comisionan) y {$comisionables} como COMISIONABLES.");
        }
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn('comisionable');
        });
    }
};
