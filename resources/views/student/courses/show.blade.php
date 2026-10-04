@extends('student.navigation')

@section('content')

{{--
    THE STUDENT'S COURSE HOME.

    THREE RULES THIS PAGE FOLLOWS, AND WHY

    1. NO INVENTED IMAGE. The cover is shown when one exists. When none does, a
       typographic fallback built from the real course code is drawn instead of a
       stock photograph - a picture of a library over "Business Mathematics" would
       be a false claim about this course, and a placeholder that names the course
       is honest as well as cheaper.

    2. EVERY STATE IS A WORD, NOT ONLY A COLOUR. Each chip carries its own text.
       A chip that is only a colour excludes a colour-blind reader and a screen
       reader equally, and two different states that look alike are worse than no
       list at all.

    3. NOTHING UNFINISHED IS FAKED. Quizzes & Exams and Grades appear in the
       navigation because the course experience is planned to have them, and each
       says plainly that it is not available yet. Neither carries a link. A
       student who clicked through to invented content would have been told
       something untrue about their own course.
--}}

@php
    $header = $header ?? [];
    $progress = $progress ?? [];
    $continueLearning = $continueLearning ?? [];
    $assignments = $assignments ?? [];
    $liveClasses = $liveClasses ?? [];
    $resources = $resources ?? [];
    $sections = $sections ?? [];

    $hasCover = ! empty($header['cover_path']);
    $nextActivity = $continueLearning['next'] ?? null;

    // Due-soon is a THRESHOLD, not a fact, so it is stated as a threshold. A card
    // that showed "due in 2 days" for something due in ten days because the
    // threshold was different would be making the same claim with a different
    // number.
    $dueSoonDays = 7;
@endphp

