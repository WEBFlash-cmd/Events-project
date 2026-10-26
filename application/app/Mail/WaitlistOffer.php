<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistOffer extends Mailable
{
    public function __construct(public WaitlistEntry $entry)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Освободился билет — '.$this->entry->event->title);
    }

    public function content(): Content
    {
        return new Content(view: 'email.waitlist-offer');
    }
}
