<?php

namespace App\Services;

use App\Events\CustomerLifecycleNotificationRequested;
use DateTimeInterface;

class CustomerLifecycleNotifier
{
    public function notify(
        string $eventType,
        string $email,
        string $subject,
        string $intro,
        array $details = [],
        ?int $userId = null,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
        ?string $unsubscribeUrl = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        bool $sendEmail = true,
        bool $sendDatabase = true,
        ?DateTimeInterface $sendAt = null,
    ): void {
        if (blank($email) && ! $userId) {
            return;
        }

        event(new CustomerLifecycleNotificationRequested(
            eventType: $eventType,
            recipientEmail: $email,
            subject: $subject,
            intro: $intro,
            details: $details,
            userId: $userId,
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
            unsubscribeUrl: $unsubscribeUrl,
            resourceType: $resourceType,
            resourceId: $resourceId,
            sendEmail: $sendEmail,
            sendDatabase: $sendDatabase,
            sendAt: $sendAt,
        ));
    }
}
