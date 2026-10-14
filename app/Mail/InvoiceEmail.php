<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Mail\Mailable;

class InvoiceEmail extends Mailable
{
    public function __construct(public array $invoice, public array $billing) {}

    public function build()
    {
        $school = School::findOrFail($this->invoice['school_id']);
        return $this->subject('Invoice '.sprintf('%08d', $this->invoice['id']).' — '.$this->invoice['title'])
            ->from(get_settings('smtp_user'), $school->title)
            ->view('email.invoice', ['school' => $school, 'invoice' => $this->invoice, 'billing' => $this->billing]);
    }
}
