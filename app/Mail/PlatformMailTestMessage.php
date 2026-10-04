<?php

namespace App\Mail;

use App\Support\Mail\PlatformMailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PlatformMailTestMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        $mail = $this->subject('PIIE email configuration test')
            ->view('email.platformMailTest');

        if ($from = PlatformMailIdentity::fromAddress()) {
            $mail->from($from, PlatformMailIdentity::fromName());
        }

        return $mail;
    }
}
