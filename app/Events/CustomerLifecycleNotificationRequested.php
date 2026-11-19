<?php

namespace App\Events;

use DateTimeInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class CustomerLifecycleNotificationRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventType,
        public string $recipientEmail,
        public string $subject,
        public string $intro,
        public array $details = [],
        public ?int $userId = null,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public ?string $unsubscribeUrl = null,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public bool $sendEmail = true,
        public bool $sendDatabase = true,
        public ?DateTimeInterface $sendAt = null,
    ) {}
}
