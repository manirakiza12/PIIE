<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalCredentialStorage;
use App\Support\Payments\PesaPalException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplicantPesaPalSettingsController extends Controller
{
    public function index(Request $request)
    {
        $school = (int) $request->user()->school_id;
        $rows = PaymentMethods::where('school_id', $school)->where('name', 'pesapal')->where('status', 1)->get();
        $keys = [];
        $configurationError = null;
        // Never choose one of several active rows automatically: that could
        // display or overwrite credentials belonging to a different order.
        $configurationWritable = $rows->count() <= 1;
        if (! $configurationWritable) {
            $configurationError = 'Multiple active PesaPal configurations exist for this school. Payments are blocked until exactly one is active. Resolve the duplicate rows; this page will not choose one automatically.';
        } elseif ($rows->isNotEmpty()) {
            try {
                $keys = PesaPalCredentialStorage::read((string) $rows->first()->payment_keys, $school);
            } catch (PesaPalException $exception) {
                // The page must stay reachable so an administrator can recover
                // by re-entering both credentials; no secret is displayed.
                $configurationError = 'Stored PesaPal credentials cannot be read. Payments are blocked until both credentials are re-entered below.';
            }
        }
        $notificationStorageReady = \Illuminate\Support\Facades\Schema::hasTable('applicant_notification_deliveries');
        $deliveries = $notificationStorageReady
            ? DB::table('applicant_notification_deliveries')->where('school_id', $school)
                ->whereNull('sent_at')->select(['id', 'attempts', 'available_at'])->orderByDesc('id')->limit(20)->get() : collect();
        return view('admin.admissions.pesapal_settings', ['environment' => $keys['environment'] ?? 'sandbox',
            'configured' => filled($keys['consumer_key'] ?? null) && filled($keys['consumer_secret'] ?? null),
            'notificationId' => $keys['notification_id'] ?? null, 'deliveries' => $deliveries,
            'notificationStorageReady' => $notificationStorageReady,
            'configurationError' => $configurationError,
            'configurationWritable' => $configurationWritable]);
    }

    public function retryNotification(Request $request, int $delivery)
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('applicant_notification_deliveries'), 503);
        $row = DB::table('applicant_notification_deliveries')->where('id', $delivery)->where('school_id', $request->user()->school_id)
            ->whereNull('sent_at')->where('attempts', '>=', 5)->where('available_at', '<=', now())->first();
        abort_unless($row, 404);
        DB::table('applicant_notification_deliveries')->where('id', $row->id)->whereNull('sent_at')->update(['attempts' => 0, 'available_at' => now()]);
        $sent = \App\Support\Admissions\ApplicantNotificationDelivery::deliver($row->id);
        return back()->with($sent ? 'success' : 'error', $sent ? 'Notification handed to the mailer.' : 'Notification is still undelivered. Check SMTP configuration.');
    }

    public function save(Request $request)
    {
        $data = $request->validate(['environment' => 'required|in:sandbox,live',
            'consumer_key' => 'nullable|string|max:512', 'consumer_secret' => 'nullable|string|max:512']);
        DB::transaction(function () use ($request, $data) {
            $school = (int) $request->user()->school_id;
            $rows = PaymentMethods::where('school_id', $school)->where('name', 'pesapal')->where('status', 1)->lockForUpdate()->get();
            if ($rows->count() > 1) {
                throw ValidationException::withMessages(['configuration' => 'Duplicate PesaPal configurations. Resolve them before saving; no configuration was changed.']);
            }
            // Never strand an outstanding order by switching its credentials.
            if (ApplicationPayment::where('school_id', $school)->where('method', 'pesapal')->where('status', 'pending')->exists()
                || \App\Models\TuitionPayment::where('school_id', $school)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['configuration' => 'Resolve outstanding PesaPal orders before replacing configuration; no configuration was changed.']);
            }
            $row = $rows->first() ?? new PaymentMethods(['school_id' => $school, 'name' => 'pesapal']);
            try {
                $old = $row->exists ? PesaPalCredentialStorage::read((string) $row->payment_keys, $school) : [];
            } catch (PesaPalException $exception) {
                // Unreadable credentials cannot be merged or compared. Treat
                // them as absent so both fields must be re-entered and the
                // unreadable value is never reused.
                $old = [];
            }
            if (($old['environment'] ?? $data['environment']) !== $data['environment']
                && (blank($data['consumer_key'] ?? null) || blank($data['consumer_secret'] ?? null))) {
                throw ValidationException::withMessages(['environment' => 'Supply both credentials when changing environment.']);
            }
            foreach (['consumer_key', 'consumer_secret'] as $field) {
                $data[$field] = filled($data[$field] ?? null) ? $data[$field] : ($old[$field] ?? null);
                if (blank($data[$field])) { throw ValidationException::withMessages([$field => 'Credentials are required for initial setup or recovery.']); }
            }
            if ($row->exists && $data['environment'] === ($old['environment'] ?? null)
                && $data['consumer_key'] === ($old['consumer_key'] ?? null)
                && $data['consumer_secret'] === ($old['consumer_secret'] ?? null)) {
                $row->save(); // Encrypt a legacy row without changing its IPN or identity.
                return;
            }
            if ($row->exists && (ApplicationPayment::where('school_id', $school)->where('method', 'pesapal')->exists() || \App\Models\TuitionPayment::where('school_id', $school)->exists())) {
                $row->update(['status' => 0]);
                $row = new PaymentMethods(['school_id' => $school, 'name' => 'pesapal']);
            }
            $row->fill(['status' => 1, 'mode' => $data['environment'] === 'sandbox' ? 'test' : 'live',
                'payment_keys' => json_encode($data)])->save();
        });
        return back()->with('success', 'Configuration saved. Register the IPN before accepting payments.');
    }

    public function register(Request $request)
    {
        $school = (int) $request->user()->school_id;
        try {
            $configuration = PesaPalConfiguration::forSchool($school);
            $registration = new \App\Support\Payments\PesaPalIpnRegistration;
            $url = route('applicant.pesapal.ipn');
            $ipn = $registration->register($configuration, $url);
            $registration->persist($configuration, $ipn, $url);
            return back()->with('success', 'PesaPal IPN registered. Sandbox checkout still requires end-to-end testing.');
        } catch (PesaPalException $exception) {
            return back()->with('error', 'PesaPal IPN registration could not be verified. Check credentials and the public HTTPS application URL.');
        }
    }
}
