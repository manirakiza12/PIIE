@extends('student.navigation')
@section('content')
@php
    /**
     * The student-facing course content index.
     *
     * A student "immediately understands where they are, what they have
     * completed and what comes next". Those are three facts, and this page is
     * built around exactly those three:
     *
     *   WHERE AM I    the Course Unit name, then the module list, in order
     *   WHAT IS DONE  separate facts, never one blended number
     *   WHAT IS NEXT  the outstanding required assessment, or the next lesson
     *
     * PROGRESS IS REPORTED AS DIMENSIONS, NOT AS A SINGLE FIGURE
     *
     * This page used to show one number - lessons completed over lessons
     * published - and then say "You have completed every lesson currently
     * available" as though that settled the matter. With a required assessment
     * waiting, that sentence was the problem: it told a student they were done
     * while work was still outstanding. Two lessons done is true, and it is not
     * the same claim as "this module is finished".
     *
     * So the three facts are shown as three facts:
     *
     *   Learning content        2 of 2 lessons completed
     *   Required assessments    0 of 1 completed
     *   Modules                 0 of 1 complete
     *
     * WHY THERE IS NO COMBINED PERCENTAGE
     *
     * Folding "2 lessons + 1 assessment" into a single percentage requires a
     * weighting - what share of a module is a lesson worth, what share an
     * assessment - and PIIE has no governed model for that. Any number produced
     * without one is invented arithmetic wearing the clothes of a grade. The
     * lesson percentage is still shown, because it is a real fact about lessons,
     * but it is only ever labelled as a LESSON figure. A governed weighting can
     * arrive with the Gradebook, which is where it belongs.
     *
     * EACH NUMBER COUNTS ONLY WHAT THE STUDENT IS ELIGIBLE TO BE ASSESSED ON
     *
     * Published, released lessons in published, released modules. A draft, an
     * unreleased lesson and an archived module contribute to neither numerator
     * nor denominator. Assessments count only when they are required, visible and
     * evaluable - an optional task, and an assessment the student cannot see,
     * contribute to neither.
     *
     * Opening a lesson or an assessment records engagement and can never mark
     * either complete. "Not started" / "In progress" / "Completed" is shown per
     * lesson so a student can see their own position, not just an aggregate.
     */
@endphp

@include('teacher.course_offerings.content._surface_styles')

@php
    /**
     * THE NEXT THING TO DO.
     *
     * Ordered by what actually unblocks the student, not by what is nearest in
     * the file. An outstanding REQUIRED assessment comes first, because a lesson
     * the student has already finished is not work; a module cannot be completed
     * while a required assessment is pending, however many lessons are done. Only
     * when nothing required is outstanding does an unfinished lesson become the
     * suggestion.
     *
     * Both are searched in module and reading order, so this agrees with the list
     * below it rather than jumping around.
     */
    $nextAssessment = null;
    $nextAssessmentModule = null;

    foreach ($modules as $candidate) {
        foreach (($moduleCompletion[$candidate->id]['assessments'] ?? []) as $row) {
            if ($row['required'] && ! $row['satisfied']) {
                $nextAssessment = $row;
                $nextAssessmentModule = $candidate;
                break 2;
            }
        }
    }

    $nextLesson = null;
    $nextLessonModule = null;

    if ($nextAssessment === null) {
        foreach ($modules as $candidate) {
            foreach ($candidate->lessons as $candidateLesson) {
                if (! in_array((int) $candidateLesson->id, $completedIds, true)) {
                    $nextLesson = $candidateLesson;
                    $nextLessonModule = $candidate;
                    break 2;
                }
            }
        }
    }

    // "Up to date" is only true when NOTHING required is outstanding. The old
    // version derived this from lessons alone, which is how a student with a
    // pending assessment was congratulated for finishing the course.
    $nothingOutstanding = ! $hasOutstandingRequirements;
@endphp

