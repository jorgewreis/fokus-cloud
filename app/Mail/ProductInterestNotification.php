<?php

namespace App\Mail;

use App\Models\ProductInterest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ProductInterestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProductInterest $interest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Novo interesse no Fokus Law · '.$this->interest->name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.product-interest-notification', with: [
            'interest' => $this->interest,
        ]);
    }
}
