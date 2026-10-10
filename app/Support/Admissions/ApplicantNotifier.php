<?php

namespace App\Support\Admissions;

use App\Mail\ApplicantNotificationEmail;
use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\Applicant;
use App\Models\ApplicationPayment;
use Illuminate\Support\Facades\Mail;

/**
 * Every email the admissions flow sends to an applicant.
 *
 * Two rules hold for all of them:
 *  - sending is gated on SMTP actually being configured in global_settings,
 *    matching App\Support\StudentPortalActivation. An institution that has
 *    not set up mail should still be able to take applications.
 *  - a failed send never breaks the action that triggered it. An applicant
 *    who submits an application has submitted it, whether or not the
 *    confirmation email got out; the failure is reported, not raised.
 */
class ApplicantNotifier
{
    public static function paymentReversed(Admission $admission, ApplicationPayment $payment): bool
    {
        return self::send($admission->email, ['subject' => 'Application payment reversed', 'heading' => 'Payment reversal verified',
            'greeting' => 'Dear applicant,', 'paragraphs' => ['PesaPal reports a reversal of your application payment. Contact finance before making another payment.'],
            'details' => ['Application Number' => $admission->app_number, 'Payment Reference' => $payment->reference],
            'cta_url' => route('applicant.login'), 'cta_label' => 'Open applicant portal', 'school_id' => $admission->school_id]);
    }
    public static function isConfigured(): bool
    {
        return ! empty(get_settings('smtp_user'))
            && ! empty(get_settings('smtp_pass'))
            && ! empty(get_settings('smtp_host'))
            && ! empty(get_settings('smtp_port'));
    }

