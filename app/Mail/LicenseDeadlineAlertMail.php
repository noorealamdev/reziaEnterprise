<?php

namespace App\Mail;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class LicenseDeadlineAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, array{license: License, daysRemaining: int}>  $licenses
     */
    public function __construct(
        public Collection $licenses,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->licenses->count();
        $noun = str('license')->plural($count);
        $verb = $count === 1 ? 'is' : 'are';

        return new Envelope(
            subject: "License Deadline Alert — {$count} {$noun} {$verb} expiring soon",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.license-deadline-alert',
            with: [
                'licenses' => $this->licenses,
            ],
        );
    }
}
