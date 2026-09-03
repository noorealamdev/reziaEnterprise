<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Carbon $date,
        public array $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Daily Report — '.$this->date->format('M j, Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.daily-report',
            with: [
                'date' => $this->date,
                ...$this->data,
            ],
        );
    }
}
