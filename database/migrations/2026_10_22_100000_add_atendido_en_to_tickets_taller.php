<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fecha en que un ticket se marco atendido: es la "fecha de venta" del taller para el
// panel Ventas. tickets_taller solo guardaba estado, sin momento de cierre.
//
// Backfill de los ya atendidos, en este orden:
// 1. activity_log: NO se usa -- el codigo nunca registra el cambio de estado (solo
//    'foto eliminada/reemplazada'), no hay nada que leer ahi.
// 2. cargo automatico vinculado (movimiento_cuenta_id): se crea en la misma
//    transaccion que marca atendido, su created_at es el momento real de cierre.
// 3. created_at del ticket: aproximado. Queda marcado con atendido_en_aprox = true
//    (un booleano basta para distinguirlos y no pesa en las consultas).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->timestamp('atendido_en')->nullable()->after('estado');
            $table->boolean('atendido_en_aprox')->default(false)->after('atendido_en');
        });

        DB::statement("
            UPDATE tickets_taller
            SET atendido_en = (SELECT m.created_at FROM movimientos_cuenta m WHERE m.id = tickets_taller.movimiento_cuenta_id),
                atendido_en_aprox = 0
            WHERE estado = 'atendido'
              AND atendido_en IS NULL
              AND EXISTS (SELECT 1 FROM movimientos_cuenta m WHERE m.id = tickets_taller.movimiento_cuenta_id)
        ");

        DB::statement("
            UPDATE tickets_taller
            SET atendido_en = created_at,
                atendido_en_aprox = 1
            WHERE estado = 'atendido'
              AND atendido_en IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropColumn(['atendido_en', 'atendido_en_aprox']);
        });
    }
};
