<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Backfill de datos, no de esquema: hasta ahora todo telefono en clientes se
    // capturo en formato local venezolano (ej: 0414-1234567), la misma suposicion
    // que ya usaba waLink en Clientes/Show.vue. De aqui en adelante telefono se
    // guarda siempre en E.164; esta migracion normaliza lo que ya existia para que
    // no quede ningun registro en formato viejo.
    public function up(): void
    {
        $clientes = DB::table('clientes')->select('id', 'telefono')->get();

        foreach ($clientes as $cliente) {
            if (str_starts_with($cliente->telefono, '+')) {
                continue;
            }

            $digitos = preg_replace('/\D/', '', $cliente->telefono);
            $e164 = str_starts_with($digitos, '0') ? '58'.substr($digitos, 1) : $digitos;

            DB::table('clientes')->where('id', $cliente->id)->update(['telefono' => '+'.$e164]);
        }
    }

    public function down(): void
    {
        // Irreversible a proposito: no hay forma confiable de reconstruir el
        // formato local original (guiones, presencia del 0) desde el E.164.
    }
};
