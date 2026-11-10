<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('telefono', 40)->nullable();
            $table->string('tipo_documento', 10)->nullable();
            $table->string('numero_documento', 20)->nullable();
            $table->string('tipo_comprobante', 20)->nullable();
            $table->string('direccion_entrega')->nullable();
            $table->string('ubicacion_entrega', 120)->nullable();
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('nombre_cliente')->nullable();
            $table->string('email_cliente')->nullable();
            $table->string('telefono_cliente', 40)->nullable();
            $table->string('tipo_comprobante', 20)->nullable();
            $table->string('tipo_documento', 10)->nullable();
            $table->string('numero_documento', 20)->nullable();
            $table->string('empresa_facturacion')->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('coupon_code', 32)->nullable();
            $table->decimal('igv_rate', 5, 2)->default(18);
            $table->decimal('igv_amount', 12, 2)->default(0);
        });

        Schema::create('store_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['active', 'starts_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupons');

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_cliente',
                'email_cliente',
                'telefono_cliente',
                'tipo_comprobante',
                'tipo_documento',
                'numero_documento',
                'empresa_facturacion',
                'discount_amount',
                'coupon_code',
                'igv_rate',
                'igv_amount',
            ]);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'telefono',
                'tipo_documento',
                'numero_documento',
                'tipo_comprobante',
                'direccion_entrega',
                'ubicacion_entrega',
            ]);
        });
    }
};
