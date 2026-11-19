<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\CustomerLifecycleNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

class RecordLifecycleNotificationDelivery
{
    public function sent(NotificationSent $event): void
    {
        if (
            $event->channel !== 'mail'
            || ! $event->notification instanceof CustomerLifecycleNotification
            || ! $event->notification->logId
        ) {
            return;
        }

        NotificationLog::query()->whereKey($event->notification->logId)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'failure_message' => null,
        ]);
    }

    public function failed(NotificationFailed $event): void
    {
        if (
            $event->channel !== 'mail'
            || ! $event->notification instanceof CustomerLifecycleNotification
            || ! $event->notification->logId
        ) {
            return;
        }

        $exception = $event->data['exception'] ?? null;
        $failureMessage = $exception instanceof Throwable
            ? $exception->getMessage()
            : 'No se recibió el detalle del error de entrega.';

        NotificationLog::query()->whereKey($event->notification->logId)->update([
            'status' => 'failed',
            'failure_message' => mb_substr($failureMessage, 0, 1000),
        ]);
    }
}
