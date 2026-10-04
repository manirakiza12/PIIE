@extends('layouts.app')

@section('title', 'Quizzes &amp; Exams — ' . $offering->reference)

@section('content')
    {{-- The breadcrumb is the Offering, because that is where the lecturer came
         from and where they will go back to. An assessment is a document inside a
         course, not a separate product. --}}
    <nav class="mb-3" aria-label="Breadcrumb">
        <a href="{{ route('teacher.course_offerings.index') }}">My Course Offerings</a>
        <span aria-hidden="true"> / </span>
        <a href="{{ route('teacher.course_offerings.show', $offering) }}">{{ $offering->reference }}</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page">Quizzes &amp; Exams</span>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1" data-testid="exams-heading">
                {{ $offering->subject?->name ?? 'This course' }} —
                {{ get_phrase('Quizzes & Exams') }}
            </h1>
            {{-- The course and its term, in the page's own subtitle, so a lecturer who
                 clicked in from the Course Offering tabs is still told which course and
                 which term they are looking at. Without it the tab reads as a generic
                 examination list that happens to be nearby, which is precisely the
                 "thrown back into an unrelated legacy system" feeling. Read from the
                 Offering, never from an exam row, so it is right even when the list
                 below is empty. --}}
            <p class="text-muted mb-1" data-testid="exams-period">
                @if($offering->academicYear || $offering->academicPeriod)
                    {{ $offering->academicYear?->label ?? '—' }}@if($offering->academicPeriod) — {{ $offering->academicPeriod->label }}@endif
                @else
                    {{ get_phrase('Academic period not set on this Course Offering') }}
                @endif
            </p>
            <p class="text-muted mb-0">
                {{ get_phrase('Every assessment below belongs to this Course Offering, and is marked and published through the existing online exam governance. Only students confirmed on this course receive them.') }}
            </p>
        </div>

        @if ($canCreate)
            <a href="{{ route('teacher.course_offerings.exams.create', $offering) }}"
               class="btn btn-primary">Create assessment</a>
        @endif
    </div>

    @if (count($rows) === 0)
        {{-- The honest absence. A course with no assessments yet is a normal state
             on the first day of term, and saying so is more useful than an empty
             table that looks like something failed to load. --}}
        <div class="card" data-testid="exams-empty">
            <div class="card-body">
                <h2 class="h6">No assessments in this course yet</h2>
                <p class="text-muted mb-3">
                    Create a quiz, CAT, mid-term or final exam. It is added to this Course
                    Offering, and only students confirmed on this Offering will see it.
                </p>
                @if ($canCreate)
                    <a href="{{ route('teacher.course_offerings.exams.create', $offering) }}"
                       class="btn btn-outline-primary btn-sm">Create the first assessment</a>
                @else
                    <p class="mb-0 text-muted">
                        You do not have permission to create online assessments.
                    </p>
                @endif
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0" data-testid="exams-table">
                    <caption class="visually-hidden">
                        Assessments in this Course Offering, with their state, window and
                        question count.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Assessment</th>
                            <th scope="col">Type</th>
                            <th scope="col">State</th>
                            <th scope="col">Window</th>
                            <th scope="col" class="text-end">Marks</th>
                            <th scope="col" class="text-end">Questions</th>
                            <th scope="col" class="text-end">Attempts</th>
                            <th scope="col">Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $exam = $row['exam']; @endphp
                            <tr data-testid="exam-row" data-exam-id="{{ $exam->id }}">
                                <th scope="row" class="fw-normal">
                                    <div class="fw-semibold">{{ $exam->title }}</div>
                                    @if ($exam->cancellation_reason)
                                        <div class="small text-danger">
                                            Cancelled: {{ $exam->cancellation_reason }}
                                        </div>
                                    @endif
                                    @if (count($row['readiness_errors']) > 0)
                                        {{-- The engine's own readiness list, shown
                                             before publication rather than as a
                                             failure at publish time. --}}
                                        <details class="small mt-1">
                                            <summary class="text-warning">
                                                {{ count($row['readiness_errors']) }}
                                                thing(s) to fix before this can be published
                                            </summary>
                                            <ul class="mb-0 ms-3">
                                                @foreach ($row['readiness_errors'] as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </th>
                                <td>{{ $row['type_label'] }}</td>
                                <td>
                                    <span class="badge bg-secondary">{{ $row['lifecycle_label'] }}</span>
                                    @if ($row['awaiting_manual'] > 0)
                                        <span class="badge bg-warning text-dark ms-1"
                                              data-testid="awaiting-manual">
                                            {{ $row['awaiting_manual'] }} to mark
                                        </span>
                                    @endif
                                    @if ($row['results_published'] > 0)
                                        <span class="badge bg-success ms-1"
                                              data-testid="results-published">
                                            {{ $row['results_published'] }} result(s) released
                                        </span>
                                    @endif
                                </td>
                                <td class="small">{{ $row['window'] }}</td>
                                <td class="text-end">
                                    {{ $exam->total_marks }}
                                    <span class="text-muted small">/ pass {{ $exam->pass_mark }}</span>
                                </td>
                                <td class="text-end">
                                    {{ $row['question_count'] }}
                                    @if ($row['questions_locked'])
                                        <span class="text-muted small" title="Locked because students have already sat this assessment">locked</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['submissions'] }}</td>
                                <td class="text-nowrap">
                                    {{-- Every one of these is the EXISTING engine's
                                         route, not a Course Offering copy. There is
                                         one question editor, one marking queue and
                                         one publication gate in PIIE. --}}
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('teacher.online_exams.questions.index', $exam->id) }}">Questions</a>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('teacher.online_exams.edit', $exam->id) }}">Settings</a>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('teacher.online_exams.preview', $exam->id) }}">Preview</a>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('teacher.online_exams.results', $exam->id) }}">Results</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Publication and result release follow the institution's existing exam
            governance: an assessment is submitted for review, and results are released
            only through the review the engine already requires. Nothing on this page
            releases a mark to a student.
        </p>
    @endif
@endsection