<div class="ch-home" data-testid="course-home">

    {{-- ══════════════════════════════════════════════════════════════════
         THE HEADER
         ══════════════════════════════════════════════════════════════════ --}}
    <header class="ch-header">
        <div class="ch-header-cover">
            @if ($hasCover)
                {{-- Served by `student.courses.cover`, which re-checks that this
                     student has a confirmed registration on THIS Offering. The
                     bytes live outside the web root, so this is the only way in. --}}
                <img src="{{ route('student.courses.cover', $offering->id) }}"
                     alt="Course image for {{ $header['title'] }}"
                     class="ch-cover-image"
                     data-testid="ch-cover-image">
            @else
                {{-- No cover is a NORMAL state. CourseCoverImage documents the
                     relationship as optional, so this is not a missing thing to
                     be fixed - it is what most courses look like today. The
                     fallback names the course, so a student always knows which one
                     they are looking at. --}}
                <div class="ch-cover-fallback" role="img"
                     aria-label="No course image. Course {{ $header['code'] }}."
                     data-testid="ch-cover-fallback">
                    <span class="ch-cover-code">{{ $header['code'] }}</span>
                </div>
            @endif
        </div>

        <div class="ch-header-body">
            <p class="ch-eyebrow">Course Home</p>
            <h1 class="ch-title" data-testid="ch-course-title">{{ $header['title'] }}</h1>

            <dl class="ch-facts">
                <div class="ch-fact">
                    <dt>Course Unit code</dt>
                    <dd data-testid="ch-course-code">{{ $header['code'] }}</dd>
                </div>

                <div class="ch-fact">
                    <dt>Academic year</dt>
                    <dd>{{ $header['academic_year'] ?: '—' }}</dd>
                </div>

                <div class="ch-fact">
                    <dt>Semester / period</dt>
                    <dd data-testid="ch-period">{{ $header['period'] ?: '—' }}</dd>
                </div>

                <div class="ch-fact">
                    <dt>Status</dt>
                    <dd><span class="ch-chip ch-chip-muted">{{ $header['status_label'] }}</span></dd>
                </div>

                <div class="ch-fact ch-fact-wide">
                    <dt>Lecturer{{ count($header['lecturers']) === 1 ? '' : 's' }}</dt>
                    <dd data-testid="ch-lecturers">
                        @forelse ($header['lecturers'] as $lecturer)
                            {{-- The name, and the state that qualifies it. A course
                                 whose term opens tomorrow lists its teacher with
                                 "starts 1 Oct", which is true; omitting them would
                                 be the misleading choice. `is_current` comes from
                                 the same method that gates teaching authority, so
                                 the label and the authority cannot disagree. --}}
                            <span class="ch-person" data-testid="ch-lecturer-name">
                                <span class="ch-person-name">{{ $lecturer['name'] }}</span>
                                <span class="ch-person-role">{{ $lecturer['role_label'] }}</span>
                                @if ($lecturer['is_current'])
                                    <span class="ch-person-state">Teaching now</span>
                                @elseif ($lecturer['note'])
                                    <span class="ch-person-state">{{ $lecturer['note'] }}</span>
                                @endif
                            </span>
                        @empty
                            {{-- Reached only when there is genuinely nobody ALLOCATED,
                                 not merely nobody teaching at this instant. A course
                                 with no allocation has nobody to name, and saying so
                                 is the honest answer - it is never a reason to invent
                                 one. --}}
                            <span class="ch-muted">No lecturer is allocated to this course.</span>
                        @endforelse
                    </dd>
                </div>
            </dl>

            {{-- PROGRESS AS DIMENSIONS. Three separate counts, never folded into
                 one number - see `StudentCourseHome::progress()` for why. Each bar
                 is labelled with its own numerator and denominator, so a glance at
                 the page is enough to know exactly what is being counted. --}}
            <div class="ch-progress" data-testid="ch-progress">
                @foreach ([
                    ['label' => 'Learning content', 'done' => $progress['lessons_completed'] ?? 0, 'total' => $progress['lessons_total'] ?? 0, 'key' => 'lessons'],
                    ['label' => 'Required assessments', 'done' => $progress['assessments_completed'] ?? 0, 'total' => $progress['assessments_total'] ?? 0, 'key' => 'assessments'],
                    ['label' => 'Modules', 'done' => $progress['modules_complete'] ?? 0, 'total' => $progress['modules_total'] ?? 0, 'key' => 'modules'],
                ] as $dimension)
                    <div class="ch-progress-item">
                        <div class="ch-progress-head">
                            <span class="ch-progress-label">{{ $dimension['label'] }}</span>
                            <span class="ch-progress-count">
                                {{ $dimension['done'] }} of {{ $dimension['total'] }}
                            </span>
                        </div>
                        <div class="ch-progress-track" role="img"
                             aria-label="{{ $dimension['label'] }}: {{ $dimension['done'] }} of {{ $dimension['total'] }} complete">
                            <div class="ch-progress-fill"
                                 style="width: {{ $dimension['total'] > 0
                                     ? round($dimension['done'] / $dimension['total'] * 100)
                                     : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </header>

    {{-- ══════════════════════════════════════════════════════════════════
         THE NAVIGATION
         ══════════════════════════════════════════════════════════════════ --}}
    <nav class="ch-nav" aria-label="Course sections" data-testid="ch-nav">
        @foreach ($sections as $section)
            @if ($section['available'])
                <a class="ch-nav-item{{ request()->routeIs('student.courses.show') && $section['key'] === 'overview' ? ' is-current' : '' }}"
                   href="{{ $section['url'] }}"
                   data-testid="ch-nav-{{ $section['key'] }}"
                   data-section-key="{{ $section['key'] }}"
                   data-count="{{ $section['count'] ?? 0 }}">
                    <span class="ch-nav-label">{{ $section['label'] }}</span>
                    @if (! empty($section['count']))
                        {{-- A ZERO IS DELIBERATELY NOT RENDERED AS A BADGE. "0
                             assessments" beside a tab is noise; the tab's own page
                             says so in words. The attribute above still carries the
                             real number, so the figure is testable and never a
                             guess. --}}
                        <span class="ch-nav-count">{{ $section['count'] }}</span>
                    @endif
                </a>
            @else
                {{-- SHOWN, NOT HIDDEN, AND NOT LINKED. A navigation that quietly
                     omits two of its own sections makes a student wonder whether
                     they have been given the wrong account. One that says "not
                     available yet" tells them the truth and still admits the plan.
                     And it links NOWHERE, because a link to a page that 404s is
                     worse than no link. --}}
                <span class="ch-nav-item is-unavailable"
                      data-testid="ch-nav-{{ $section['key'] }}"
                      aria-disabled="true">
                    <span class="ch-nav-label">{{ $section['label'] }}</span>
                    <span class="ch-nav-note">Not available yet</span>
                </span>
            @endif
        @endforeach
    </nav>

    {{-- ══════════════════════════════════════════════════════════════════
         THE FOUR CARDS
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="ch-cards">

        {{-- ── 1. CONTINUE LEARNING ────────────────────────────────────── --}}
        <section class="ch-card ch-card-continue" aria-labelledby="ch-card-continue-h" data-testid="ch-card-continue">
            <h2 class="ch-card-title" id="ch-card-continue-h">Continue Learning</h2>

            @if ($nextActivity)
                @php
                    $isOverdue = $nextActivity['overdue'] ?? false;
                    $dueSoon = ! empty($nextActivity['due'])
                        && $nextActivity['due']->between(now()->startOfDay(), now()->addDays($dueSoonDays)->endOfDay());
                @endphp

                <p class="ch-card-hint">
                    @if ($isOverdue)
                        Overdue — this is the one thing that needs your attention today.
                    @elseif ($dueSoon)
                        Due soon.
                    @else
                        The next thing in this course.
                    @endif
                </p>

                <a class="ch-next" href="{{ $nextActivity['url'] }}" data-testid="ch-continue-next">
                    <span class="ch-next-kind">{{ $nextActivity['kind_label'] }}</span>
                    <span class="ch-next-title">{{ $nextActivity['title'] }}</span>
                    <span class="ch-next-module">{{ $nextActivity['module_title'] }}</span>

                    <span class="ch-next-meta">
                        @if ($isOverdue)
                            <span class="ch-chip ch-chip-missing">Overdue</span>
                        @elseif (! empty($nextActivity['due']))
                            <span class="ch-chip ch-chip-muted">Due {{ $nextActivity['due']->format('j M Y') }}</span>
                        @endif

                        @if (! empty($nextActivity['required']))
                            <span class="ch-chip ch-chip-draft">Required</span>
                        @endif

                        @if ($nextActivity['in_progress'] ?? false)
                            <span class="ch-chip ch-chip-progress">In progress</span>
                        @endif

                        @if (! empty($nextActivity['state_short']))
                            <span class="ch-chip {{ $nextActivity['state_chip'] }}">{{ $nextActivity['state_short'] }}</span>
                        @endif
                    </span>
                </a>

                @if (! empty($continueLearning['also']))
                    <details class="ch-more">
                        <summary>Also waiting for you ({{ count($continueLearning['also']) }})</summary>
                        <ul class="ch-list">
                            @foreach ($continueLearning['also'] as $also)
                                <li>
                                    <a href="{{ $also['url'] }}">
                                        <span class="ch-list-kind">{{ $also['kind_label'] }}</span>
                                        {{ $also['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @else
                {{-- "NOTHING TO DO" IS AN ANSWER, AND IT HAS A REASON.
                     The common real case is an assessment handed in and awaiting a
                     mark. Saying "you have finished everything" would be a claim
                     PIIE cannot support, and "keep going" would point at a lesson
                     the student has already read. So the message distinguishes:
                     work is with the lecturer, or there is genuinely nothing
                     outstanding. --}}
                <p class="ch-card-hint" data-testid="ch-continue-waiting">
                    @if ($continueLearning['waiting_on_lecturer'] ?? false)
                        Everything you can do right now is done. Work you have
                        submitted is with your lecturer.
                    @else
                        There is nothing outstanding for you in this course at the
                        moment.
                    @endif
                </p>
            @endif
        </section>

        {{-- ── 2. ASSIGNMENTS ─────────────────────────────────────────── --}}
        <section class="ch-card ch-card-assignments" aria-labelledby="ch-card-assignments-h" data-testid="ch-card-assignments">
            <h2 class="ch-card-title" id="ch-card-assignments-h">Assignments</h2>

            @if (($assignments['actionable_count'] ?? 0) > 0)
                {{-- The count, the noun and the overdue clause are assembled in PHP
                     rather than stitched together around a conditional.

                     TWO BLADE TRAPS, and this comment names neither by writing it,
                     because writing it is what caused the failure:

                     1. A directive is only recognised when its at-sign is NOT
                        preceded by a word character. So "hand in" followed
                        immediately by a conditional is left as literal text, while
                        its matching close IS compiled - and the template dies
                        complaining about a stray "else" somewhere far below the
                        real cause.
                     2. Blade compiles directives found INSIDE a comment, before it
                        strips the comment. So an explanation that quotes the very
                        syntax it is describing injects live PHP into the page, which
                        then swallows the markup that follows it.

                     Assembling the sentence in one PHP block makes trap 1
                     impossible, and describing the traps without quoting them makes
                     trap 2 impossible. --}}
                <p class="ch-card-hint">
                    @php
                        $line = $assignments['actionable_count'].' '
                            .\Illuminate\Support\Str::plural('assignment', $assignments['actionable_count'])
                            .' to hand in';

                        if (($assignments['overdue_count'] ?? 0) > 0) {
                            $line .= ' — '.$assignments['overdue_count'].' overdue';
                        }
                    @endphp
                    {{ $line }}
                </p>

                <ul class="ch-list" data-testid="ch-assignments-actionable">
                    @foreach ($assignments['actionable'] as $row)
                        <li>
                            <a href="{{ $row['url'] }}">
                                <span class="ch-list-title">{{ $row['assignment']->title }}</span>
                                <span class="ch-list-meta">
                                    <span class="ch-chip {{ $row['state_chip'] }}">{{ $row['state_short'] }}</span>
                                    @if ($row['due'])
                                        <span class="ch-list-due">Due {{ $row['due']->format('j M') }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if (($assignments['actionable_count'] ?? 0) > count($assignments['actionable']))
                    <a class="ch-card-link" href="{{ route('student.courses.assignments.index', $offering->id) }}">
                        See all {{ $assignments['actionable_count'] }}
                    </a>
                @endif
            @else
                <p class="ch-card-hint">No assignments are waiting for you.</p>
            @endif

            @if (($assignments['done_count'] ?? 0) > 0)
                <details class="ch-more">
                    <summary>Submitted and returned ({{ $assignments['done_count'] }})</summary>
                    <ul class="ch-list">
                        @foreach ($assignments['done'] as $row)
                            <li>
                                <a href="{{ $row['url'] }}">
                                    <span class="ch-list-title">{{ $row['assignment']->title }}</span>
                                    <span class="ch-chip {{ $row['state_chip'] }}">{{ $row['state_short'] }}</span>
                                    @if ($row['marks'] !== null)
                                        {{-- The mark only ever appears when it has been
                                             RELEASED, and it is the same number the
                                             assignment page and the module completion
                                             rule read - one computation, three readers. --}}
                                        <span class="ch-list-mark">
                                            {{ rtrim(rtrim(number_format((float) $row['marks'], 2), '0'), '.') }}
                                            / {{ rtrim(rtrim(number_format((float) $row['max_marks'], 2), '0'), '.') }}
                                        </span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        {{-- ── 3. LIVE CLASSES ─────────────────────────────────────────── --}}
        <section class="ch-card ch-card-live" aria-labelledby="ch-card-live-h" data-testid="ch-card-live">
            <h2 class="ch-card-title" id="ch-card-live-h">Live Classes</h2>

            @php $nextClass = $liveClasses['next'] ?? null; @endphp

            @if ($nextClass)
                <p class="ch-card-hint">{{ $nextClass['when'] }}</p>
                <h3 class="ch-live-title">{{ $nextClass['title'] }}</h3>

                <p class="ch-live-meta">
                    <span class="ch-chip ch-chip-muted">{{ $nextClass['status_label'] }}</span>
                    @if ($nextClass['has_materials'])
                        <span class="ch-chip ch-chip-draft">Materials available</span>
                    @endif
                    @if ($nextClass['has_recording'])
                        <span class="ch-chip ch-chip-draft">Recording available</span>
                    @endif
                </p>

                <div class="ch-card-actions">
                    {{-- THE JOIN BUTTON IS A QUESTION TO LiveClassAccessService,
                         NOT TO A CLOCK. `canStudentJoin` already encodes
                         publication, cancellation, the join window, whether the
                         platform is enabled, and the platform's own free-tier
                         limit. Re-deriving any of that here would be a second
                         implementation of a rule that already exists.

                         No meeting URL is built anywhere in this feature: the link
                         is the EXISTING `student.live_classes.join` route, so the
                         current Jitsi/provider behaviour is untouched and an
                         institutional Google Meet integration can be added behind
                         that route without anything here being undone. --}}
                    @if ($nextClass['joinable'])
                        <a class="btn btn-primary btn-sm" href="{{ $nextClass['join_url'] }}"
                           data-testid="ch-live-join">Join</a>
                    @endif

                    <a class="btn btn-outline-secondary btn-sm" href="{{ $nextClass['show_url'] }}">
                        Details
                    </a>
                </div>
            @else
                <p class="ch-card-hint">No Live Class is scheduled for this course.</p>
            @endif

            @if (($liveClasses['past_count'] ?? 0) > 0)
                <details class="ch-more">
                    <summary>Completed ({{ $liveClasses['past_count'] }})</summary>
                    <ul class="ch-list">
                        @foreach ($liveClasses['past'] as $row)
                            <li>
                                <a href="{{ $row['show_url'] }}">
                                    <span class="ch-list-title">{{ $row['title'] }}</span>
                                    <span class="ch-list-due">{{ $row['date'] }}</span>
                                    <span class="ch-chip ch-chip-muted">{{ $row['status_label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <a class="ch-card-link" href="{{ route('student.live_classes.index') }}">All Live Classes</a>
        </section>

        {{-- ── 4. COURSE RESOURCES ─────────────────────────────────────── --}}
        <section class="ch-card ch-card-resources" aria-labelledby="ch-card-resources-h" data-testid="ch-card-resources">
            <h2 class="ch-card-title" id="ch-card-resources-h">Course Resources</h2>

            @if (($resources['count'] ?? 0) > 0)
                <p class="ch-card-hint">
                    {{ $resources['count'] }}
                    {{ \Illuminate\Support\Str::plural('resource', $resources['count']) }}
                    from published lessons
                </p>

                <ul class="ch-list" data-testid="ch-resources-list">
                    @foreach ($resources['items'] as $row)
                        <li>
                            <a href="{{ $row['url'] }}">
                                <span class="ch-chip ch-chip-muted">{{ $row['kind_label'] }}</span>
                                <span class="ch-list-title">{{ $row['name'] }}</span>
                                <span class="ch-list-due">{{ $row['lesson_title'] }}</span>
                                @if ($row['size'])
                                    <span class="ch-list-due">{{ $row['size'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                {{-- An empty state that says WHY it is empty. "Nothing here" reads
                     as though the lecturer forgot; "no lesson has published a file
                     or a link yet" names the actual condition. --}}
                <p class="ch-card-hint">
                    No lesson in this course has published a file or a link yet.
                </p>
            @endif
        </section>
    </div>

    {{-- A readable summary of the same facts, for anyone using a screen reader to
         navigate by headings and who does not want to read four cards. Not
         additional information: the same numbers, stated once in a list. --}}
    <section class="ch-summary" aria-labelledby="ch-summary-h" data-testid="ch-summary">
        <h2 class="ch-card-title" id="ch-summary-h">At a glance</h2>
        <ul class="ch-summary-list">
            <li>{{ $progress['lessons_completed'] ?? 0 }} of {{ $progress['lessons_total'] ?? 0 }} lessons completed</li>
            <li>{{ $progress['assessments_completed'] ?? 0 }} of {{ $progress['assessments_total'] ?? 0 }} required assessments completed</li>
            <li>{{ $progress['modules_complete'] ?? 0 }} of {{ $progress['modules_total'] ?? 0 }} modules complete</li>
            <li>{{ $assignments['actionable_count'] ?? 0 }} assignments to hand in, {{ $assignments['overdue_count'] ?? 0 }} overdue</li>
            <li>{{ $liveClasses['upcoming_count'] ?? 0 }} upcoming Live Classes, {{ $liveClasses['past_count'] ?? 0 }} completed</li>
            <li>{{ $resources['count'] ?? 0 }} published course resources</li>
        </ul>
    </section>
</div>

<style>
    /* ── Built for metered connections and small screens ────────────────
       No web fonts, no background images, nothing that must download before
       text appears. Every size is relative so the page scales with the
       reader's own text-size setting, and the cards stack on a phone. */
    .ch-home { color: #1f2933; }
    .ch-eyebrow { font-size: .74rem; text-transform: uppercase; letter-spacing: .08em; color: #52606d; margin: 0 0 .25rem; font-weight: 700; }
    .ch-title { font-size: 1.5rem; font-weight: 600; margin: 0 0 .75rem; line-height: 1.25; }
    .ch-muted { color: #7b8794; }

    .ch-header {
        display: flex; gap: 1.25rem; align-items: stretch;
        background: #fff; border: 1px solid #d7dee5; border-radius: 10px;
        padding: 1.1rem; margin-bottom: 1rem; flex-wrap: wrap;
    }

    .ch-header-cover { flex: 0 0 190px; }
    .ch-cover-image { width: 100%; height: 118px; object-fit: cover; border-radius: 8px; display: block; }
    .ch-cover-fallback {
        width: 100%; height: 118px; border-radius: 8px;
        background: linear-gradient(135deg, #1a5490, #2b6cb0);
        color: #fff; display: flex; align-items: center; justify-content: center;
        padding: .5rem; text-align: center;
    }
    .ch-cover-code { font-size: 1.05rem; font-weight: 700; letter-spacing: .02em; word-break: break-word; }

    .ch-header-body { flex: 1 1 320px; min-width: 0; }

    .ch-facts { display: flex; flex-wrap: wrap; gap: .35rem 1.5rem; margin: 0 0 .9rem; }
    .ch-fact { margin: 0; min-width: 7.5rem; }
    .ch-fact-wide { flex: 1 1 100%; }
    .ch-fact dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #7b8794; font-weight: 700; }
    .ch-fact dd { margin: 0; font-size: .92rem; }

    .ch-person { display: inline-flex; flex-direction: column; margin-right: 1.4rem; margin-bottom: .2rem; }
    .ch-person-name { font-weight: 600; }
    .ch-person-role { font-size: .76rem; color: #7b8794; }
    /* The state is a WORD, not only a colour - the same rule every other state in
       this product follows, for the same reason. */
    .ch-person-state { font-size: .74rem; color: #1a5490; }

    .ch-progress { display: flex; gap: 1rem; flex-wrap: wrap; }
    .ch-progress-item { flex: 1 1 10rem; min-width: 9rem; }
    .ch-progress-head { display: flex; justify-content: space-between; gap: .5rem; font-size: .78rem; margin-bottom: .25rem; }
    .ch-progress-label { color: #52606d; font-weight: 600; }
    .ch-progress-count { color: #1f2933; font-variant-numeric: tabular-nums; }
    .ch-progress-track { height: .45rem; background: #e9ecef; border-radius: 999px; overflow: hidden; }
    .ch-progress-fill { height: 100%; background: #1a5490; }

    .ch-nav { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .ch-nav-item {
        display: inline-flex; align-items: baseline; gap: .4rem;
        padding: .45rem .8rem; border: 1px solid #d7dee5; border-radius: 999px;
        background: #fff; color: #1f2933; text-decoration: none; font-size: .88rem;
    }
    .ch-nav-item:hover { border-color: #1a5490; color: #1a5490; }
    .ch-nav-item.is-current { background: #1a5490; border-color: #1a5490; color: #fff; }
    .ch-nav-count { font-size: .74rem; background: rgba(26, 84, 144, .12); border-radius: 999px; padding: 0 .4rem; }
    .ch-nav-item.is-current .ch-nav-count { background: rgba(255, 255, 255, .25); }
    /* An unavailable area is visibly unavailable, and is not a link at all. */
    .ch-nav-item.is-unavailable { background: #f8f9fa; color: #7b8794; border-style: dashed; cursor: default; }
    .ch-nav-note { font-size: .72rem; }

    .ch-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; align-items: start; }
    .ch-card { background: #fff; border: 1px solid #d7dee5; border-radius: 10px; padding: 1rem; }
    .ch-card-title { font-size: 1rem; font-weight: 700; margin: 0 0 .55rem; }
    .ch-card-hint { font-size: .84rem; color: #52606d; margin: 0 0 .7rem; }

    .ch-next { display: block; text-decoration: none; color: inherit; border: 1px solid #d7dee5; border-radius: 8px; padding: .7rem; }
    .ch-next:hover { border-color: #1a5490; }
    .ch-next-kind { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #7b8794; font-weight: 700; }
    .ch-next-title { display: block; font-weight: 600; margin: .15rem 0; }
    .ch-next-module { display: block; font-size: .8rem; color: #52606d; }
    .ch-next-meta { display: flex; gap: .3rem; flex-wrap: wrap; margin-top: .5rem; }

    .ch-list { list-style: none; padding: 0; margin: 0; }
    .ch-list li { border-bottom: 1px solid #eef1f4; padding: .45rem 0; }
    .ch-list li:last-child { border-bottom: 0; }
    .ch-list a { display: flex; gap: .45rem; align-items: baseline; flex-wrap: wrap; text-decoration: none; color: inherit; }
    .ch-list a:hover .ch-list-title { color: #1a5490; text-decoration: underline; }
    .ch-list-kind, .ch-list-due { font-size: .76rem; color: #7b8794; }
    .ch-list-title { font-weight: 600; }
    .ch-list-mark { font-size: .8rem; font-variant-numeric: tabular-nums; color: #1a5490; font-weight: 600; }

    .ch-more { margin-top: .7rem; }
    .ch-more summary { cursor: pointer; font-size: .82rem; color: #52606d; }

    .ch-live-title { font-size: .95rem; font-weight: 600; margin: 0 0 .35rem; }
    .ch-live-meta { display: flex; gap: .3rem; flex-wrap: wrap; margin: 0 0 .6rem; }
    .ch-card-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    .ch-card-link { display: inline-block; margin-top: .7rem; font-size: .84rem; }

    .ch-summary { background: #f8f9fa; border: 1px solid #d7dee5; border-radius: 10px; padding: 1rem; margin-top: 1rem; }
    .ch-summary-list { margin: 0; padding-left: 1.1rem; font-size: .86rem; color: #52606d; }
    .ch-summary-list li { margin-bottom: .25rem; }

    /* The chip vocabulary the assignment surfaces already use, so a state looks
       the same wherever a student meets it. */
    .ch-chip { display: inline-flex; align-items: center; font-size: .72rem; font-weight: 700; padding: .12em .5em; border-radius: 999px; border: 1px solid transparent; }
    .ch-chip-muted      { background: #f1f3f5; color: #495057; border-color: #dee2e6; }
    .ch-chip-draft      { background: #fff3cd; color: #7a5b00; border-color: #ffe69c; }
    .ch-chip-progress   { background: #fff3cd; color: #7a5b00; border-color: #ffe69c; }
    .ch-chip-open       { background: #d1e7dd; color: #0f5132; border-color: #a3cfbb; }
    .ch-chip-missing    { background: #f8d7da; color: #842029; border-color: #f1aeb5; }
    .ch-chip-returned   { background: #cfe2ff; color: #084298; border-color: #b6d4fe; }

    @media (max-width: 575.98px) {
        .ch-header-cover { flex: 1 1 100%; }
        .ch-cover-image, .ch-cover-fallback { height: 100px; }
    }
</style>

@endsection
