<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentPortalActivationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    /**
     * @param array $data name, email, password, code, programme, intake, school_id
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        if ($this->data['student_password_setup'] ?? false) {
            $student = \App\Models\User::where('email', $this->data['email'])
                ->where('school_id', $this->data['school_id'])->where('role_id', 7)->firstOrFail();
            // Create the expiring token at delivery time so queued retries get a usable link.
            $token = \Illuminate\Support\Facades\Password::broker('users')->createToken($student);
            $this->data['setup_url'] = route('password.reset', ['token' => $token, 'email' => $student->email]);
        }
        return $this->subject(($this->data['student_password_setup'] ?? false)
            ? 'Set your student portal password – PIIE'
            : 'Welcome to Prime International Institute of Excellence – Student Portal Account')
            ->view('email.studentPortalActivationEmail')
            ->from(get_settings('smtp_user'), get_settings('system_title'));
    }
}
