<?php

namespace App\Mail;

use App\Models\StoreOrder;
use App\Support\StoreOrderReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StoreOrderConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StoreOrder $order) {}

    public function envelope(): Envelope
    {
        $subject = $this->order->payment_status === 'pagado'
            ? "Comprobante de pago DigitalPyme #{$this->order->id}"
            : "Confirmación de pedido DigitalPyme #{$this->order->id}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pedido-confirmado',
            with: ['order' => $this->order->loadMissing('items', 'client.user')],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => StoreOrderReceiptPdf::render($this->order),
                'pedido-digitalpyme-'.str_pad((string) $this->order->id, 8, '0', STR_PAD_LEFT).'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
