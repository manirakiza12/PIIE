<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\OnlineExamSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SnapshotCaptureRequest extends FormRequest
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
            'image' => ['required', 'string', 'max:10485760'], // 10MB max base64
            'capture_type' => ['nullable', 'string', Rule::in(['automatic', 'manual', 'invigilator_requested'])],
            'request_id' => ['nullable', 'integer', 'exists:online_exam_snapshot_requests,id'],
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
                $validator->errors()->add('submission_id', 'Snapshots can only be captured during active attempts.');
                return;
            }

            // Validate image content
            $image = $this->input('image');
            if ($image) {
                $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $image);
                $imageData = base64_decode($base64, true);
                if ($imageData === false) {
                    $validator->errors()->add('image', 'Invalid base64 image data.');
                    return;
                }
                // Check size (max 5MB decoded)
                if (strlen($imageData) > 5 * 1024 * 1024) {
                    $validator->errors()->add('image', 'Image size exceeds maximum allowed (5MB).');
                    return;
                }
                // Check MIME type by examining magic bytes
                $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $imageData);
                $allowedMimes = ['image/webp', 'image/jpeg', 'image/png', 'image/gif'];
                if (!in_array($mime, $allowedMimes, true)) {
                    $validator->errors()->add('image', 'Unsupported image type. Allowed: WebP, JPEG, PNG, GIF.');
                    return;
                }
            }

            // If invigilator_requested, verify there's a pending request
            if ($this->input('capture_type') === 'invigilator_requested') {
                $requestId = $this->input('request_id');
                if (!$requestId) {
                    $validator->errors()->add('request_id', 'Request ID is required for invigilator-requested snapshots.');
                    return;
                }
                $pendingRequest = \App\Models\OnlineExamSnapshotRequest::where('id', $requestId)
                    ->where('submission_id', $this->submission->id)
                    ->where('status', 'pending')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->first();
                if (!$pendingRequest) {
                    $validator->errors()->add('request_id', 'No valid pending snapshot request found.');
                }
            }
        });
    }
}