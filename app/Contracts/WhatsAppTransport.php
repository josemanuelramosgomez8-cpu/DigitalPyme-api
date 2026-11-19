<?php

namespace App\Contracts;

interface WhatsAppTransport
{
    public function send(string $destination, string $message): void;
}
