<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->nullable()->constrained('servicios')->cascadeOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('service_subcategories')->cascadeOnDelete();
            $table->unsignedInteger('available')->default(0);
            $table->unsignedInteger('minimum')->default(0);
            $table->timestamps();
            $table->index(['available', 'minimum']);
            $table->unique('servicio_id');
            $table->unique('subcategory_id');
        });

        Schema::create('service_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_inventory_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 12);
            $table->unsignedInteger('cantidad');
            $table->string('motivo');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(
                ['service_inventory_id', 'created_at'],
                'service_inv_movements_inventory_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inventory_movements');
        Schema::dropIfExists('service_inventories');
    }
};
