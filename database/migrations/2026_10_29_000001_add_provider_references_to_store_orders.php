<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('provider_reference')->nullable()->unique();
            $table->string('provider_preference_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->text('provider_checkout_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropUnique(['provider_preference_id']);
            $table->dropUnique(['provider_payment_id']);
            $table->dropColumn(['provider_reference', 'provider_preference_id', 'provider_payment_id', 'provider_checkout_url']);
        });
    }
};
