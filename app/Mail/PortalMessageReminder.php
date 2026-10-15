<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class PortalMessageReminder extends Mailable
{
    public function __construct(public int $threadId) {}

    public function build(): self
    {
        return $this->subject('You have an unread PIIE portal message')->view('email.portalMessageReminder', [
            'messageUrl' => route('messaging.show', $this->threadId),
        ]);
    }
}
