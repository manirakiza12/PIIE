<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\Subject;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\Permissions\OnlineExamAuthorizer;
use App\Support\Permissions\OnlineExamPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * ── WHICH MODE IS THIS UPDATE IN, AND WHY IT IS NOT THE REQUEST ──────────────
 *
 * Unlike the store request, an UPDATE cannot be told its mode by the body: the exam
 * already exists, and the field that decides is the one on the ROW. So the mode is
 * read from `$this->exam->course_offering_id` and the request is checked AGAINST
 * it. Three consequences, each of which is a rejection rather than a coercion:
 *
 *   - a Course Offering exam cannot be RE-POINTED at another Offering;
 *   - a LEGACY exam cannot be silently CONVERTED into a Course Offering exam.
 *     Historical exams stay on the legacy path, which is what backward compatibility
 *     means here: no destructive migration, in either direction, ever.
 *
 * Who may edit is unchanged - `authorize()` still asks `canManageExam()` - and it
 * still lets an author edit their own paper. Requiring a live allocation to EDIT
 * would be a new restriction nobody asked for, and the deallocated-author case is
 * deliberately supported by `OnlineExamAuthorizer`.
 */
class UpdateOnlineExamRequest extends FormRequest
{
    private ?OnlineExam $exam = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $routeExam = $this->route('exam');
        if ($routeExam instanceof OnlineExam) {
            $this->exam = $routeExam;
        } else {
            $id = (int) ($this->route('id') ?? $routeExam ?? 0);
            $this->exam = OnlineExam::find($id);
        }

        if (!$this->exam) {
            return false;
        }

