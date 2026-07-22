<?php

namespace Darinlarimore\StatamicEmailSubscribe\Mail;

use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Support\Urls;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UnsubscribeLinkMail extends Mailable
{
    public function __construct(public Subscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('email_subscribe.from.email'), config('email_subscribe.from.name')),
            subject: __('Confirm your unsubscribe request'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email-subscribe::emails.unsubscribe-link',
            with: [
                'subscriber' => $this->subscriber,
                'unsubscribeUrl' => Urls::unsubscribe($this->subscriber),
                'preferencesUrl' => Urls::preferences($this->subscriber),
            ],
        );
    }
}
