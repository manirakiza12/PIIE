<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferingLiveClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'integer'],
            'platform' => ['required', 'in:jitsi,google_meet,zoom,bigbluebutton,custom'],
            'meeting_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'start_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'timezone' => ['nullable', 'timezone'],
            // The lecturer's intent, not a lifecycle state. "Save as Draft" and
            // "Schedule & Notify Students" are the only two choices, and the
            // controller derives is_published/status from them. `status` is
            // deliberately NOT accepted any more: it let a client assert an
            // internal state the form no longer offers.
            'action' => ['nullable', 'in:draft,publish'],
            // Backwards-compatible publishing signal for existing callers
            // (the admin API and older integrations) that post is_published
            // rather than an action. `action` always wins when both are
            // present, so the lecturer form's two buttons are authoritative.
            'is_published' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A provider that cannot create its own meeting needs a link from the
     * lecturer; one that can must not be handed a stale link for a different
     * time. This keeps the requirement in one place instead of in the view.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $platform = (string) $this->input('platform');
            $autoCreates = in_array($platform, ['jitsi', 'google_meet', 'zoom'], true);

            if (! $autoCreates && blank($this->input('meeting_url'))) {
                $validator->errors()->add(
                    'meeting_url',
                    get_phrase('This provider does not create a meeting link automatically. Paste the link your provider gave you.')
                );
            }
        });
    }
}
