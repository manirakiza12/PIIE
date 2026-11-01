<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\OnlineExamSubmission;
use Illuminate\Foundation\Http\FormRequest;

class SnapshotFailedRequest extends FormRequest
{
    private ?OnlineExamSubmission $submission = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user || (int) $user->role_id !== 7) {
            return false;
        }

        $submissionId = (int) ($this->route('submission') ?? $this->input('submission_id') ?? 0);
        $this->submission = OnlineExamSubmission::with('exam')->find($submissionId);

        return $this->submission
            && (int) $this->submission->student_id === (int) $user->id
            && (int) $this->submission->school_id === (int) $user->school_id;
    }

    public function rules(): array
    {
        return [
            'submission_id' => ['required', 'integer', 'exists:online_exam_submissions,id'],
            'error' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->submission || !$this->submission->exam) {
                return;
            }

            if (!$this->submission->exam->webcam_required) {
                $validator->errors()->add('submission_id', 'This exam does not require webcam snapshots.');
                return;
            }

            if ($this->submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
                $validator->errors()->add('submission_id', 'Snapshot failures can only be reported during active attempts.');
            }
        });
    }
}