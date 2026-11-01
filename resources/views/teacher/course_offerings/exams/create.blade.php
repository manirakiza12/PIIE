@extends('layouts.app')

@section('title', 'Create an assessment — ' . $offering->reference)

@section('content')
    <nav class="mb-3" aria-label="Breadcrumb">
        <a href="{{ route('teacher.course_offerings.index') }}">My Course Allocations</a>
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
         offered here. This is a higher-education Course Allocation, and choosing a
         legacy class would enrol every student of that class into the assessment
         and bypass the confirmed Course Registration that is the legitimate
         eligibility test. The Course Unit is shown read-only because the Allocation
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
                    <div class="col-md-3">
                        <label for="exam-start-date" class="form-label">Opens Date <span aria-hidden="true">*</span></label>
                        <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                               id="exam-start-date" name="start_date" required
                               value="{{ old('start_date') }}">
                        @error('start_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-start-hour" class="form-label">Hour <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('start_hour') is-invalid @enderror" id="exam-start-hour" name="start_hour" required>
                            @for ($h = 1; $h <= 12; $h++)
                                <option value="{{ $h }}" {{ old('start_hour') == $h ? 'selected' : '' }}>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</option>
                            @endfor
                        </select>
                        @error('start_hour') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-start-minute" class="form-label">Minute <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('start_minute') is-invalid @enderror" id="exam-start-minute" name="start_minute" required>
                            @for ($m = 0; $m < 60; $m++)
                                <option value="{{ $m }}" {{ old('start_minute') == $m ? 'selected' : '' }}>{{ str_pad($m, 2, '0', STR_PAD_LEFT) }}</option>
                            @endfor
                        </select>
                        @error('start_minute') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-start-period" class="form-label">Period <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('start_period') is-invalid @enderror" id="exam-start-period" name="start_period" required>
                            <option value="AM" {{ old('start_period', 'AM') === 'AM' ? 'selected' : '' }}>AM</option>
                            <option value="PM" {{ old('start_period', 'AM') === 'PM' ? 'selected' : '' }}>PM</option>
                        </select>
                        @error('start_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="exam-end-date" class="form-label">Closes Date <span aria-hidden="true">*</span></label>
                        <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                               id="exam-end-date" name="end_date" required
                               value="{{ old('end_date') }}">
                        @error('end_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-end-hour" class="form-label">Hour <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('end_hour') is-invalid @enderror" id="exam-end-hour" name="end_hour" required>
                            @for ($h = 1; $h <= 12; $h++)
                                <option value="{{ $h }}" {{ old('end_hour') == $h ? 'selected' : '' }}>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</option>
                            @endfor
                        </select>
                        @error('end_hour') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-end-minute" class="form-label">Minute <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('end_minute') is-invalid @enderror" id="exam-end-minute" name="end_minute" required>
                            @for ($m = 0; $m < 60; $m++)
                                <option value="{{ $m }}" {{ old('end_minute') == $m ? 'selected' : '' }}>{{ str_pad($m, 2, '0', STR_PAD_LEFT) }}</option>
                            @endfor
                        </select>
                        @error('end_minute') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="exam-end-period" class="form-label">Period <span aria-hidden="true">*</span></label>
                        <select class="form-select @error('end_period') is-invalid @enderror" id="exam-end-period" name="end_period" required>
                            <option value="AM" {{ old('end_period', 'AM') === 'AM' ? 'selected' : '' }}>AM</option>
                            <option value="PM" {{ old('end_period', 'AM') === 'PM' ? 'selected' : '' }}>PM</option>
                        </select>
                        @error('end_period') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
