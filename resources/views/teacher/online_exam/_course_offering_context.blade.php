{{--
    THE ACADEMIC CONTEXT OF A COURSE OFFERING EXAM, DERIVED AND READ-ONLY.

    ── WHY THIS IS A PARTIAL AND NOT INLINE ────────────────────────────────────

    The same four facts have to appear in three places: the generic create page once
    an Offering is chosen, the generic edit page for an Offering-backed exam, and the
    offering-scoped create page. Inline, they would be three copies that could
    disagree about what "the academic context" is - and the two that had already been
    written used different labels, which is how a course's header came to say
    "Semester / Period" on one page and "Academic period" on another.

    ── WHY IT IS READ-ONLY ─────────────────────────────────────────────────────

    Every value here comes from the Course Offering row, read through relations the
    rest of the product already uses. None of it is a `<select>`, and none of it is
    a hidden input the server reads back. A lecturer cannot create an "assessment
    for this course" that is actually about another Course Unit, cannot point it at a
    legacy class, and cannot restate the academic year - because there is nothing to
    restate.

    ── WHY THE FIELD NAMES ARE THESE ──────────────────────────────────────────

    `academic_term('subject', ...)` resolves to the institution's own wording for the
    column, which is "Course Unit" in the higher-education configuration and "Subject"
    elsewhere. The literal labels here match what the Course Offering workspace and
    the offering-scoped exam form already print, so a lecturer reading two pages of
    the same workflow sees one vocabulary.
--}}
@php
    $courseUnitLabel = function_exists('academic_term') ? academic_term('subject', auth()->user()->school_id) : 'Course Unit';
    $courseUnit = $offering->subject;
    $academicYear = $offering->academicYear;
    $academicPeriod = $offering->academicPeriod;
    $lecturerName = $lecturer->name ?? null;
@endphp

<div class="card border-primary-subtle mb-4" data-testid="exam-offering-context">
    <div class="card-header bg-body-tertiary">
        {{ get_phrase('Course Offering exam') }}
        <span class="text-muted small fw-normal">
            {{ get_phrase('This academic context comes from the course. It cannot be changed here.') }}
        </span>
    </div>
    <div class="card-body py-3">
        <dl class="row mb-0 small">
            <dt class="col-sm-3 col-lg-2">{{ get_phrase('Course Offering') }}</dt>
            <dd class="col-sm-9 col-lg-10 mb-2" data-testid="exam-offering-reference">
                {{ $offering->subject?->name ?? (get_phrase('Course Offering') . ' #' . $offering->id) }}
                @if($offering->reference)
                    <span class="text-muted">({{ $offering->reference }})</span>
                @endif
            </dd>

            <dt class="col-sm-3 col-lg-2">{{ $courseUnitLabel }}</dt>
            <dd class="col-sm-9 col-lg-10 mb-2" data-testid="exam-offering-course-unit">
                @if($courseUnit)
                    {{ $courseUnit->code ? $courseUnit->code . ' — ' : '' }}{{ $courseUnit->name }}
                @else
                    <span class="text-muted">{{ get_phrase('Not set on this Course Offering') }}</span>
                @endif
            </dd>

            <dt class="col-sm-3 col-lg-2">{{ get_phrase('Academic Year') }}</dt>
            <dd class="col-sm-9 col-lg-10 mb-2" data-testid="exam-offering-academic-year">
                {{ $academicYear?->label ?? '—' }}
            </dd>

            <dt class="col-sm-3 col-lg-2">{{ get_phrase('Semester / Period') }}</dt>
            <dd class="col-sm-9 col-lg-10 mb-2" data-testid="exam-offering-academic-period">
                {{ $academicPeriod?->label ?? '—' }}
            </dd>

            <dt class="col-sm-3 col-lg-2">{{ get_phrase('Lecturer') }}</dt>
            <dd class="col-sm-9 col-lg-10 mb-0" data-testid="exam-offering-lecturer">
                {{ $lecturerName ?? '—' }}
            </dd>
        </dl>

        <p class="text-muted small mb-0 mt-3">
            {{ get_phrase('Only students with a confirmed registration on this Course Offering will receive this exam. A class, programme or academic session is deliberately not chosen: choosing one would enrol every student of it and bypass that confirmation.') }}
        </p>
    </div>
</div>