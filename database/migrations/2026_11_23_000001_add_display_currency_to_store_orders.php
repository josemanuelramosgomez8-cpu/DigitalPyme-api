<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('display_currency', 3)->default('PEN');
            $table->decimal('display_exchange_rate', 12, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['display_currency', 'display_exchange_rate']);
        });
    }
};
