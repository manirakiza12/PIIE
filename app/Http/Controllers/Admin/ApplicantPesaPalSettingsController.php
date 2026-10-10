<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ApplicantPesaPalSettingsController extends Controller
{
    public function index(Request $request)
    {
        $rows = PaymentMethods::where('school_id', $request->user()->school_id)->where('name', 'pesapal')->where('status', 1)->get();
        abort_if($rows->count() > 1, 422, 'Resolve duplicate PesaPal configurations before proceeding.');
        $keys = json_decode((string) $rows->first()?->payment_keys, true) ?? [];
        $notificationStorageReady = \Illuminate\Support\Facades\Schema::hasTable('applicant_notification_deliveries');
        $deliveries = $notificationStorageReady
            ? DB::table('applicant_notification_deliveries')->where('school_id', $request->user()->school_id)
                ->whereNull('sent_at')->select(['id', 'attempts', 'available_at'])->orderByDesc('id')->limit(20)->get() : collect();
        return view('admin.admissions.pesapal_settings', ['environment' => $keys['environment'] ?? 'sandbox',
            'configured' => $rows->isNotEmpty() && (isset($keys['encrypted_credentials']) || (filled($keys['consumer_key'] ?? null) && filled($keys['consumer_secret'] ?? null))),
            'notificationId' => $keys['notification_id'] ?? null, 'deliveries' => $deliveries,
            'notificationStorageReady' => $notificationStorageReady]);
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
            abort_if($rows->count() > 1, 422, 'Duplicate PesaPal configurations.');
            // Never strand an outstanding order by switching its credentials.
            abort_if(ApplicationPayment::where('school_id', $school)->where('method', 'pesapal')->where('status', 'pending')->exists(), 422, 'Resolve outstanding PesaPal orders before replacing configuration.');
            $row = $rows->first() ?? new PaymentMethods(['school_id' => $school, 'name' => 'pesapal']);
            $old = $row->exists ? \App\Support\Payments\PesaPalCredentialStorage::read((string) $row->payment_keys, $school) : [];
            if (($old['environment'] ?? $data['environment']) !== $data['environment']
                && (blank($data['consumer_key'] ?? null) || blank($data['consumer_secret'] ?? null))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['environment' => 'Supply both credentials when changing environment.']);
            }
            foreach (['consumer_key', 'consumer_secret'] as $field) {
                $data[$field] = filled($data[$field] ?? null) ? $data[$field] : ($old[$field] ?? null);
                if (blank($data[$field])) { throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Credentials are required for initial setup.']); }
            }
            if ($row->exists && $data['environment'] === ($old['environment'] ?? null)
                && $data['consumer_key'] === ($old['consumer_key'] ?? null)
                && $data['consumer_secret'] === ($old['consumer_secret'] ?? null)) {
                $row->save(); // Encrypt a legacy row without changing its IPN or identity.
                return;
            }
            if ($row->exists && ApplicationPayment::where('school_id', $school)->where('method', 'pesapal')->exists()) {
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
