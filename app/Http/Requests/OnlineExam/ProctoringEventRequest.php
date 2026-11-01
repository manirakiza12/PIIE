<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\OnlineExamSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProctoringEventRequest extends FormRequest
{
    private ?OnlineExamSubmission $submission = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user || (int) $user->role_id !== 7) {
            return false;
        }

        $submissionId = (int) ($this->route('submission') ?? $this->input('submission_id') ?? 0);
        $this->submission = OnlineExamSubmission::find($submissionId);

        return $this->submission
            && (int) $this->submission->student_id === (int) $user->id
            && (int) $this->submission->school_id === (int) $user->school_id;
    }

    public function rules(): array
    {
        return [
            'submission_id' => ['required', 'integer', 'exists:online_exam_submissions,id'],
            'event_type' => [
                'required',
                'string',
                Rule::in([
                    'consent_given',
                    'camera_permission_granted',
                    'camera_permission_denied',
                    'camera_started',
                    'camera_stopped',
                    'tab_hidden',
                    'fullscreen_started',
                    'fullscreen_exited',
                    'connection_lost',
                    'connection_restored',
                ]),
            ],
            'event_time' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->submission && $this->submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
                $validator->errors()->add('submission_id', 'Proctoring events can be recorded only for active attempts.');
            }
        });
    }
}
