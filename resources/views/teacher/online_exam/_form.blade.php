@php
    $isEdit = !empty($exam);

    // ── THE FORM'S MODE, AND WHERE IT COMES FROM ─────────────────────────────
    //
    // An exam belongs to a Course Offering or it does not. `$offering` is resolved
    // SERVER side - from the lecturer's own allocations on create, from the exam's
    // own `course_offering_id` on edit - so this branch can never be talked into
    // the wrong mode by the page that renders it.
    //
    // In Course Offering mode the legacy selectors below are not rendered at all.
    // They are hidden rather than merely ignored for a specific reason: rendering a
    // Class, Programme and academic Session dropdown for an exam that is
    // ineligible to use them invites a lecturer to fill them in, and every one of
    // those values is refused on save. A control that cannot be used correctly
    // should not be on the page.
    $isOfferingMode = !empty($offering);
    $offerings = $offerings ?? collect();
    $targetRoute = $isEdit ? route('teacher.online_exams.update', $exam->id) : route('teacher.online_exams.store');
@endphp

@if(!empty($structureLocked))
    <div class="alert alert-warning">
        {{ get_phrase('This exam structure is locked because attempts already exist. Structural fields are read-only.') }}
    </div>
@endif

@if(!empty($readinessErrors))
    <div class="alert alert-danger">
        <strong>{{ get_phrase('Publication readiness issues') }}:</strong>
        <ul class="mb-0 mt-2">
            @foreach($readinessErrors as $issue)
                <li>{{ $issue }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $targetRoute }}" class="row g-3">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="col-md-8">
        <label class="eForm-label">{{ get_phrase('Title') }}</label>
        <input type="text" class="form-control eForm-control" name="title" value="{{ old('title', $exam->title ?? '') }}" required>
    </div>

    <div class="col-md-4">
        <label class="eForm-label">{{ get_phrase('Exam Type') }}</label>
        <select class="form-select eForm-select" name="exam_type" required {{ !empty($structureLocked) ? 'disabled' : '' }}>
            @foreach(\App\Models\OnlineExam::TYPES as $k => $v)
                <option value="{{ $k }}" {{ old('exam_type', $exam->exam_type ?? 'quiz') === $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
        @if(!empty($structureLocked) && !empty($exam))
            <input type="hidden" name="exam_type" value="{{ $exam->exam_type }}">
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         WHICH ACADEMIC CONTEXT IS THIS EXAM IN?
         ══════════════════════════════════════════════════════════════════════

         Two workflows share one engine, and the difference is only what they are
         attached to. A COURSE OFFERING exam is attached to a course; a LEGACY exam
         is attached to a class, a programme and an academic session.
    --}}

    @if($isOfferingMode)
        @include('teacher.online_exam._course_offering_context', [
            'offering' => $offering,
            'lecturer' => $lecturer ?? auth()->user(),
        ])

        {{-- The Offering the server already resolved and authorised. Round-tripped so
             `StoreOnlineExamRequest::isCourseOfferingMode()` is true on submit, and so
             `UpdateOnlineExamRequest` can prove the form did not re-point it.
             `course_offering_id` is validated against the lecturer's own allocations
             on store, so this value is a convenience, never an authority. --}}
        <input type="hidden" name="course_offering_id" value="{{ $offering->id }}">
    @else
        @if($offerings->isNotEmpty() && !$isEdit)
            {{-- ── THE FIRST ACADEMIC SELECTOR ─────────────────────────────────
                 A lecturer appointed to a Course Offering does not choose a subject.
                 They choose the COURSE, and the Course Unit, academic year, period
                 and lecturer follow from it. Only Offerings this lecturer is
                 currently allocated to teach are offered.

                 Choosing one reloads the page with `?course_offering_id=`, so the
                 derived summary below is produced by the same server-side read the
                 store performs. Nothing about the relationship is reconstructed in
                 the browser, so nothing in the browser can be wrong. --}}
            <div class="col-md-12">
                <label class="eForm-label" for="exam_course_offering">{{ get_phrase('Course Offering') }}</label>
                <select class="form-select eForm-select" name="course_offering_id" id="exam_course_offering" data-testid="exam-course-offering-select">
                    <option value="">{{ get_phrase('— Create a legacy class exam instead —') }}</option>
                    @foreach($offerings as $candidate)
                        <option value="{{ $candidate->id }}" {{ (string) old('course_offering_id', $selectedOfferingId ?? 0) === (string) $candidate->id ? 'selected' : '' }}>
                            {{ $candidate->subject?->name ?? (get_phrase('Course Offering') . ' #' . $candidate->id) }}{{ $candidate->reference ? ' — ' . $candidate->reference : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">
                    {{ get_phrase('Pick the course you are teaching. Its Course Unit, academic year and semester are filled in for you, and only students confirmed on it will receive the exam.') }}
                </small>
            </div>
        @endif

        @if($subjects->isEmpty() && $offerings->isEmpty())
            {{-- The warning is for a lecturer with NO academic assignment of any kind.

                 It used to be shown whenever the legacy subject list was empty, which
                 is also true for a lecturer whose only authority is a Course Offering
                 allocation - so Daniel Okello was told he had no subject while holding
                 a PRIMARY LECTURER appointment on BBIT1103. It is now shown only when
                 the Course Offering list is empty too, which is the case it was
                 actually written for: nothing this person teaches. --}}
            <div class="col-md-12">
                <div class="alert alert-warning py-2">{{ get_phrase('No subjects are assigned to this teacher. An administrator must assign a class/subject before this exam can be created.') }}</div>
            </div>
        @elseif($subjects->isEmpty())
            {{-- A genuine dual situation: this lecturer holds a Course Offering
                 allocation but no legacy class assignment. That is not an error, so
                 it is stated as orientation rather than as a warning - and it says
                 which path is the real one, so the legacy selectors below are not
                 mistaken for the primary workflow. --}}
            <div class="col-md-12">
                <div class="alert alert-info py-2">
                    {{ get_phrase('You are not assigned to any legacy class, so the Course Unit, Class, Programme and academic period selectors below cannot be used. Choose a Course Offering above, or create the assessment from the course itself.') }}
                </div>
            </div>
        @endif

        <div class="col-md-6">
            <label class="eForm-label">{{ academic_term('subject', auth()->user()->school_id) }}</label>
            <select class="form-select eForm-select" name="subject_id" required {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <option value="">{{ get_phrase('Select subject') }}</option>
                @foreach($subjects as $subject)
                    <option value="{{ $subject->id }}" data-programme-id="{{ $subject->programme_id ?? '' }}" {{ (string) old('subject_id', $exam->subject_id ?? '') === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                @endforeach
            </select>
            @if(!empty($structureLocked) && !empty($exam))
                <input type="hidden" name="subject_id" value="{{ $exam->subject_id }}">
            @endif
        </div>

        <div class="col-md-6">
            <label class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
            <select class="form-select eForm-select" name="class_id" {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <option value="">{{ get_phrase('Select class') }}</option>
                @foreach($classes as $class)
                    <option value="{{ $class->id }}" {{ (string) old('class_id', $exam->class_id ?? '') === (string) $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                @endforeach
            </select>
            @if(!empty($structureLocked) && !empty($exam))
                <input type="hidden" name="class_id" value="{{ $exam->class_id }}">
            @endif
        </div>

        <div class="col-md-6">
            <label class="eForm-label">{{ academic_term('programme', auth()->user()->school_id) }}</label>
            <select class="form-select eForm-select" name="programme_id" id="teacher_online_exam_programme" {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <option value="">{{ get_phrase('Not programme-targeted') }}</option>
                @foreach(($programmes ?? collect()) as $programme)
                    <option value="{{ $programme->id }}" {{ (string) old('programme_id', $exam->programme_id ?? '') === (string) $programme->id ? 'selected' : '' }}>{{ $programme->name }}{{ $programme->code ? ' ('.$programme->code.')' : '' }}</option>
                @endforeach
            </select>
            @if(!empty($structureLocked) && !empty($exam))<input type="hidden" name="programme_id" value="{{ $exam->programme_id }}">@endif
        </div>

        <div class="col-md-6">
            <label class="eForm-label">{{ academic_term('session', auth()->user()->school_id) }}</label>
            <select class="form-select eForm-select" name="session_id" {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <option value="">{{ get_phrase('No academic period selected') }}</option>
                @foreach(($sessions ?? collect()) as $session)
                    <option value="{{ $session->id }}" {{ (string) old('session_id', $exam->session_id ?? ($session->status ? $session->id : '')) === (string) $session->id ? 'selected' : '' }}>{{ $session->session_title }}{{ $session->status ? ' — '.get_phrase('Active') : '' }}</option>
                @endforeach
            </select>
            @if(!empty($structureLocked) && !empty($exam))<input type="hidden" name="session_id" value="{{ $exam->session_id }}">@endif
        </div>
    @endif

    <div class="col-md-3">
        <label class="eForm-label">{{ get_phrase('Start') }}</label>
        <input type="datetime-local" class="form-control eForm-control" name="start_datetime" value="{{ old('start_datetime', !empty($exam?->start_datetime) ? $exam->start_datetime->format('Y-m-d\\TH:i') : '') }}" required {{ !empty($structureLocked) ? 'disabled' : '' }}>
        @if(!empty($structureLocked) && !empty($exam?->start_datetime))
            <input type="hidden" name="start_datetime" value="{{ $exam->start_datetime->format('Y-m-d H:i:s') }}">
        @endif
    </div>

    <div class="col-md-3">
        <label class="eForm-label">{{ get_phrase('End') }}</label>
        <input type="datetime-local" class="form-control eForm-control" name="end_datetime" value="{{ old('end_datetime', !empty($exam?->end_datetime) ? $exam->end_datetime->format('Y-m-d\\TH:i') : '') }}" required {{ !empty($structureLocked) ? 'disabled' : '' }}>
        @if(!empty($structureLocked) && !empty($exam?->end_datetime))
            <input type="hidden" name="end_datetime" value="{{ $exam->end_datetime->format('Y-m-d H:i:s') }}">
        @endif
    </div>

    <div class="col-md-2">
        <label class="eForm-label">{{ get_phrase('Duration (min)') }}</label>
        <input type="number" min="1" class="form-control eForm-control" name="duration_mins" value="{{ old('duration_mins', $exam->duration_mins ?? 60) }}" required {{ !empty($structureLocked) ? 'readonly' : '' }}>
    </div>

    <div class="col-md-2">
        <label class="eForm-label">{{ get_phrase('Total Marks') }}</label>
        <input type="number" min="1" class="form-control eForm-control" name="total_marks" value="{{ old('total_marks', $exam->total_marks ?? 100) }}" required {{ !empty($structureLocked) ? 'readonly' : '' }}>
    </div>

    <div class="col-md-2">
        <label class="eForm-label">{{ get_phrase('Pass Mark') }}</label>
        <input type="number" min="0" class="form-control eForm-control" name="pass_mark" value="{{ old('pass_mark', $exam->pass_mark ?? 50) }}" required {{ !empty($structureLocked) ? 'readonly' : '' }}>
    </div>

    <div class="col-md-2">
        <label class="eForm-label">{{ get_phrase('Maximum Attempts') }}</label>
        <input type="number" min="1" max="20" class="form-control eForm-control" name="max_attempts" value="{{ old('max_attempts', $exam->max_attempts ?? 1) }}" required {{ !empty($structureLocked) ? 'readonly' : '' }}>
    </div>

    <div class="col-md-4">
        <label class="eForm-label">{{ get_phrase('Result Release Policy') }}</label>
        <select class="form-select eForm-select" name="result_release_policy" {{ !empty($structureLocked) ? 'disabled' : '' }}>
            @foreach(['immediate' => 'Immediate', 'after_exam_end' => 'After Exam End', 'manual' => 'Manual'] as $k => $v)
                <option value="{{ $k }}" {{ old('result_release_policy', $exam->result_release_policy ?? 'immediate') === $k ? 'selected' : '' }}>{{ get_phrase($v) }}</option>
            @endforeach
        </select>
        <small class="form-text text-muted">{{ get_phrase('Immediate allows an administrator to publish once marking is complete; After Exam End also requires the scheduled end time; Manual keeps it hidden until an administrator publishes it.') }}</small>
    </div>

    <div class="col-md-12">
        <label class="eForm-label">{{ get_phrase('Instructions') }}</label>
        <label class="eForm-label">{{ get_phrase('Instructions') }}</label>
                    {{-- `old()` first, then the stored value: a failed save must return
                         the lecturer their wording rather than the last saved one. The
                         raw attribute is read rather than the accessor, so the editor is
                         handed the markup that was actually stored. --}}
                    <x-academic-editor
                        name="instructions"
                        :value="old('instructions', !empty($exam) ? ($exam->getAttributes()['instructions'] ?? '') : '')"
                        :rows="5"
                        :height="280"
                        help="Formatting, lists, tables and mathematical notation are kept."
                        testid="exam-instructions-editor" />
    </div>

    <div class="col-md-12">
        <div class="row">
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="shuffle_questions" id="shuffle_questions" value="1" {{ old('shuffle_questions', $exam->shuffle_questions ?? false) ? 'checked' : '' }} {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <label class="form-check-label" for="shuffle_questions">{{ get_phrase('Shuffle Questions') }}</label>
                @if(!empty($structureLocked) && !empty($exam))
                    <input type="hidden" name="shuffle_questions" value="{{ (int) ($exam->shuffle_questions ?? false) }}">
                @endif
            </div>
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="shuffle_options" id="shuffle_options" value="1" {{ old('shuffle_options', $exam->shuffle_options ?? false) ? 'checked' : '' }} {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <label class="form-check-label" for="shuffle_options">{{ get_phrase('Shuffle Options') }}</label>
                @if(!empty($structureLocked) && !empty($exam))
                    <input type="hidden" name="shuffle_options" value="{{ (int) ($exam->shuffle_options ?? false) }}">
                @endif
            </div>
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="allow_previous_navigation" id="allow_previous_navigation" value="1" {{ old('allow_previous_navigation', $exam->allow_previous_navigation ?? true) ? 'checked' : '' }} {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <label class="form-check-label" for="allow_previous_navigation">{{ get_phrase('Allow Previous Navigation') }}</label>
                @if(!empty($structureLocked) && !empty($exam))
                    <input type="hidden" name="allow_previous_navigation" value="{{ (int) ($exam->allow_previous_navigation ?? true) }}">
                @endif
            </div>
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="auto_submit" id="auto_submit" value="1" {{ old('auto_submit', $exam->auto_submit ?? true) ? 'checked' : '' }}>
                <label class="form-check-label" for="auto_submit">{{ get_phrase('Auto Submit') }}</label>
                <small class="form-text text-muted d-block">{{ get_phrase('Automatically submit the student attempt when the exam time expires.') }}</small>
            </div>

    </div>

    <div class="col-md-12">
        <div class="row">
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="webcam_required" id="webcam_required" value="1" {{ old('webcam_required', $exam->webcam_required ?? false) ? 'checked' : '' }} {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <label class="form-check-label" for="webcam_required">{{ get_phrase('Webcam Required') }}</label>
                @if(!empty($structureLocked) && !empty($exam))
                    <input type="hidden" name="webcam_required" value="{{ (int) ($exam->webcam_required ?? false) }}">
                @endif
            </div>
            <div class="col-md-3 form-check">
                <input class="form-check-input" type="checkbox" name="fullscreen_required" id="fullscreen_required" value="1" {{ old('fullscreen_required', $exam->fullscreen_required ?? false) ? 'checked' : '' }} {{ !empty($structureLocked) ? 'disabled' : '' }}>
                <label class="form-check-label" for="fullscreen_required">{{ get_phrase('Fullscreen Required') }}</label>
                @if(!empty($structureLocked) && !empty($exam))
                    <input type="hidden" name="fullscreen_required" value="{{ (int) ($exam->fullscreen_required ?? false) }}">
                @endif
            </div>
        </div>
    </div>

    {{--
        APPROVED ADJUSTMENT TO THIS PAPER'S INTEGRITY CONTROLS.

        Deliberately NOT part of `$structureLocked`: a student whose adjustment is
        recognised part-way through a paper must be able to receive it without the
        paper having to be rebuilt. Relaxing a control never alters a mark, never ends an
        attempt, and never stops events being recorded — see
        `config/online_exam_integrity.php`.

        The options come from the configured allow-list, so a value added to the config
        appears here without a second edit that could forget it.
    --}}
    <div class="col-md-6">
        <label class="eForm-label" for="integrity_accommodation">
            {{ get_phrase('Approved integrity adjustment') }}
        </label>
        <select class="form-select eForm-select" name="integrity_accommodation" id="integrity_accommodation"
                data-testid="integrity-accommodation-select">
            <option value="">{{ get_phrase('None — full restrictions') }}</option>
            @foreach((array) config('online_exam_integrity.accommodations', []) as $accommodationKey => $accommodation)
                <option value="{{ $accommodationKey }}"
                    {{ old('integrity_accommodation', $exam->integrity_accommodation ?? null) === $accommodationKey ? 'selected' : '' }}>
                    {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $accommodationKey)) }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            {{ get_phrase('Relaxes specific on-screen controls for candidates with an approved adjustment. Marks, attempt state and the event record are unaffected.') }}
        </small>
    </div>

    <div class="col-12 d-flex gap-2">
        <button class="eBtn eBtn-primary" type="submit">{{ $isEdit ? get_phrase('Update Exam') : get_phrase('Create Exam') }}</button>
        @if($isEdit)
            <a class="eBtn eBtn-secondary" href="{{ route('teacher.online_exams.show', $exam->id) }}">{{ get_phrase('View') }}</a>
            <a class="eBtn eBtn-secondary" href="{{ route('teacher.online_exams.questions.index', $exam->id) }}">{{ get_phrase('Manage Questions') }}</a>
        @endif
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ── COURSE OFFERING → SERVER-SIDE DERIVATION ─────────────────────────────
    //
    // Choosing a Course Offering reloads the page with `?course_offering_id=`.
    //
    // A GET, deliberately. The derived read-only summary - Course Unit, academic year,
    // semester, lecturer - is produced by the same authoritative read the store will
    // perform, so what the lecturer is shown before saving is what will be written.
    // Deriving it in JavaScript instead would need a second copy of that mapping,
    // and a second copy is a second thing that can disagree.
    const offering = document.getElementById('exam_course_offering');
    if (offering) {
        offering.addEventListener('change', function () {
            const url = new URL(window.location.href);
            if (offering.value) {
                url.searchParams.set('course_offering_id', offering.value);
            } else {
                url.searchParams.delete('course_offering_id');
            }
            window.location.assign(url.toString());
        });
    }

    // Not `else if`. A lecturer may hold BOTH a Course Offering allocation and legacy
    // class assignments, in which case both controls are on the page at once and both
    // behaviours are wanted - the legacy programme filter included.
    const programme = document.getElementById('teacher_online_exam_programme');
    const subject = document.querySelector('select[name="subject_id"]');
    if (!programme || !subject) return;
    const filterSubjects = function () {
        const selected = programme.value;
        Array.from(subject.options).forEach(function (option) {
            if (!option.value) return;
            option.hidden = !!selected && option.dataset.programmeId !== selected;
            if (option.hidden && option.selected) subject.value = '';
        });
    };
    programme.addEventListener('change', filterSubjects);
    filterSubjects();
});
</script>
