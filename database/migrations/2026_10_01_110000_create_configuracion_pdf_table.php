<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_pdf', function (Blueprint $table) {
            $table->id();
            $table->boolean('mostrar_banners_en_taller')->default(false);
            $table->timestamps();
        });

        // singleton id=1 -- unica fila, igual patron que Configuracion::logoHost()
        DB::table('configuracion_pdf')->insert([
            'id' => 1,
            'mostrar_banners_en_taller' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_pdf');
    }
};
