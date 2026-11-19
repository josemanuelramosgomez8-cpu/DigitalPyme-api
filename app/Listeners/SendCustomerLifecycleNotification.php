<?php

namespace App\Listeners;

use App\Events\CustomerLifecycleNotificationRequested;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\CustomerLifecycleNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCustomerLifecycleNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(CustomerLifecycleNotificationRequested $event): void
    {
        $user = $event->userId ? User::query()->find($event->userId) : null;
        $recipient = $user?->email ?: $event->recipientEmail;
        $log = $event->sendEmail
            ? NotificationLog::create([
                'event_type' => $event->eventType,
                'recipient_email' => $recipient,
                'channel' => 'mail',
                'status' => 'queued',
                'payload' => [
                    'subject' => $event->subject,
                    'intro' => $event->intro,
                ],
            ])
            : null;

        $notification = new CustomerLifecycleNotification(
            logId: $log?->id,
            eventType: $event->eventType,
            subject: $event->subject,
            intro: $event->intro,
            details: $event->details,
            sendEmail: $event->sendEmail,
            sendDatabase: $event->sendDatabase,
            actionLabel: $event->actionLabel,
            actionUrl: $event->actionUrl,
            unsubscribeUrl: $event->unsubscribeUrl,
            resourceType: $event->resourceType,
            resourceId: $event->resourceId,
        );

        if ($event->sendAt) {
            $notification->delay($event->sendAt);
        }

        try {
            if ($user && $event->sendDatabase) {
                $user->notify($notification);
            } elseif ($event->sendEmail) {
                Notification::route('mail', $recipient)->notify($notification);
            }
        } catch (Throwable $exception) {
            Log::warning('No se pudo entregar una notificación de ciclo de cliente.', [
                'event_type' => $event->eventType,
                'recipient_email' => $recipient,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
