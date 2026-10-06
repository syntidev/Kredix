<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->json('revision_tecnica')->nullable()->after('trabajo_realizado');
            $table->foreignId('revisado_por')->nullable()->after('revision_tecnica')->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable()->after('revisado_por');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_taller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['revision_tecnica', 'revisado_en']);
        });
    }
};
