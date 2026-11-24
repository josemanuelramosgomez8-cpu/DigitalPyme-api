<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('reason');
            $table->string('status', 20)->default('pendiente');
            $table->string('resolution', 30)->nullable();
            $table->decimal('resolution_amount', 12, 2)->nullable();
            $table->char('resolution_currency', 3)->nullable();
            $table->text('response_message')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_service_requests');
    }
};
