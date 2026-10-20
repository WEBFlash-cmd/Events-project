<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['event.venue', 'tickets.ticketType']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Покупка подтверждена — заказ №{$this->order->id}");
    }

    public function content(): Content
    {
        return new Content(view: 'email.purchase-confirmation');
    }
}
