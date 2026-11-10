<?php

namespace App\Mail;

use App\Models\ProjectPayment;
use App\Support\PaymentReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PagoConfirmado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProjectPayment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Recibo de pago DigitalPyme #{$this->payment->id}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pago-confirmado',
            with: ['payment' => $this->payment->loadMissing([
                'project.client.user',
                'project.cotizacion',
                'milestone',
            ])],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => PaymentReceiptPdf::render($this->payment),
                'recibo-digitalpyme-'.str_pad((string) $this->payment->id, 8, '0', STR_PAD_LEFT).'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
