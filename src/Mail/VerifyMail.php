<?php

namespace Darinlarimore\Postmaster\Mail;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Support\Urls;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyMail extends Mailable
{
    public function __construct(public SubscriberContract $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('postmaster.from.email'), config('postmaster.from.name')),
            subject: __('Confirm your subscription'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'postmaster::emails.verify',
            with: [
                'subscriber' => $this->subscriber,
                'verifyUrl' => Urls::verify($this->subscriber),
            ],
        );
    }
}
