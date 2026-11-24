<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('recepcion_checklist')->nullable();
            $table->timestamp('recepcion_confirmada_at')->nullable();
            $table->text('notas_preparacion')->nullable();
            $table->string('modalidad_entrega', 30)->nullable();
            $table->text('url_entregable')->nullable();
            $table->text('resumen_entrega')->nullable();
            $table->timestamp('entrega_enviada_at')->nullable();
            $table->timestamp('entregado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'recepcion_checklist',
                'recepcion_confirmada_at',
                'notas_preparacion',
                'modalidad_entrega',
                'url_entregable',
                'resumen_entrega',
                'entrega_enviada_at',
                'entregado_at',
            ]);
        });
    }
};
