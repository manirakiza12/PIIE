@extends('student.navigation')

@section('content')
    {{--
        THE STUDENT'S QUIZZES & EXAMS, INSIDE A COURSE OFFERING.

        ── EVERY STATE HERE IS A FACT, NOT A COLOUR ──────────────────────────
        Upcoming, Available, In progress, Submitted — under review, Results
        available, Closed, No attempts remaining, Withdrawn. The same rule the
        assignments page follows, for the same reason: a chip that is only a hue
        excludes a colour-blind reader and a screen reader equally, and "In
        progress" and "Submitted" are different claims about a student's work.

        ── NOTHING ON THIS PAGE SUBMITS ANYTHING ─────────────────────────────
        Every button below is a LINK, and each goes to the existing online exam
        engine: read the instructions, start, resume, or view a released result.
        There is no start button, no timer and no answer field here, because a
        second implementation of the attempt lifecycle is exactly the thing that
        would drift from the first - and the engine already handles refresh,
        disconnection, resume and the deliberate final submission.

        ── OPENING IS NOT SUBMITTING ─────────────────────────────────────────
        A student mid-attempt is shown as "In progress" and offered RESUME, never
        a fresh Start. That is what stops a lost connection from becoming an
        abandoned attempt and a second one.
    --}}

    <div class="mainSection-title">
        <a href="{{ route('student.my_courses') }}" class="btn btn-sm btn-outline-secondary mb-2">Back to My Courses</a>
        <h4 class="mb-1">Quizzes &amp; Exams &mdash; {{ $offering->subject?->name ?? $offering->reference }}</h4>
        <p class="text-muted mb-0">
            {{ $offering->academicYear?->label ?? '—' }} &middot;
            {{ $offering->academicPeriod?->label ?? '—' }}
        </p>
    </div>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <nav class="mb-3" aria-label="Course sections">
        <a href="{{ route('student.courses.show', $offering) }}">&larr; Back to {{ $offering->reference }}</a>
    </nav>

    @if (count($rows) === 0)
        {{-- The honest absence. "None set yet" and "none available to you" are
             different, but this service only ever returns assessments the
             institution has PUBLISHED for this Offering, so from a student's point
             of view there is one truthful thing to say. --}}
        <div class="border rounded p-4 text-center" role="status" data-testid="exams-empty">
            <h6>No quizzes or exams have been set for this course yet.</h6>
            <p class="text-muted mb-0">
                When your lecturer publishes an assessment for this course it will appear
                here, with its opening time and how long you have. You do not need to do
                anything until then.
            </p>
        </div>
    @else
        <p class="small text-muted">
            Opening an assessment does <strong>not</strong> submit it. You read the
            instructions, answer, and hand it in deliberately &mdash; and your answers are
            saved as you go, so a lost connection does not lose your work.
        </p>

        @foreach ($rows as $row)
            <article class="border rounded p-3 mb-3"
                     data-testid="exam-student-row"
                     data-status="{{ $row['status'] }}"
                     data-exam-id="{{ $row['id'] }}">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h5 class="mb-1">{{ $row['title'] }}</h5>
                        <p class="text-muted small mb-0">
                            {{ $row['type_label'] }} &middot;
                            {{ $row['question_count'] }} question(s) &middot;
                            {{ $row['total_marks'] }} marks, pass {{ $row['pass_mark'] }} &middot;
                            {{ $row['duration_minutes'] }} minutes
                        </p>
                    </div>

                    <span class="badge bg-secondary" data-testid="exam-status">
                        {{ $row['status_label'] }}
                    </span>
                </div>

                <p class="small mb-2 mt-2">
                    <strong>Window:</strong> {{ $row['window'] }}
                    @if ($row['attempts_allowed'] > 1)
                        <br><strong>Attempts:</strong>
                        {{ $row['attempts_used'] }} of {{ $row['attempts_allowed'] }} used
                    @endif
                </p>

                @if ($row['detail'])
                    <p class="small mb-2" data-testid="exam-detail">{{ $row['detail'] }}</p>
                @endif

                <div class="d-flex flex-wrap gap-2">
                    @if ($row['action'])
                        {{-- A LINK, always. This page cannot submit an attempt, and
                             cannot start one either - starting is a deliberate
                             action on the engine's own instructions screen. --}}
                        <a href="{{ $row['action'] }}"
                           class="btn btn-sm {{ $row['status'] === 'results_available' ? 'btn-outline-success' : 'btn-primary' }}"
                           data-testid="exam-action">
                            {{ $row['action_label'] }}
                        </a>
                    @endif

                    @if ($row['result_url'])
                        <a href="{{ $row['result_url'] }}" class="btn btn-sm btn-outline-success">
                            View result
                        </a>
                    @endif
                </div>
            </article>
        @endforeach

        <div class="border rounded p-3 bg-light">
            <h6 class="mb-2">How results work here</h6>
            <p class="small mb-0 text-muted">
                Your lecturer marks the written questions by hand. Once marking is
                complete it goes to the institution for review, and your result appears
                here only once that review has released it &mdash; never before, whatever
                the assessment's release setting says. An assessment marked
                &ldquo;under review&rdquo; means your work is with the lecturer or the
                academic office, not that anything is wrong with it.
            </p>
        </div>
    @endif
@endsection
