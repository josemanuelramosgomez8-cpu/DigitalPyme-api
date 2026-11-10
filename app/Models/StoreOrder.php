<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StoreOrder extends Model
{
    public const ESTADOS = ['recibido', 'en_picking', 'empacado', 'enviado', 'entregado'];

    public const PAYMENT_METHODS = ['bank_transfer', 'yape_plin', 'card'];

    public const PAYMENT_STATUSES = ['pendiente', 'comprobante_enviado', 'pagado', 'rechazado', 'expirado'];

    protected $fillable = [
        'client_id',
        'nombre_cliente',
        'email_cliente',
        'telefono_cliente',
        'tipo_comprobante',
        'tipo_documento',
        'numero_documento',
        'empresa_facturacion',
        'estado',
        'payment_method',
        'payment_status',
        'provider_reference',
        'provider_preference_id',
        'provider_payment_id',
        'provider_checkout_url',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime_type',
        'receipt_size',
        'receipt_uploaded_at',
        'paid_at',
        'payment_due_at',
        'tipo_operacion',
        'operador_logistico',
        'metodo_envio',
        'ubicacion_entrega',
        'direccion_entrega',
        'codigo_rastreo',
        'subtotal',
        'costo_envio',
        'discount_amount',
        'coupon_code',
        'igv_rate',
        'igv_amount',
        'total',
        'enviado_at',
        'entregado_at',
    ];

    protected $hidden = ['receipt_path', 'provider_reference', 'provider_preference_id', 'provider_checkout_url'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'costo_envio' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'igv_rate' => 'decimal:2',
            'igv_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'receipt_size' => 'integer',
            'receipt_uploaded_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'enviado_at' => 'datetime',
            'entregado_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class, 'order_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'order_id');
    }

    public function returnRequest(): HasOne
    {
        return $this->hasOne(StoreReturn::class, 'order_id');
    }
}
