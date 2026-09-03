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
     * @param  Collection<int, array<string, mixed>>  $groups
     */
    public function __construct(
        public Collection $groups,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->groups->count();
        $noun = str('group')->plural($count);
        $verb = $count === 1 ? 'needs' : 'need';

        return new Envelope(
            subject: "Unbilled Alert — {$count} {$noun} {$verb} invoicing",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.unbilled-alert',
            with: [
                'groups' => $this->groups,
            ],
        );
    }
}