<div class="mainSection-title">
    <a href="{{ route('student.my_courses') }}" class="btn btn-sm btn-outline-secondary mb-2">Back to My Courses</a>
    <h4 class="mb-1">{{ $unitName }}</h4>
    <p class="text-muted mb-0">
        {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif

{{-- ══ Where am I, and how am I doing ══════════════════════════════════════ --}}
<section class="eSection-wrap mb-3" aria-labelledby="cc-student-progress-heading">
    <h5 id="cc-student-progress-heading" class="mb-2">Your progress</h5>

    @if($total === 0 && $modulesTotal === 0)
        <div class="border rounded p-4 text-center" role="status">
            <h6>No lessons are available yet.</h6>
            <p class="text-muted mb-0">
                Your lecturer has not released any lessons for this course yet. They will appear
                here as soon as they are published.
            </p>
        </div>
    @else
        <div class="border rounded p-3" data-testid="cc-student-progress">

            {{-- ── DIMENSION 1: LEARNING CONTENT ───────────────────────────
                 A real, factual figure about LESSONS. The percentage is
                 measured against lessons and is labelled as such, so it cannot
                 be read as a claim about the course being finished. --}}
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-1">
                    <span class="text-muted small text-uppercase">Learning content</span>
                    <span class="small text-muted" data-testid="cc-lesson-percent">{{ $percent }}% of lessons</span>
                </div>
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
                    <span class="fs-5 fw-semibold" data-testid="cc-progress-count">
                        {{ $completed }} of {{ $total }} lessons completed
                    </span>
                </div>

                {{-- The value is also the text above it: a percentage alone is
                     unreadable to a screen reader and meaningless without a
                     denominator. --}}
                <div class="progress" role="progressbar" style="height:10px"
                     aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ $completed }} of {{ $total }} lessons completed">
                    <div class="progress-bar bg-success" style="width: {{ $percent }}%"></div>
                </div>
            </div>

            {{-- ── DIMENSION 2: REQUIRED ASSESSMENTS ───────────────────────
                 A separate count, never folded into the lesson figure. Optional
                 tasks are deliberately absent from both sides: supplementary
                 work that appeared in this number would stop being optional. --}}
            <div class="mb-3" data-testid="cc-required-assessments">
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-1">
                    <span class="text-muted small text-uppercase">Required assessments</span>
                    @if($requiredAssessments === 0)
                        <span class="small text-muted">None required</span>
                    @else
                        <span class="small
                            @if($outstandingAssessments > 0) text-warning fw-semibold
                            @else text-success fw-semibold @endif">
                            {{ $outstandingAssessments > 0 ? $outstandingAssessments.' outstanding' : 'All done' }}
                        </span>
                    @endif
                </div>
                <div class="fs-6 fw-semibold">
                    {{ $satisfiedAssessments }} of {{ $requiredAssessments }} completed
                </div>
            </div>

            {{-- ── DIMENSION 3: MODULES ──────────────────────────────────── --}}
            <div data-testid="cc-modules-complete">
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-1">
                    <span class="text-muted small text-uppercase">Modules</span>
                    <span class="small
                        @if($modulesComplete === $modulesTotal) text-success fw-semibold
                        @else text-muted @endif">
                        {{ $modulesComplete === $modulesTotal ? 'All complete' : $modulesComplete.' of '.$modulesTotal.' complete' }}
                    </span>
                </div>
                <div class="fs-6 fw-semibold">
                    {{ $modulesComplete }} of {{ $modulesTotal }} complete
                </div>
            </div>

            {{-- ── WHAT COMES NEXT ─────────────────────────────────────────
                 The one thing that moves the student forward. A required
                 assessment outranks an unfinished lesson because a lesson they
                 have already read is not work, and no amount of lesson
                 completion completes a module with a pending assessment. --}}
            <hr class="my-3">

            <p class="small text-uppercase text-muted mb-1">Continue with</p>

            @if($nextAssessment)
                <p class="mb-1">
                    <span class="text-muted small">
                        Module {{ $nextAssessmentModule->sequence }} &mdash; {{ $nextAssessmentModule->title }}
                    </span><br>
                    <a class="fw-semibold"
                       href="{{ route('student.courses.assignments.show', [$offering->id, $nextAssessment['id']]) }}">{{ $nextAssessment['title'] }}</a>
                </p>
                <p class="small text-muted mb-0">
                    {{ $nextAssessment['state_label'] }} &middot;
                    {{ $nextAssessment['max_marks'] }} marks
                    @if($nextAssessment['due_label'])
                        &middot; Due {{ $nextAssessment['due_label'] }}
                    @endif
                </p>
            @elseif($nextLesson)
                <p class="mb-0">
                    <span class="text-muted small">
                        Module {{ $nextLessonModule->sequence }} &mdash; {{ $nextLessonModule->title }}
                    </span><br>
                    <a class="fw-semibold"
                       href="{{ route('student.courses.content.lessons.show', [$offering->id, $nextLesson->id]) }}">{{ $nextLesson->title }}</a>
                    <span class="small text-muted">&middot; not completed yet</span>
                </p>
            @else
                {{-- Reachable ONLY when nothing required is outstanding. The old
                     version reached here with a required assessment pending and
                     told the student they had finished the course. --}}
                <p class="mb-0" data-testid="cc-up-to-date">
                    <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                    <strong>You are up to date.</strong>
                    Every lesson currently available is complete
                    @if($requiredAssessments > 0)
                        and every required assessment has been returned to you
                    @endif
                    &mdash; so every module open to you is complete.
                    New work will appear here when your lecturer releases it.
                </p>
            @endif
        </div>
    @endif