    private static function send(?string $to, array $data): bool
    {
        if (filled($to) && \Illuminate\Support\Facades\Schema::hasTable('applicant_notification_deliveries')) {
            $id = ApplicantNotificationDelivery::record($to, $data);
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => ApplicantNotificationDelivery::deliver($id));
                return true;
            }
            return ApplicantNotificationDelivery::deliver($id);
        }
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => self::send($to, $data));
            return true;
        }
        if (blank($to) || ! self::isConfigured()) {
            return false;
        }

        try {
            Mail::to($to)->send(new ApplicantNotificationEmail($data));

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Applicant notification failed; durable delivery migration is not installed.');

            return false;
        }
    }

    public static function welcome(Applicant $applicant, ?Admission $admission = null): bool
    {
        return self::send($applicant->email, [
            'subject'  => get_phrase('Your applicant account is ready'),
            'heading'  => get_phrase('Welcome to the Applicant Portal'),
            'greeting' => get_phrase('Dear') . ' ' . $applicant->full_name . ',',
            'paragraphs' => [
                get_phrase('Your applicant account has been created. You can now complete your application at your own pace — everything you enter is saved as you go, so you can stop and come back at any time.'),
            ],
            'details' => array_filter([
                get_phrase('Login Email')          => $applicant->email,
                get_phrase('Application Number')   => $admission->app_number ?? null,
            ]),
            'cta_label'   => get_phrase('Continue My Application'),
            'cta_url'     => route('applicant.dashboard'),
            'footer_note' => get_phrase('If you did not create this account, please ignore this email or contact the admissions office.'),
            'school_id'   => $applicant->school_id,
        ]);
    }

    public static function submitted(Admission $admission): bool
    {
        return self::send($admission->email, [
            'subject'  => get_phrase('Application received') . ' — ' . $admission->app_number,
            'heading'  => get_phrase('We have received your application'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => [
                get_phrase('Thank you for applying. Your application has been submitted to the admissions office and is now queued for review.'),
                get_phrase('You can follow its progress at any time from the Track My Application page in your portal. We will email you whenever the status changes.'),
            ],
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Programme')          => optional($admission->programme)->name,
                get_phrase('Intake')             => optional($admission->intakeSession)->name,
                get_phrase('Submitted On')       => optional($admission->submitted_at)->format('d M Y, H:i'),
            ]),
            'cta_label' => get_phrase('Track My Application'),
            'cta_url'   => route('applicant.track'),
            'school_id' => $admission->school_id,
        ]);
    }

    /**
     * Status transitions the applicant is told about. Internal-only moves
     * (e.g. back to 'submitted' from 'under_review' while reviewers shuffle a
     * file) are absent on purpose — the applicant should not receive mail for
     * a change that means nothing to them.
     */
    public static function statusChanged(Admission $admission, string $fromStatus): bool
    {
        $copy = [
            Admission::STATUS_UNDER_REVIEW => [
                'subject'    => get_phrase('Your application is under review'),
                'heading'    => get_phrase('Your application is being reviewed'),
                'paragraphs' => [get_phrase('The admissions committee has started reviewing your application. No action is needed from you right now — we will be in touch as soon as a decision is made.')],
            ],
            Admission::STATUS_ACCEPTED => [
                'subject'    => get_phrase('Congratulations — you have been offered a place'),
                'heading'    => get_phrase('You have been offered a place'),
                'paragraphs' => [get_phrase('Congratulations. Your application has been successful and an offer has been made. Your offer letter is available for download from your portal.')],
            ],
            Admission::STATUS_REJECTED => [
                'subject'    => get_phrase('An update on your application'),
                'heading'    => get_phrase('An update on your application'),
                'paragraphs' => [get_phrase('Thank you for your interest in studying with us. After careful consideration, we are unable to offer you a place for this intake.')],
            ],
            Admission::STATUS_ENROLLED => [
                'subject'    => get_phrase('You are now enrolled'),
                'heading'    => get_phrase('Your enrolment is complete'),
                'paragraphs' => [get_phrase('Your admission has been completed and your student account has been created. You will receive your student portal login details in a separate email.')],
            ],
            Admission::STATUS_WITHDRAWN => [
                'subject'    => get_phrase('Your application has been withdrawn'),
                'heading'    => get_phrase('Your application has been withdrawn'),
                'paragraphs' => [get_phrase('Your application has been marked as withdrawn. If this was not your intention, please contact the admissions office.')],
            ],
        ];

        if (! isset($copy[$admission->status])) {
            return false;
        }

        $message    = $copy[$admission->status];
        $paragraphs = $message['paragraphs'];

        if (! blank($admission->decision_note)) {
            $paragraphs[] = '<strong>' . get_phrase('Note from the admissions office') . ':</strong><br>' . e($admission->decision_note);
        }

        return self::send($admission->email, [
            'subject'    => $message['subject'] . ' — ' . $admission->app_number,
            'heading'    => $message['heading'],
            'greeting'   => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => $paragraphs,
            'details'    => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Programme')          => optional($admission->programme)->name,
                get_phrase('Previous Status')    => ucwords(str_replace('_', ' ', $fromStatus)),
                get_phrase('Current Status')     => $admission->statusLabel(),
            ]),
            'cta_label' => get_phrase('View My Application'),
            'cta_url'   => route('applicant.track'),
            'school_id' => $admission->school_id,
        ]);
    }

    public static function correctionRequested(Admission $admission, ?string $note = null): bool
    {
        return self::send($admission->email, [
            'subject'  => get_phrase('Action needed on your application') . ' — ' . $admission->app_number,
            'heading'  => get_phrase('We need something from you'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => array_filter([
                get_phrase('Your application has been returned for corrections. It is not rejected — make the changes and resubmit to go straight back into the review queue.'),
                blank($note) ? null : '<strong>' . get_phrase('What needs to change') . ':</strong><br>' . nl2br(e($note)),
            ]),
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Programme')          => optional($admission->programme)->name,
            ]),
            'cta_label' => get_phrase('Update My Application'),
            'cta_url'   => route('applicant.dashboard'),
            'school_id' => $admission->school_id,
        ]);
    }

    public static function documentRejected(Admission $admission, AdmissionDocument $document): bool
    {
        return self::send($admission->email, [
            'subject'  => get_phrase('A document needs to be re-uploaded') . ' — ' . $admission->app_number,
            'heading'  => get_phrase('One of your documents was not accepted'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => array_filter([
                get_phrase('A document you uploaded could not be accepted. Please upload a replacement from the Documents page of your portal.'),
                blank($document->review_note) ? null : '<strong>' . get_phrase('Reviewer note') . ':</strong><br>' . nl2br(e($document->review_note)),
            ]),
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Document')           => $document->label ?: $document->original_name,
            ]),
            'cta_label' => get_phrase('Upload a Replacement'),
            'cta_url'   => route('applicant.documents'),
            'school_id' => $admission->school_id,
        ]);
    }

    public static function paymentReceived(Admission $admission, ApplicationPayment $payment): bool
    {
        $confirmed = $payment->status === ApplicationPayment::STATUS_PAID;
        $settled = ApplicationFee::isSettled($admission);

        return self::send($admission->email, [
            'subject'  => ($confirmed ? get_phrase('Application fee received') : get_phrase('Application fee awaiting confirmation')) . ' — ' . $admission->app_number,
            'heading'  => $confirmed ? get_phrase('Your application fee has been received') : get_phrase('We have your payment details'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => [
                $confirmed
                    ? ($settled
                        ? get_phrase('Your application fee has been confirmed. This step of your application is now complete.')
                        : get_phrase('Your payment has been confirmed. Your application fee is not yet fully paid; please pay the remaining balance.'))
                    : get_phrase('We have received your payment details and they are being verified by the finance office. This usually takes one to two working days.'),
            ],
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Amount')             => ApplicationFee::format((float) $payment->amount, $admission),
                get_phrase('Method')             => ucfirst($payment->method),
                get_phrase('Reference')          => $payment->reference,
            ]),
            'cta_label' => get_phrase('View My Application'),
            'cta_url'   => route('applicant.dashboard'),
            'school_id' => $admission->school_id,
        ]);
    }

    public static function paymentRejected(Admission $admission, ApplicationPayment $payment): bool
    {
        return self::send($admission->email, [
            'subject'  => get_phrase('Your application fee payment could not be confirmed') . ' — ' . $admission->app_number,
            'heading'  => get_phrase('We could not confirm your payment'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => array_filter([
                get_phrase('The finance office was unable to confirm the application fee payment you submitted. Please check the details and submit them again from your portal.'),
                blank($payment->note) ? null : '<strong>' . get_phrase('Note') . ':</strong><br>' . nl2br(e($payment->note)),
            ]),
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Amount')             => ApplicationFee::format((float) $payment->amount, $admission),
                get_phrase('Reference')          => $payment->reference,
            ]),
            'cta_label' => get_phrase('Resubmit Payment Details'),
            'cta_url'   => route('applicant.payment'),
            'school_id' => $admission->school_id,
        ]);
    }

    /**
     * The staff-entry counterpart of the online applicant simply reaching
     * the payment step themselves: an administrator entered this
     * application, so the candidate needs to be told it exists and given a
     * way to pay. $portalUrl is a one-time reset-password link into the
     * applicant portal (see ApplicantPortalAccess) — the same portal, same
     * payment page, same gateways an online applicant would use.
     *
     * $isReminder only changes the subject/copy; it is never a second
     * payment record or a second application (see
     * AdmissionsController::resendPaymentInstructions()).
     */
    public static function staffEntryPaymentRequest(Admission $admission, string $portalUrl, bool $isReminder = false, ?string $accessUrl = null): bool
    {
        $amount = ApplicationFee::amountFor($admission);

        return self::send($admission->email, [
            'subject'  => ($isReminder
                ? get_phrase('Reminder: application fee payment required')
                : get_phrase('An application has been created for you')) . ' — ' . $admission->app_number,
            'heading'  => $isReminder
                ? get_phrase('Your application fee is still outstanding')
                : get_phrase('An application has been created for you'),
            'greeting' => get_phrase('Dear') . ' ' . $admission->full_name . ',',
            'paragraphs' => [
                $isReminder
                    ? get_phrase('Your application fee is outstanding. Use your secure link to pay through PesaPal or check an existing payment.')
                    : get_phrase('An application has been created for you at') . ' ' . (get_settings('system_title') ?: 'the institution') . '. ' . get_phrase('Use your secure link to pay through PesaPal. Payment is verified with the provider.'),
            ],
            'details' => array_filter([
                get_phrase('Application Number') => $admission->app_number,
                get_phrase('Programme')          => optional($admission->programme)->name,
                get_phrase('Application Fee')    => $amount > 0 ? ApplicationFee::format($amount, $admission) : get_phrase('None for this intake'),
                get_phrase('Payment Reference')  => $admission->app_number,
                get_phrase('Payment Status')     => ucfirst($admission->fee_status),
            ]),
            'cta_label'   => get_phrase('Pay or Check PesaPal Payment'),
            'cta_url'     => $portalUrl,
            'access_url'  => $accessUrl,
            'payment_invitation_admission_id' => $admission->id,
            'footer_note' => get_phrase('Keep this personal payment link private. It expires after 60 minutes. If you were not expecting it, contact the admissions office.'),
            'school_id'   => $admission->school_id,
        ]);
    }

    public static function passwordReset(Applicant $applicant, string $token): bool
    {
        return self::send($applicant->email, [
            'subject'  => get_phrase('Reset your applicant portal password'),
            'heading'  => get_phrase('Password reset request'),
            'greeting' => get_phrase('Dear') . ' ' . $applicant->full_name . ',',
            'paragraphs' => [
                get_phrase('We received a request to reset the password for your applicant portal account. Use the button below to choose a new one.'),
            ],
            'cta_label'   => get_phrase('Reset My Password'),
            'cta_url'     => route('applicant.password.reset', ['token' => $token, 'email' => $applicant->email]),
            'footer_note' => get_phrase('This link expires in 60 minutes. If you did not request a password reset, no action is needed — your password will stay as it is.'),
            'school_id'   => $applicant->school_id,
        ]);
    }
}
