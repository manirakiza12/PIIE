<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\Subject;
use App\Models\Programme;
use App\Models\Session;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\Permissions\OnlineExamPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ── TWO MODES, ONE ENGINE ────────────────────────────────────────────────────
 *
 * An exam is EITHER a Course Offering assessment (`course_offering_id` present) OR a
 * legacy Class/Section exam (it NULL). Which one is decided by the presence of that
 * single field, and never by the shape of anything else - not by the tenant, not by
 * the lecturer's role, not by whether some legacy column happens to be filled.
 *
 * In OFFERING mode the Course Unit is DERIVED from the Offering, so `subject_id` is
 * not required of the browser at all; it is written from the authorised Offering.
 * In LEGACY mode every legacy rule below applies exactly as it always did.
 *
 * The two are kept apart deliberately rather than merged. A required `subject_id`
 * forced a Course Offering lecturer into the legacy Subject dropdown, and that
 * dropdown is populated from `teacher_permissions` - which a lecturer with only a
 * Course Offering allocation does not have. So the page told Daniel Okello "No
 * subjects are assigned to this teacher" about a course he is the PRIMARY LECTURER
 * of. The fix is not to invent a legacy subject for him; it is to stop asking.
 */
class StoreOnlineExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        if ((int) $user->role_id === 7) {
            return false;
        }

        return app(OnlineExamPermissionService::class)->has($user, 'create_online_exams');
    }

    /**
     * Is this an exam for a COURSE OFFERING?
     *
     * True whenever a Course Offering was named - from the generic create page's
     * selector or from the Offering-scoped route, which is merged in below.
     */
    public function isCourseOfferingMode(): bool
    {
        return $this->filled('course_offering_id');
    }

    private ?CourseOffering $resolvedOffering = null;

    private bool $offeringResolved = false;

    /**
     * The Offering this exam will belong to, resolved through the lecturer's own
     * allocation in their own tenant - or NULL when there is none.
     *
     * Asked from both the validator and the controller so that the id that was
     * AUTHORISED is the id that gets WRITTEN. Re-reading `$this->input()` in the
     * controller instead would leave a window in which the two disagree.
     *
     * Returns NULL rather than throwing: a tampered id is a validation failure
     * here, and `resolveOfferingForManager()`'s own 404/403 is swallowed so it
     * becomes a message on the field instead of an unexplained error page.
     */
    public function offering(): ?CourseOffering
    {
        if (!$this->offeringResolved) {
            $this->offeringResolved = true;

            $id = (int) $this->input('course_offering_id');
            $user = $this->user();

            if ($id > 0 && $user) {
                try {
                    $this->resolvedOffering = app(CourseOfferingExamAccess::class)
                        ->resolveOfferingForManager($user, $id);
                } catch (HttpException) {
                    $this->resolvedOffering = null;
                }
            }
        }

        return $this->resolvedOffering;
    }

    public function rules(): array
    {
        // ── NOTHING HERE MAY TOUCH `$this->user()` EAGERLY ────────────────────
        //
        // `rules()` is a rule BUILDER and is read directly, without a session, by
        // `CourseOfferingExamTest`, which asserts the engine's own vocabulary rather
        // than a copy of it:
        //
        //     (new StoreOnlineExamRequest())->rules()['exam_type']
        //
        // Hoisting the tenant out of the `Rule::exists()` closures dereferences a null
        // user and kills that read with "Attempt to read property school_id on null".
        // The closures below stay lazy for exactly that reason.
        $schoolId = fn ($q) => $q->where('school_id', $this->user()->school_id);
        $isOffering = $this->isCourseOfferingMode();

        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            // Tenant-scoped. A Course Offering belonging to another institution fails
            // here, before any question of allocation is asked.
            'course_offering_id' => [
                'nullable',
                'integer',
                Rule::exists('course_offerings', 'id')->where($schoolId),
            ],
            // Required only for a LEGACY exam. For a Course Offering exam the Course
            // Unit comes from the Offering, so asking the browser for it would be
            // asking the lecturer to restate something the server already knows.
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
            // An APPROVED adjustment to this paper's integrity controls.
            //
            // Validated against the CONFIGURED allow-list rather than a copy of it, so
            // a value added to `config/online_exam_integrity.php` becomes selectable
            // here without a second edit that could forget to add it. Null is valid and
            // means the full default restrictions.
            'integrity_accommodation' => ['nullable', 'string', Rule::in(array_merge(
                [''],
                array_keys((array) config('online_exam_integrity.accommodations', []))
            ))],
            'auto_submit' => ['nullable', 'boolean'],
            'workflow_state' => ['nullable', Rule::in(['draft', 'pending_review', 'published', 'cancelled'])],
        ];
    }

    public function messages(): array
    {
        return [
            'end_datetime.after' => 'End datetime must be after start datetime.',
            'duration_mins.min' => 'Duration must be greater than zero.',
            'duration_minutes.min' => 'Duration must be greater than zero.',
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
            // '' rather than null, so a form that posts an empty <select> clears the
            // adjustment rather than failing validation against the nullable rule.
            'integrity_accommodation' => ($this->input('integrity_accommodation') ?: '') === ''
                ? null
                : $this->input('integrity_accommodation'),
            'auto_submit' => $this->has('auto_submit') ? $this->boolean('auto_submit') : true,
        ]);

        // ── THE OFFERING SCOPED ROUTE IS THE AUTHORITY, NOT THE BODY ──────────
        //
        // `POST /teacher/course-offerings/{id}/exams` carries the Offering in the
        // path. Merging it here means the validation below - and the controller -
        // see the same value, and a body that says nothing about `course_offering_id`
        // still produces an exam bound to the course the lecturer is in.
        //
        // `routeIs()` and not `$this->route('id')`: that parameter name is used by
        // many routes in this file of routes/web.php, and reading it unguarded would
        // make an admin POST that happens to have an `id` be treated as a Course
        // Offering exam. Naming the route explicitly cannot fire anywhere else.
        if ($this->routeIs('teacher.course_offerings.exams.store')) {
            $offeringId = (int) $this->route('id');

            if ($offeringId > 0) {
                $this->merge(['course_offering_id' => $offeringId]);
            }
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if (!$user) {
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

            // Governance is mode-independent. A lecturer still lands in the Admin
            // review queue for a Course Offering exam, exactly as for a legacy one.
            if ($this->filled('workflow_state') && !$permissionService->has($user, 'publish_online_exams')) {
                $requestedState = (string) $this->input('workflow_state');
                if (in_array($requestedState, ['published', 'cancelled'], true)) {
                    $validator->errors()->add('workflow_state', 'You are not authorized to set this workflow state.');
                }
            }

            // ── A COURSE OFFERING EXAM: the Offering is the authority ──────────
            //
            // Everything from here to the end of this callback is the LEGACY
            // subject/class/programme graph, and none of it may be applied to an
            // Offering exam. Three of those checks would actively reject a correct
            // submission: `teacherCanUseSubject()` reads `teacher_permissions`, which
            // a Course Offering lecturer does not have, so requiring it would refuse
            // the primary lecturer of a course he demonstrably teaches.
            if ($this->isCourseOfferingMode()) {
                $this->validateCourseOfferingContext($validator);

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
     * Is this lecturer allowed to author an exam in the named Course Offering?
     *
     * One question, asked of `resolveOfferingForManager()` - which is the same
     * question `POST /teacher/course-offerings/{id}/exams` asks - so the generic page
     * and the Offering-scoped page cannot disagree about who may author where.
     *
     * It already covers every rejection the brief lists:
     *
     *   - another tenant's Offering: refused by the tenant-scoped `exists` rule on
     *     `course_offering_id` before this runs;
     *   - the lecturer is not allocated: `resolveOffering()` finds no allocation and
     *     a non-admin staff member is a 404;
     *   - an Offering that is not being taught: `assertCanManage()` composes
     *     `canLecturerManage()`, which requires `teachingActionsAllowed()`, which in
     *     turn requires `offeringAllowsOperations()` - so a cancelled or completed
     *     Offering cannot receive a new paper;
     *   - a manipulated URL/form id: it is the same lookup, in the actor's own tenant,
     *     so an id from another lecturer's course simply does not resolve.
     *
     * A post that tries to steer the derived fields is refused rather than ignored.
     * Ignoring them would be safe, but a lecturer who sees their `subject_id`
     * silently replaced would have no way to know the form had been tampered with.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    private function validateCourseOfferingContext($validator): void
    {
        $user = $this->user();
        $offering = $this->offering();

        if (! $offering instanceof CourseOffering) {
            // ── A MISSING OFFERING IS A 404 ONLY WHEN IT WAS IN THE URL ───────
            //
            // `POST /teacher/course-offerings/{id}/exams` names the Offering in the
            // path, and a path that does not resolve is a missing resource - which
            // also tells an outsider nothing, since "not yours" and "not real" are
            // the same answer.
            //
            // The generic create page is different: there the id is a form FIELD, so
            // the honest response is a message on that field. Answering a mistyped
            // dropdown with a 404 would be baffling, and answering a forged field
            // with a redirect-to-the-form is exactly the right amount of information.
            if ($this->routeIs('teacher.course_offerings.exams.store')) {
                throw new HttpException(404, 'Course Offering not found.');
            }

            $validator->errors()->add(
                'course_offering_id',
                'Choose a Course Offering you are currently allocated to teach.'
            );

            return;
        }

        // Defence in depth, and free: the Offering resolved inside the actor's own
        // tenant must also be in the exam's own tenant.
        if ((int) $offering->school_id !== (int) $user->school_id) {
            $validator->errors()->add('course_offering_id', 'That Course Offering belongs to another institution.');

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

        if (!isset($data['workflow_state'])) {
            $user = $this->user();
            $canPublish = $user ? app(OnlineExamPermissionService::class)->has($user, 'publish_online_exams') : false;
            $data['workflow_state'] = $canPublish ? 'draft' : 'pending_review';
        }

        $data['duration_mins'] = (int) ($data['duration_mins'] ?? 0);

        return $key ? ($data[$key] ?? $default) : $data;
    }
}
