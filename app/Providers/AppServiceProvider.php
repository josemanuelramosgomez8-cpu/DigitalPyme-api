<?php

namespace App\Providers;

use App\Events\CustomerLifecycleNotificationRequested;
use App\Events\ProjectStatusChanged;
use App\Events\StoreOrderStatusChanged;
use App\Listeners\RecordLifecycleNotificationDelivery;
use App\Listeners\SendCustomerLifecycleNotification;
use App\Listeners\SendProjectStatusNotification;
use App\Listeners\SendStoreOrderStatusNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            CustomerLifecycleNotificationRequested::class,
            SendCustomerLifecycleNotification::class,
        );
        Event::listen(
            StoreOrderStatusChanged::class,
            SendStoreOrderStatusNotification::class,
        );
        Event::listen(
            ProjectStatusChanged::class,
            SendProjectStatusNotification::class,
        );
        Event::listen(
            NotificationSent::class,
            [RecordLifecycleNotificationDelivery::class, 'sent'],
        );
        Event::listen(
            NotificationFailed::class,
            [RecordLifecycleNotificationDelivery::class, 'failed'],
        );
        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::warning('La cola superó el umbral configurado.', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::error('Un trabajo de cola falló definitivamente.', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'exception' => $event->exception::class,
            ]);
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return rtrim(config('services.digitalpyme.frontend_url'), '/').'/restablecer-contrasena?'.$query;
        });
    }
}
