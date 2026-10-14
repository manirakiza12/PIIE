{{--
    THE PASS POPUP.

    RENDERED ONLY WHEN `$celebration` IS NOT NULL.

    That is not a style choice, it is the safety property the specification demands.
    `PassCelebration::for()` returns null for every case that must not be
    celebrated - not passed, marked but not returned, still resting, no pass mark at
    all - so a student who has NOT passed cannot reach this markup by any path. A
    view that guarded the popup with its own `@if` would be one variable-name slip
    away from congratulating a failure, and a student congratulated on a failure has
    been told the one thing they most need to be true.

    WHY A DISMISSIBLE PANEL, NOT A MODAL
    A modal covers the mark and the feedback the student came to read, and traps
    keyboard focus on a page whose whole purpose is to be read carefully. The
    celebration should announce itself and then get out of the way. It is marked up
    with `role="status"` so a screen reader announces it when it appears, and it
    does not steal focus.
--}}
@if($celebration)
    <div class="alert alert-success as-celebration d-flex align-items-start gap-2"
         role="status"
         data-testid="as-pass-popup"
         style="border-left: 4px solid var(--bs-success);">
        <span aria-hidden="true" class="fs-5 lh-1">&check;</span>

        <div class="flex-grow-1">
            <h6 class="mb-1">Well done &mdash; you have passed</h6>

            <p class="mb-0" data-testid="as-pass-popup-message">
                {{ $celebration->line() }}

                @if($celebration->qualifiedForNextTopic())
                    <span class="d-block mt-1">
                        You have qualified for the next topic, <strong>{{ $celebration->nextTopicTitle() }}</strong>.
                    </span>
                @elseif($celebration->nextTopicTitle() !== null)
                    {{-- The next topic EXISTS but is not open yet. Saying so, and
                         why, beats silence: a passed student left wondering why the
                         next button is grey is worse off than one who was never
                         promised it. --}}
                    <span class="d-block mt-1 text-muted">
                        Finish what is left of this topic and the next one opens.
                    </span>
                @endif
            </p>
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                data-testid="as-pass-popup-dismiss"
                aria-label="Dismiss"></button>
    </div>
@endif