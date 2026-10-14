<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Every applicant-facing email, from one mailable.
 *
 * Six near-identical Mailable classes differing only in a subject line and a
 * paragraph is six places to forget to update when the institution rebrands.
 * The variable part is passed as data; the shape (heading, body paragraphs,
 * a detail table, one call to action) is fixed by the template and is enough
 * for every message the admissions flow sends.
 *
 * Subjects are built by App\Support\Admissions\ApplicantNotifier — this class
 * deliberately holds no copy of its own.
 */
class ApplicantNotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public array $data;

    /**
     * @param array $data subject, heading, greeting, paragraphs[], details[],
     *                    cta_label, cta_url, footer_note, school_id
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function build()
    {
        if (! empty($this->data['admission_letter'])) {
            $letter = $this->data['admission_letter'];
            $this->attachData(\App\Support\Admissions\AdmissionLetterDocument::pdf($letter)->output(),
                \App\Support\Admissions\AdmissionLetterDocument::filename($letter), ['mime' => 'application/pdf']);
            if (filter_var($letter['email'] ?? null, FILTER_VALIDATE_EMAIL)) $this->replyTo($letter['email']);
        }
        return $this->subject($this->data['subject'] ?? 'Your application')
            ->view('email.applicantNotification')
            ->from(get_settings('smtp_user'), get_settings('system_title'));
    }
}
