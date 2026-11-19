<?php

namespace App\Events;

use App\Models\StoreOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class StoreOrderStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public StoreOrder $order,
        public string $previousStatus,
        public string $status,
    ) {}
}
