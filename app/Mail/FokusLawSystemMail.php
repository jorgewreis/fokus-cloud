<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FokusLawSystemMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $formattedCode;

    /**
     * @param array<int, array{label: string, value: string}> $details
     */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public string $intro,
        public string $preheader,
        public ?string $code = null,
        public ?string $codeLabel = null,
        public ?string $expiry = null,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public ?string $actionExpiryLabel = null,
        public ?string $securityTitle = null,
        public ?string $securityText = null,
        public array $details = [],
        public string $footerMessage = 'Mais clareza para conduzir a operação jurídica do seu escritório.',
        public string $footerNotice = 'Mensagem automática de segurança · fokuscloud.com.br',
    ) {
        $this->formattedCode = $code !== null && preg_match('/^\d{6}$/', $code)
            ? substr($code, 0, 3).' '.substr($code, 3)
            : $code;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.fokus-law-system');
    }
}
