<?php

namespace App\Enums;

enum StoreOrderStatus: string
{
    case Received = 'recibido';
    case Picking = 'en_picking';
    case Packed = 'empacado';
    case Shipped = 'enviado';
    case Delivered = 'entregado';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Picking => 'En preparación',
            self::Packed => 'Empacado',
            self::Shipped => 'En camino',
            self::Delivered => 'Entregado',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Received => self::Picking,
            self::Picking => self::Packed,
            self::Packed => self::Shipped,
            self::Shipped => self::Delivered,
            self::Delivered => null,
        };
    }
}