</section>

{{-- ══ The modules ═══════════════════════════════════════════════════════ --}}
<section class="eSection-wrap" aria-labelledby="cc-student-modules-heading">
    <h5 id="cc-student-modules-heading" class="mb-3">Course content</h5>

    @if($modules->isEmpty())
        <div class="border rounded p-4 text-center" role="status">
            <h6>Nothing to study yet.</h6>
            <p class="text-muted mb-0">
                Your lecturer has not published any lessons for this course yet. You do not need
                to do anything &mdash; they will appear here when they are ready.
            </p>
        </div>
    @else
        @foreach($modules as $module)
            @php
                /**
                 * This module's completion row.
                 *
                 * `forCourseOffering()` and `studentContent()` read the SAME
                 * modules from the SAME Offering, so the key is always present -
                 * the fallback is here because the alternative is a null that is
                 * then dereferenced a dozen lines below, which is a crash dressed
                 * up as a guard. If the two ever do disagree, the page degrades
                 * to "no lessons, no assessments outstanding" rather than
                 * returning a 500 to a student who did nothing wrong.
                 */
                $completion = $moduleCompletion[$module->id] ?? [
                    'lessons_total' => 0, 'lessons_completed' => 0, 'lessons_complete' => true,
                    'assessments_total' => 0, 'assessments_required' => 0, 'assessments_gating' => 0,
                    'assessments_satisfied' => 0, 'assessments_outstanding' => 0,
                    'assessments_complete' => true, 'is_complete' => true,
                    'assessments' => [], 'unevaluable' => [],
                ];
                $assessments = $completion['assessments'];
                $required = array_values(array_filter($assessments, fn ($row) => $row['required']));
                $optional = array_values(array_filter($assessments, fn ($row) => ! $row['required']));
            @endphp

            <article class="border rounded p-3 mb-3" data-testid="cc-student-module">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                    <h6 class="mb-0">
                        <span class="text-muted">Module {{ $module->sequence }}</span> &mdash; {{ $module->title }}
                    </h6>

                    {{-- The module's OWN state, and the reason for it. Every
                         branch names what is outstanding, so a student is never
                         left to infer why a module they have finished reading is
                         not marked complete. --}}
                    <span class="badge text-nowrap
                        @if($completion['is_complete']) cc-badge-complete
                        @elseif($completion['assessments_outstanding'] > 0) cc-badge-pending
                        @else cc-badge-progress @endif"
                          data-testid="cc-module-state">
                        @if($completion['is_complete'])
                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Completed
                        @elseif($completion['assessments_outstanding'] > 0)
                            Assessment pending
                        @else
                            In progress
                        @endif
                    </span>
                </div>

                @if($module->summary)
                    <p class="small text-muted mb-2">{{ $module->summary }}</p>
                @endif

                {{-- What this module needs, as SEPARATE facts with their own
                     denominators. Deliberately no combined figure: folding a
                     lesson count and an assessment count into one number needs
                     a weighting, and PIIE has no governed one. --}}
                <p class="small text-muted mb-2" data-testid="cc-module-summary">
                    <i class="bi bi-check-lg
                        @if($completion['lessons_complete']) text-success
                        @else text-muted @endif" aria-hidden="true"></i>
                    Lessons complete: {{ $completion['lessons_completed'] }} of {{ $completion['lessons_total'] }}

                    @if($required !== [])
                        <span class="ms-2">
                            <i class="bi bi-check-lg
                                @if($completion['assessments_outstanding'] === 0) text-success
                                @else text-muted @endif" aria-hidden="true"></i>
                            @if($completion['assessments_outstanding'] > 0)
                                Required assessment pending
                            @else
                                Required assessments complete
                            @endif
                        </span>
                    @endif
                </p>

                @if($module->lessons->isNotEmpty())
                    <ul class="list-group list-group-flush">
                        @foreach($module->lessons as $lesson)
                            @php
                                $isComplete = in_array((int) $lesson->id, $completedIds, true);
                                // "In progress" means the student has genuinely
                                // OPENED the lesson. It is display state only and
                                // is never counted towards the progress figure
                                // above, which still means "lessons actually
                                // completed".
                                $isInProgress = ! $isComplete
                                    && in_array((int) $lesson->id, $inProgressIds, true);
                            @endphp
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                <a class="text-decoration-none d-flex align-items-center gap-2 text-dark"
                                   href="{{ route('student.courses.content.lessons.show', [$offering->id, $lesson->id]) }}">
                                    {{--
                                        THE STATE ICON.

                                        An earlier version emitted a character
                                        entity for the check and the circle from
                                        inside a Blade ESCAPED-OUTPUT expression.
                                        Escaping turned the entity into literal
                                        text, so the browser handed the student the
                                        characters "&#9675;" instead of a circle -
                                        a raw entity leaking into the page. The
                                        lesson page's own checkmark was unaffected
                                        because that one was written as raw HTML,
                                        which is exactly the difference that made
                                        this easy to misread.

                                        Now it uses the Bootstrap Icons font PIIE
                                        already loads in the student layout, so this
                                        adds no bytes and matches the rest of the
                                        portal. The markup is a static class name,
                                        not lesson data, so nothing a lecturer
                                        writes can reach it.

                                        `aria-hidden` is deliberate: the icon is a
                                        visual cue, and the STATE TEXT beside it is
                                        the screen-reader-legible indication. Shape
                                        and colour are never the only way a state
                                        is conveyed.
                                    --}}
                                    @if($isComplete)
                                        <i class="bi bi-check-circle-fill text-success cc-mark" aria-hidden="true"></i>
                                    @elseif($isInProgress)
                                        <i class="bi bi-circle-half text-warning cc-mark" aria-hidden="true"></i>
                                    @else
                                        <i class="bi bi-circle text-muted cc-mark" aria-hidden="true"></i>
                                    @endif
                                    <span>
                                        {{ $lesson->title }}
                                        @if($lesson->estimated_minutes)
                                            <span class="small text-muted">&middot; {{ $lesson->estimatedDurationLabel() }}</span>
                                        @endif
                                    </span>
                                </a>
                                <span class="small text-nowrap
                                    @if($isComplete) text-success fw-semibold
                                    @elseif($isInProgress) text-warning fw-semibold
                                    @else text-muted @endif">
                                    {{ $isComplete ? 'Completed' : ($isInProgress ? 'In progress' : 'Not started') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- ══ THE ASSESSMENTS ON THIS MODULE ═══════════════════════
                     The student's real Course Offering assignments, linked to
                     the workflow that already exists. No second implementation,
                     no separate submission route: this is a list OF the real
                     thing, and the button opens it.

                     Only assessments a student can SEE are here. A draft, an
                     unreleased assessment and one from another Offering, module
                     or tenant never reach this list, and - the part that matters
                     for correctness rather than privacy - a required assessment
                     the student cannot see also does not BLOCK them. --}}
                @foreach(['Required' => $required, 'Optional' => $optional] as $groupLabel => $group)
                    @if($group !== [])
                        <p class="small text-uppercase text-muted mt-3 mb-1">
                            {{ $groupLabel }} {{ $groupLabel === 'Required' ? 'assessment' : 'assessments' }}
                        </p>

                        <ul class="list-group list-group-flush" data-testid="cc-module-{{ strtolower($groupLabel) }}-assessments">
                            @foreach($group as $row)
                                <li class="list-group-item px-0 cc-assessment"
                                    data-testid="cc-assessment-{{ $row['id'] }}">

                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <a class="fw-semibold text-decoration-none"
                                               href="{{ route('student.courses.assignments.show', [$offering->id, $row['id']]) }}">{{ $row['title'] }}</a>

                                            <div class="small text-muted">
                                                {{ $row['max_marks'] }} marks
                                                @if($row['due_label'])
                                                    &middot; Due {{ $row['due_label'] }}
                                                @endif
                                            </div>
                                        </div>

                                        <span class="small text-nowrap
                                            @if($row['satisfied']) text-success fw-semibold
                                            @elseif($row['state'] === 'not_submitted') text-warning fw-semibold
                                            @else text-muted fw-semibold @endif"
                                              data-testid="cc-assessment-state">
                                            {{ $row['state_label'] }}
                                        </span>
                                    </div>

                                    {{-- REQUIREDNESS, SAID PLAINLY.

                                         A required assessment gates its module.
                                         An optional one never does, in any state,
                                         for anyone - so it is labelled as
                                         supplementary rather than left to look
                                         like work that was forgotten. --}}
                                    <div class="small mt-1">
                                        @if($row['required'])
                                            <span class="text-danger fw-semibold" data-testid="cc-assessment-required">
                                                Required to complete this module
                                            </span>
                                            <span class="text-muted">
                                                &middot; {{ $row['rule_label'] ?? 'Completion rule not yet available' }}
                                            </span>
                                        @else
                                            <span class="text-muted" data-testid="cc-assessment-optional">
                                                Optional &mdash; supplementary work. It does not affect
                                                your module completion.
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-2">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="{{ route('student.courses.assignments.show', [$offering->id, $row['id']]) }}"
                                           data-testid="cc-assessment-open">Open assignment</a>

                                        @if(! $row['open'] && $row['refusal'])
                                            <span class="small text-muted ms-2">{{ $row['refusal'] }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            </article>
        @endforeach
    @endif
</section>

<style>
    /* Sized as a fixed-width column so the three states line up down the list
       regardless of the glyph each icon font happens to draw. */
    .cc-mark { font-size: 1.05rem; line-height: 1; width: 1.25rem; text-align: center; flex: 0 0 auto; }
    li a .cc-mark + span { min-width: 0; }

    /* Module state. Tinted rather than solid so the badge sits with the page's
       existing surfaces instead of becoming the loudest thing in it - the point
       of this section is the words, not the colour. */
    .cc-badge-complete { background: #e7f6ec; color: #14663a; }
    .cc-badge-pending  { background: #fdf1dc; color: #8a5300; }
    .cc-badge-progress { background: #eef1f5; color: #465260; }
</style>
@endsection
