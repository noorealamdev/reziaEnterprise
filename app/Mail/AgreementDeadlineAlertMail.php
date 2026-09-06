<?php

namespace App\Mail;

use App\Models\CompanyAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class AgreementDeadlineAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, array{agreement: CompanyAgreement, daysRemaining: int}>  $agreements
     */
    public function __construct(
        public Collection $agreements,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->agreements->count();
        $noun = str('agreement')->plural($count);
        $verb = $count === 1 ? 'is' : 'are';

        return new Envelope(
            subject: "Agreement Deadline Alert — {$count} {$noun} {$verb} expiring soon",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agreement-deadline-alert',
            with: [
                'agreements' => $this->agreements,
            ],
        );
    }
}
