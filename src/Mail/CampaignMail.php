<?php

namespace Darinlarimore\StatamicEmailSubscribe\Mail;

use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Support\Urls;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class CampaignMail extends Mailable
{
    public function __construct(
        public Sendout $sendout,
        public Subscriber $subscriber,
        public string $htmlBody,
        public bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->sendout->fromEmail(), $this->sendout->fromName()),
            replyTo: array_filter([$this->sendout->reply_to ?: config('email_subscribe.reply_to')]),
            subject: ($this->isTest ? '[Test] ' : '').$this->sendout->subject,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function headers(): Headers
    {
        if ($this->isTest) {
            return new Headers;
        }

        // RFC 8058 one-click unsubscribe - required by Gmail/Yahoo for bulk senders.
        return new Headers(text: [
            'List-Unsubscribe' => '<'.Urls::oneClickUnsubscribe($this->subscriber, $this->sendout).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