        return app(OnlineExamAuthorizer::class)->canManageExam($user, $this->exam);
    }

    /**
     * Is the exam being updated one that belongs to a COURSE OFFERING?
     *
     * Guarded on the column existing, because a deployment that has not run the
     * additive migration has no such field on any row, and `->course_offering_id`
     * on a model whose table lacks the column is not an error but simply undefined.
     */
    public function isCourseOfferingMode(): bool
    {
        // `Schema::hasColumn` AND `Schema::hasTable`. Both, because several suites in
        // this engine hand-roll a partial schema: one declares the `course_offering_id`
        // column on `online_exams` while never creating `course_offerings` at all, and
        // resolving the Offering there would be a 500 on the save.
        return $this->exam !== null
            && $this->exam->course_offering_id !== null
            && Schema::hasColumn('online_exams', 'course_offering_id')
            && Schema::hasTable('course_offerings');
    }

    /**
     * The Offering this exam belongs to, read in the exam's OWN tenant.
     *
     * Tenant from the exam row, never from the request: the exam was authorised
     * against its own school in `authorize()` and in the controller, so the context
     * shown and saved here is the context that was authorised.
     */
    public function offering(): ?CourseOffering
    {
        if (! $this->isCourseOfferingMode()) {
            return null;
        }

        return CourseOffering::query()
            ->where('school_id', (int) $this->exam->school_id)
            ->whereKey((int) $this->exam->course_offering_id)
            ->first();
    }

    public function rules(): array
    {
        // Lazy, for the same reason as `StoreOnlineExamRequest::rules()`: the rule
        // builder is read without a session by other suites, so it must never
        // dereference the user outside a closure that runs at validation time.
        $schoolId = fn ($q) => $q->where('school_id', $this->user()->school_id);
        $isOffering = $this->isCourseOfferingMode();

        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            // Accepted so an honest form round-trips, and checked in `withValidator()`
            // rather than trusted. See the class note: an exam's mode comes from the
            // row, so a post may never move an exam between modes.
            'course_offering_id' => ['nullable', 'integer'],
            // Required only for a legacy exam. A Course Offering exam's Course Unit
            // comes from the Offering, so the form does not offer it as a choice.
            'subject_id' => array_values(array_filter([
                Rule::requiredIf(! $isOffering),
                'nullable',
                'integer',
                Rule::exists('subjects', 'id')->where($schoolId),
            ])),
            'class_id' => [
                'nullable',
                'integer',
                Rule::exists('classes', 'id')->where($schoolId),
            ],
            'programme_id' => [
                'nullable', 'integer',
                Rule::exists('programmes', 'id')->where(fn($q) => $schoolId($q)->where('is_active', 1)),
            ],
            'session_id' => [
                'nullable', 'integer',
                Rule::exists('sessions', 'id')->where($schoolId),
            ],
            'exam_type' => ['required', 'string', Rule::in(['cat', 'midterm', 'final', 'quiz', 'assignment'])],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'duration_mins' => ['nullable', 'integer', 'min:1'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_marks' => ['required', 'integer', 'min:1'],
            'pass_mark' => ['required', 'integer', 'min:0'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'allow_previous_navigation' => ['nullable', 'boolean'],
            'result_release_policy' => ['required', Rule::in(['immediate', 'after_exam_end', 'manual'])],
            'webcam_required' => ['nullable', 'boolean'],
            'fullscreen_required' => ['nullable', 'boolean'],
            // See StoreOnlineExamRequest: validated against the configured allow-list.
            'integrity_accommodation' => ['nullable', 'string', Rule::in(array_merge(
                [''],
                array_keys((array) config('online_exam_integrity.accommodations', []))
            ))],
            'auto_submit' => ['nullable', 'boolean'],
            'workflow_state' => ['nullable', Rule::in(['draft', 'pending_review', 'published', 'cancelled'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $duration = $this->input('duration_mins', $this->input('duration_minutes'));

        $this->merge([
            'duration_mins' => $duration,
            'shuffle_questions' => $this->boolean('shuffle_questions'),
            'shuffle_options' => $this->boolean('shuffle_options'),
            'allow_previous_navigation' => $this->has('allow_previous_navigation')
                ? $this->boolean('allow_previous_navigation')
                : true,
            'webcam_required' => $this->boolean('webcam_required'),
            'fullscreen_required' => $this->boolean('fullscreen_required'),
            'integrity_accommodation' => ($this->input('integrity_accommodation') ?: '') === ''
                ? null
                : $this->input('integrity_accommodation'),
            'auto_submit' => $this->has('auto_submit') ? $this->boolean('auto_submit') : true,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if (!$user || !$this->exam) {
                return;
            }

            $duration = (int) $this->input('duration_mins');
            $start = strtotime((string) $this->input('start_datetime'));
            $end = strtotime((string) $this->input('end_datetime'));
            if ($start && $end && $duration > 0) {
                $windowMins = (int) floor(($end - $start) / 60);
                if ($windowMins <= 0 || $duration > $windowMins) {
                    $validator->errors()->add('duration_mins', 'Duration must fit inside the exam window.');
                }
            }

            if ((int) $this->input('pass_mark') > (int) $this->input('total_marks')) {
                $validator->errors()->add('pass_mark', 'Pass mark cannot exceed total marks.');
            }

            $permissionService = app(OnlineExamPermissionService::class);

            if ($this->filled('workflow_state') && !$permissionService->has($user, 'publish_online_exams')) {
                $requestedState = (string) $this->input('workflow_state');
                if (in_array($requestedState, ['published', 'cancelled'], true)) {
                    $validator->errors()->add('workflow_state', 'You are not authorized to set this workflow state.');
                }
            }

            // ── THE MODE OF AN EXISTING EXAM CANNOT BE CHANGED BY A FORM ───────
            //
            // Stated first, because it decides which of the rules below run at all.
            if ($this->isCourseOfferingMode()) {
                $this->validateCourseOfferingContext($validator);
            } elseif ($this->filled('course_offering_id')) {
                $validator->errors()->add(
                    'course_offering_id',
                    'This is a legacy exam. It stays on the legacy class/subject path and cannot be attached to a Course Offering.'
                );
            }

            if ($this->exam->isStructurallyLocked()) {
                $lockedFields = [
                    'subject_id', 'class_id', 'programme_id', 'session_id', 'start_datetime', 'end_datetime', 'duration_mins',
                    'duration_minutes', 'total_marks', 'pass_mark', 'max_attempts',
                    'shuffle_questions', 'shuffle_options', 'allow_previous_navigation',
                    'result_release_policy', 'webcam_required', 'fullscreen_required', 'exam_type',
                    // NOT locked: an approved adjustment can be granted AFTER a student
                    // has begun the paper. That is the situation it exists for — a
                    // student whose need is recognised mid-attempt — and locking it
                    // would make the feature unusable in exactly its main case.
                    //
                    // Changing it never alters marks, never ends an attempt, and never
                    // stops events being recorded, so it is safe to relax live.
                ];

                foreach ($lockedFields as $field) {
                    if ($this->has($field) && $this->input($field) != $this->exam->{$field}) {
                        $validator->errors()->add($field, 'This field is locked after attempts have started.');
                    }
                }
            }

            // ── LEGACY SUBJECT/CLASS/PROGRAMME RULES, FROM HERE DOWN ───────────
            //
            // Unchanged, and deliberately NOT applied to a Course Offering exam:
            // `teacherCanUseSubject()` reads `teacher_permissions`, which a lecturer
            // allocated only to a Course Offering does not hold, so requiring it here
            // would lock the primary lecturer out of editing their own paper.
            if ($this->isCourseOfferingMode()) {
                return;
            }

            if ((int) $user->role_id === 3 && !$permissionService->teacherCanUseSubject($user, (int) $this->input('subject_id'))) {
                $validator->errors()->add('subject_id', 'You are not assigned to the selected subject/class.');
            }

            if ($this->filled('class_id') && $this->filled('subject_id')) {
                $subject = Subject::where('id', (int) $this->input('subject_id'))
                    ->where('school_id', $user->school_id)
                    ->first();

                if ($subject && $subject->class_id && (int) $subject->class_id !== (int) $this->input('class_id')) {
                    $validator->errors()->add('class_id', 'Selected class does not match selected subject.');
                }

                if ($subject && $this->filled('programme_id') && (int) ($subject->programme_id ?? 0) !== (int) $this->input('programme_id')) {
                    $validator->errors()->add('programme_id', 'Selected course does not belong to the selected programme.');
                }
            }

            if ($this->filled('programme_id') && $this->filled('subject_id')) {
                $subject = Subject::where('id', (int) $this->input('subject_id'))->where('school_id', $user->school_id)->first();
                if ($subject && (int) ($subject->programme_id ?? 0) !== (int) $this->input('programme_id')) {
                    $validator->errors()->add('subject_id', 'Selected course is not part of the selected programme.');
                }
            }

            if ((int) $user->role_id === 3) {
                if ($this->filled('programme_id') && !TeacherProgrammeAssignment::where('teacher_id', $user->id)->where('school_id', $user->school_id)->where('programme_id', $this->input('programme_id'))->exists()) {
                    $validator->errors()->add('programme_id', 'You are not assigned to the selected programme.');
                }
                if ($this->filled('class_id') && !TeacherPermission::where('teacher_id', $user->id)->where('school_id', $user->school_id)->where('class_id', $this->input('class_id'))->exists()) {
                    $validator->errors()->add('class_id', 'You are not assigned to the selected cohort.');
                }
            }
        });
    }

    /**
     * An exam's Course Offering cannot be re-pointed, and its derived academic
     * context cannot be steered from a form.
     *
     * The first is a rejection rather than a coercion on purpose. Two lecturers each
     * allocated to a different Offering could otherwise move a shared draft between
     * their own courses by editing one hidden field, and - because the legacy
     * columns are forced to NULL on an Offering exam - they could do it while
     * changing which students the paper reaches. `course_offering_id` is treated as
     * an immutable property of the exam, which is what it is.
     */
    private function validateCourseOfferingContext($validator): void
    {
        if ($this->filled('course_offering_id')
            && (int) $this->input('course_offering_id') !== (int) $this->exam->course_offering_id) {
            $validator->errors()->add(
                'course_offering_id',
                'An assessment cannot be moved to a different Course Offering.'
            );
        }

        $offering = $this->offering();

        if (! $offering instanceof CourseOffering) {
            // The Offering row is gone. Refusing is the only safe answer: there is
            // no longer an authority from which to derive the academic context, and
            // writing the exam with a NULL Course Unit would silently move it onto
            // the legacy path where it would become school-wide.
            $validator->errors()->add(
                'exam',
                'The Course Offering this assessment belongs to could not be found. Ask an administrator to check the course record.'
            );

            return;
        }

        if ($this->filled('subject_id') && (int) $this->input('subject_id') !== (int) $offering->subject_id) {
            $validator->errors()->add(
                'subject_id',
                'The Course Unit is taken from the Course Offering and cannot be changed here.'
            );
        }

        foreach (['class_id', 'programme_id', 'session_id'] as $legacyField) {
            if ($this->filled($legacyField)) {
                $validator->errors()->add(
                    $legacyField,
                    'A Course Offering exam does not target a class, programme or academic session. Only students confirmed on the Course Offering will receive it.'
                );
            }
        }
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $data['duration_mins'] = (int) ($data['duration_mins'] ?? 0);

        return $key ? ($data[$key] ?? $default) : $data;
    }
}
