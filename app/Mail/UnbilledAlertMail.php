<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class UnbilledAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, array<string, mixed>>  $bills
     */
    public function __construct(
        public Collection $bills,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Late Bills — '.number_format($this->bills->sum('amount'), 2).' not submitted to the factory',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.unbilled-alert',
            with: [
                'bills' => $this->bills,
            ],
        );
    }
}
