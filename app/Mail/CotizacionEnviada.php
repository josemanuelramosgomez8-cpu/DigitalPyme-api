<?php

namespace App\Mail;

use App\Models\Cotizacion;
use App\Support\CotizacionPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CotizacionEnviada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Cotizacion $cotizacion) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu cotización de DigitalPyme #{$this->cotizacion->id}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cotizacion-enviada',
            with: ['cotizacion' => $this->cotizacion->loadMissing('lead')],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => CotizacionPdf::render($this->cotizacion),
                "cotizacion-digitalpyme-{$this->cotizacion->id}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
