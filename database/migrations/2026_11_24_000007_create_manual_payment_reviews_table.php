<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status', 30);
            $table->string('new_status', 30);
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['project_payment_id', 'reviewed_at']);
            $table->index(['store_order_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_reviews');
    }
};
