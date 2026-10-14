<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccountConnection;
use App\Models\GoogleMeetSetting;
use App\Models\School;
use App\Support\Google\GoogleAccountService;
use App\Support\Google\GoogleMeetSetup;
use App\Support\Google\GoogleOAuthClient;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoogleMeetSettingsController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless((int) $request->user()->role_id === 1, 403);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $settings = GoogleMeetSetup::settings();
        $owner = GoogleMeetSetup::owner();
        $connection = $owner ? app(GoogleAccountService::class)->forUser($owner) : null;
        return response()->view('superadmin.settings.google_meet', [
            'settings' => $settings, 'schools' => School::orderBy('title')->get(['id', 'title']),
            'connection' => $connection, 'configured' => GoogleOAuthCredentials::isConfigured(),
            'callbackUrls' => GoogleMeetSetup::callbackUrls(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
            'client_id' => 'nullable|required_without:credentials_file|string|max:255',
            'client_secret' => 'nullable|string|max:4096',
            'credentials_file' => 'nullable|file|max:32',
            'calendar_id' => 'required|string|max:255', 'enabled' => 'nullable|boolean',
        ]);
        if ($request->hasFile('credentials_file')) {
            try { $json = json_decode(file_get_contents($request->file('credentials_file')->getRealPath()), true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw ValidationException::withMessages(['credentials_file' => 'Upload a valid Google Web application credentials JSON file.']); }
            if (!is_array($json['web'] ?? null)) throw ValidationException::withMessages(['credentials_file' => 'Choose an OAuth client of type Web application. API keys and Desktop clients are not supported.']);
            $data['client_id'] = $json['web']['client_id'] ?? null;
            $data['client_secret'] = $json['web']['client_secret'] ?? null;
        }
        if (!is_string($data['client_id'] ?? null) || !preg_match('/^[a-zA-Z0-9._-]+\.apps\.googleusercontent\.com$/', $data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => 'Enter the OAuth Client ID from a Google Web application client.']);
        }
        $oldCredentials = null;
        try { $oldCredentials = GoogleOAuthCredentials::read(); } catch (\RuntimeException $e) {}
        if ($stored = GoogleMeetSetup::settings()) {
            $savedSecret = $oldCredentials['client_secret'] ?? null;
            if (!$stored->enabled) { try { $savedSecret = $stored->client_secret; } catch (\RuntimeException $e) {} }
            $oldCredentials = ['client_id' => $stored->client_id, 'client_secret' => $savedSecret];
        }
        $secret = $data['client_secret'] ?? null;
        if ($secret === null || $secret === '') {
            if ($request->hasFile('credentials_file') || ($oldCredentials['client_id'] ?? null) !== $data['client_id']) {
                throw ValidationException::withMessages(['client_secret' => 'Provide the secret for this OAuth client.']);
            }
            $secret = $oldCredentials['client_secret'] ?? null;
        }
        if (!is_string($secret) || $secret === '' || strlen($secret) > 4096) throw ValidationException::withMessages(['client_secret' => 'Enter the client secret when configuring Google for the first time.']);
        DB::transaction(function () use ($request, $data, $secret, $oldCredentials) {
            $settings = GoogleMeetSetting::lockForUpdate()->find(1) ?? new GoogleMeetSetting(['id' => 1]);
            $changedClient = ($oldCredentials['client_id'] ?? null) !== $data['client_id'];
            if ($changedClient && $oldCredentials !== null) {
                GoogleAccountConnection::query()->update(['status' => GoogleAccountConnection::STATUS_NEEDS_REAUTH, 'access_token_ciphertext' => null, 'refresh_token_ciphertext' => null]);
            }
            if ($changedClient || ($settings->exists && (int) $settings->school_id !== (int) $data['school_id'])) $settings->platform_user_id = null;
            $settings->fill(['school_id' => $data['school_id'], 'client_id' => $data['client_id'], 'client_secret' => $secret,
                'calendar_id' => trim($data['calendar_id']), 'enabled' => $request->boolean('enabled'), 'verified_at' => null])->save();
            DB::table('global_settings')->updateOrInsert(['key' => 'live_class_platform_google_meet'], ['value' => $request->boolean('enabled') ? '1' : '0']);
            \App\Models\AuditLog::record('update', 'Integrations', 'Saved Google Meet integration settings.');
        });
        return redirect()->route('superadmin.google_meet.settings')->with('message', 'Google Meet settings saved. Connect the institution Google account to finish setup.');
    }

    public function verify(Request $request)
    {
        $this->authorizeAdmin($request);
        $settings = GoogleMeetSetup::settings();
        $settings?->update(['verified_at' => null]);
        try {
            $owner = GoogleMeetSetup::owner();
            $access = $owner ? app(GoogleAccountService::class)->accessTokenFor($owner) : null;
            if (!$access) return back()->with('error', 'Connect or reconnect the institution Google account first.');
            $response = app(GoogleOAuthClient::class)->http()->withToken($access['token'])
                ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($settings->calendar_id).'/events', ['maxResults' => 1, 'fields' => 'kind']);
            if (!$response->successful()) return back()->with('error', 'Google could not verify calendar access. Enable the Calendar API, check the calendar ID and consent permissions, then reconnect if needed.');
            $settings->update(['verified_at' => now()]);
            return back()->with('message', 'Calendar access verified. You can now schedule a Google Meet class to confirm meeting creation. No test meeting was created.');
        } catch (\Throwable $e) {
            // Provider errors may contain credentials; do not echo or log them.
            return back()->with('error', 'Google could not be reached. Check your setup and try again.');
        }
    }

    public function disconnect(Request $request)
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () {
            $settings = GoogleMeetSetting::lockForUpdate()->find(1);
            $owner = GoogleMeetSetup::owner();
            if ($owner) app(GoogleAccountService::class)->disconnect($owner);
            $settings?->update(['platform_user_id' => null, 'verified_at' => null]);
            \App\Models\AuditLog::record('update', 'Integrations', 'Disconnected institution Google account.');
        });
        return back()->with('message', 'Institution Google account disconnected. Lecturer connections were preserved.');
    }
}
