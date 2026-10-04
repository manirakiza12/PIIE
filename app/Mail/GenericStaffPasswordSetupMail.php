<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Support\Mail\PlatformMailIdentity;

class GenericStaffPasswordSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $staffName, public string $setupUrl)
    {
    }

    public function build(): self
    {
        $mail = $this->subject('Set up your PIIE staff account')
            ->view('email.genericStaffPasswordSetup');

        if ($fromAddress = PlatformMailIdentity::fromAddress()) {
            $mail->from($fromAddress, PlatformMailIdentity::fromName());
        }

        return $mail;
    }
}
