{{--
    THE COURSE PROGRESS GRID.
     *
     * Moodle's Activity Completion report: every student against every module. The
     * screen a lecturer opens to answer "who is behind, and where".
     *
     * NOTHING HERE DECIDES ANYTHING. Every tick, count and percentage is read from
     * `CourseProgressGrid`, which in turn reads `ModuleCompletion` and
     * `AssignmentOutcome` - the same two things that decide what a student is
     * allowed to do. This view draws their answers; it never forms its own.
--}}

@extends('teacher.navigation')

@section('content')
@include('teacher.course_offerings._workspace_nav')

<div class="mainSection-title">
        <a href="{{ route('teacher.course_offerings.students', $offering->id) }}"
           class="btn btn-sm btn-outline-secondary mb-2">Back to Students</a>
        <h4 class="mb-1">Progress &mdash; {{ $offering->subject?->name ?? $offering->reference }}</h4>
        <p class="text-muted mb-0 small">
            {{ $offering->academicYear?->label ?? '' }}
            @if($offering->academicPeriod?->label) &middot; {{ $offering->academicPeriod->label }} @endif
        </p>
    </div>

    @if($withheld)
        <div class="alert alert-secondary" role="status">{{ $withheld }}</div>
    @else
        {{-- The headline numbers a lecturer actually scans for. --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="fs-4">{{ $grid['totals']['students'] }}</div>
                    <div class="small text-muted">Students enrolled</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="fs-4">{{ $grid['totals']['modules'] }}</div>
                    <div class="small text-muted">Topics in this course</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="fs-4">{{ $grid['totals']['needing_attention'] }}</div>
                    <div class="small text-muted">Have failed an assessment</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="fs-4">
                        {{ $grid['rows'] === [] ? '—' : $grid['rows'][0]['percent'].'%' }}
                    </div>
                    <div class="small text-muted">First student's overall progress</div>
                </div>
            </div>
        </div>

        @if($grid['modules'] === [])
            <div class="alert alert-info" role="status">
                This Course Offering has no topics yet, so there is no progress to show.
                Create a topic from <strong>Content</strong> first.
            </div>
        @elseif($grid['rows'] === [])
            <div class="alert alert-info" role="status">
                No students are confirmed on this Course Offering yet, so there is no progress to show.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle" data-testid="progress-grid">
                    <caption class="visually-hidden">
                        Progress for every confirmed student against every topic
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="text-nowrap">Student</th>
                            @foreach($grid['modules'] as $module)
                                <th scope="col" class="text-center text-nowrap">
                                    <abbr title="{{ $module['title'] }}">{{ $module['sequence'] }}</abbr>
                                </th>
                            @endforeach
                            <th scope="col" class="text-end text-nowrap">Lessons</th>
                            <th scope="col" class="text-end text-nowrap">Assessments</th>
                            <th scope="col" class="text-end text-nowrap">Overall</th>
                            <th scope="col" class="text-nowrap">Currently on</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grid['rows'] as $row)
                            <tr @if($row['needs_attention']) class="table-warning" @endif>
                                <th scope="row" class="text-nowrap">
                                    {{ $row['student']->name }}
                                    @if($row['needs_attention'])
                                        <span class="badge text-bg-warning ms-1"
                                              title="Has an assessment marked and returned below the pass mark">
                                            needs support
                                        </span>
                                    @endif
                                </th>

                                @foreach($grid['modules'] as $module)
                                    @php $cell = $row['modules'][$module['id']] ?? null; @endphp
                                    <td class="text-center">
                                        @if($cell === null)
                                            <span class="text-muted" title="No content in this topic">&mdash;</span>
                                        @elseif($cell['complete'])
                                            {{-- A tick, not a number. "Done" is the question a
                                                 grid like this is asked to answer. --}}
                                            <span class="text-success" title="Topic complete">&#10003;</span>
                                        @elseif($cell['assessments_outstanding'] > 0)
                                            <span class="text-warning"
                                                  title="{{ $cell['assessments_outstanding'] }} assessment(s) still to pass">
                                                &#9679;
                                            </span>
                                        @else
                                            <span class="text-muted"
                                                  title="{{ $cell['lessons_percent'] }}% of the lessons complete">
                                                &middot;
                                            </span>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="text-end text-nowrap">
                                    {{ $row['lessons_done'] }}/{{ $row['lessons_total'] }}
                                </td>
                                <td class="text-end text-nowrap">
                                    {{ $row['assessments_passed'] }}/{{ $row['assessments_total'] }}
                                </td>
                                <td class="text-end text-nowrap">
                                    <span class="badge {{ $row['percent'] >= 100 ? 'text-bg-success' : ($row['percent'] >= 50 ? 'text-bg-secondary' : 'text-bg-light') }}">
                                        {{ $row['percent'] }}%
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    {{ $row['current_module'] ?? 'All topics complete' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- WHAT THE SYMBOLS MEAN, AND WHY THERE IS A SINGLE NUMBER.
                 A grid full of ticks is unreadable without a key, and a single
                 percentage is meaningless without knowing what went into it. --}}
            <div class="card mt-4">
                <div class="card-body small">
                    <h6 class="card-title mb-2">Reading this table</h6>
                    <ul class="mb-2">
                        <li><span class="text-success">&#10003;</span> &mdash; every required activity in that topic is complete.</li>
                        <li><span class="text-warning">&#9679;</span> &mdash; an assessment is still to pass.</li>
                        <li><span class="text-muted">&middot;</span> &mdash; some lessons done, nothing outstanding.</li>
                    </ul>
                    <p class="mb-2 text-muted">
                        <strong>Overall</strong> averages this student's lesson progress and their
                        assessment progress separately, then combines the two ratios. It is a sorting
                        aid, not a grade &mdash; a lesson and an assessment are not worth the same
                        thing, and PIIE has no governed weighting between them. The two columns beside
                        it are the real figures.
                    </p>
                    <p class="mb-0 text-muted">
                        <strong>Needs support</strong> means an assessment has been marked and returned
                        below its pass mark &mdash; not merely that work is outstanding.
                    </p>
                </div>
            </div>
        @endif
    @endif
@endsection
