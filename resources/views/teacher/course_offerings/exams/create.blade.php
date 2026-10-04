@extends('layouts.app')

@section('title', 'Create an assessment — ' . $offering->reference)

@section('content')
    <nav class="mb-3" aria-label="Breadcrumb">
        <a href="{{ route('teacher.course_offerings.index') }}">My Course Offerings</a>
        <span aria-hidden="true"> / </span>
        <a href="{{ route('teacher.course_offerings.show', $offering) }}">{{ $offering->reference }}</a>
        <span aria-hidden="true"> / </span>
        <a href="{{ route('teacher.course_offerings.exams.index', $offering) }}">Quizzes &amp; Exams</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page">Create assessment</span>
    </nav>

    <h1 class="h4 mb-1">Create an assessment</h1>
    <p class="text-muted">
        For {{ $offering->subject?->name ?? 'this course' }}. Once created you will be
        taken straight to the exam's question page, where you can write questions or
        pull them from the Question Bank.
    </p>

    {{-- ── WHAT IS FIXED, AND WHY ───────────────────────────────────────────
         A legacy Class, Programme or academic Session is deliberately NOT
         offered here. This is a higher-education Course Offering, and choosing a
         legacy class would enrol every student of that class into the assessment
         and bypass the confirmed Course Registration that is the legitimate
         eligibility test. The Course Unit is shown read-only because the Offering
         already has exactly one, and letting a lecturer change it here would
         produce an assessment filed under this course but about another subject.

         The summary is the SAME partial the generic create page and the generic edit
         page use. Three copies of this block would eventually disagree about a label
         or a field, and the page a lecturer lands on would depend on which door they
         came through.
    --}}
    @include('teacher.online_exam._course_offering_context', [
        'offering' => $offering,
        'lecturer' => auth()->user(),
    ])

    <form method="POST" action="{{ route('teacher.course_offerings.exams.store', $offering) }}">
        @csrf

        @if ($errors->any())
            <div class="alert alert-danger" role="alert" data-testid="exams-errors">
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-4">
            <div class="card-body">
                <div class="mb-3">
                    <label for="exam-title" class="form-label">Title <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control @error('title') is-invalid @enderror"
                           id="exam-title" name="title" required maxlength="255"
                           value="{{ old('title') }}"
                           placeholder="e.g. Mid-Semester Examination — Business Mathematics">
                    @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="exam-type" class="form-label">Assessment type <span aria-hidden="true">*</span></label>
                    {{-- The model's vocabulary, which is the set the engine's own
                         validation permits. A test asserts the two agree. --}}
                    <select class="form-select @error('exam_type') is-invalid @enderror"
                            id="exam-type" name="exam_type" required>
                        @foreach (\App\Models\OnlineExam::TYPES as $value => $label)
                            <option value="{{ $value }}" {{ old('exam_type', 'quiz') === $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('exam_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-0">
                    {{-- Rich text, because instructions to a student sitting an exam
                         are a document: lists, tables, formulae and diagrams all
                         change what a candidate does. The stored value is sanitised
                         server-side on the way in; this editor is never the only way
                         to write them, because the textarea underneath is the field
                         that actually posts. --}}
                    <x-academic-editor
                        name="instructions"
                        label="Instructions for candidates"
                        :value="old('instructions')"
                        :height="320"
                        help="Formatting, lists, tables and mathematical notation are kept. This is the same editor your students read answers in, and the same server-side filter that protects every other piece of course content." />
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Window, marks and attempts</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="exam-start" class="form-label">Opens <span aria-hidden="true">*</span></label>
                        <input type="datetime-local" class="form-control @error('start_datetime') is-invalid @enderror"
                               id="exam-start" name="start_datetime" required
                               value="{{ old('start_datetime') }}">
                        @error('start_datetime') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">No student may start before this moment.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="exam-end" class="form-label">Closes <span aria-hidden="true">*</span></label>
                        <input type="datetime-local" class="form-control @error('end_datetime') is-invalid @enderror"
                               id="exam-end" name="end_datetime" required
                               value="{{ old('end_datetime') }}">
                        @error('end_datetime') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="exam-duration" class="form-label">Duration (minutes)</label>
                        <input type="number" class="form-control @error('duration_mins') is-invalid @enderror"
                               id="exam-duration" name="duration_mins" min="1"
                               value="{{ old('duration_mins', 60) }}">
                        @error('duration_mins') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Must fit inside the window above.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="exam-total" class="form-label">Total marks <span aria-hidden="true">*</span></label>
                        <input type="number" class="form-control @error('total_marks') is-invalid @enderror"
                               id="exam-total" name="total_marks" min="1" required
                               value="{{ old('total_marks') }}">
                        @error('total_marks') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Must equal the sum of the question marks.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="exam-pass" class="form-label">Pass mark <span aria-hidden="true">*</span></label>
                        <input type="number" class="form-control @error('pass_mark') is-invalid @enderror"
                               id="exam-pass" name="pass_mark" min="0" required
                               value="{{ old('pass_mark') }}">
                        @error('pass_mark') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="exam-attempts" class="form-label">Attempts allowed</label>
                        <input type="number" class="form-control @error('max_attempts') is-invalid @enderror"
                               id="exam-attempts" name="max_attempts" min="1" max="20"
                               value="{{ old('max_attempts', 1) }}">
                        @error('max_attempts') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Sitting controls and result release</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label for="exam-policy" class="form-label">When may results be released? <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('result_release_policy') is-invalid @enderror"
                                id="exam-policy" name="result_release_policy" required>
                            @foreach ([
                                'immediate' => 'After the exam ends',
                                'after_exam_end' => 'After the exam ends, once released',
                                'manual' => 'Only when the lecturer chooses',
                            ] as $value => $label)
                                <option value="{{ $value }}" {{ old('result_release_policy', 'after_exam_end') === $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('result_release_policy') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        {{-- The engine's rule, stated honestly: a release policy says
                             when the institution MAY publish, never that publication
                             happens by itself. The persisted Admin review state is
                             still required for a student to see a mark. --}}
                        <div class="form-text">
                            This sets when results <em>may</em> be released. A student still
                            sees nothing until the institution's review publishes them.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-shuffle-q"
                                   name="shuffle_questions" value="1" @checked(old('shuffle_questions'))>
                            <label class="form-check-label" for="opt-shuffle-q">Shuffle the questions per student</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-shuffle-o"
                                   name="shuffle_options" value="1" @checked(old('shuffle_options'))>
                            <label class="form-check-label" for="opt-shuffle-o">Shuffle the options within a question</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-prev"
                                   name="allow_previous_navigation" value="1" @checked(old('allow_previous_navigation', true))>
                            <label class="form-check-label" for="opt-prev">Let students go back to earlier questions</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="opt-webcam"
                                   name="webcam_required" value="1" @checked(old('webcam_required'))>
                            <label class="form-check-label" for="opt-webcam">Request webcam</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="opt-fullscreen"
                                   name="fullscreen_required" value="1" @checked(old('fullscreen_required'))>
                            <label class="form-check-label" for="opt-fullscreen">Require fullscreen</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary">Create assessment</button>
            <a href="{{ route('teacher.course_offerings.exams.index', $offering) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>

        <p class="text-muted small">
            The assessment is created as a draft. It cannot be published until its
            questions exist and their marks add up to the total above — the engine
            checks this, and you will be shown anything outstanding.
        </p>
    </form>
@endsection
