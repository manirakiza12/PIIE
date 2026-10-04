{{-- Shared styling for the lecturer authoring surface, the marking workspace and
     the student reader.

     ONE stylesheet for all three, deliberately. A lecturer writes a heading, a
     numbered requirement and a worked example; the reader must see exactly that.
     Two stylesheets would drift, and the lecturer would discover the drift by
     publishing and then re-editing.

     MOBILE AND LOW DATA
     Built for metered connections: no web fonts, no background images, nothing
     that must download before text appears. A table scrolls inside its own
     container rather than forcing the page wide, and every size is relative so
     the surface scales with the device's own text-size setting. --}}
<style>
    .as-surface { --as-ink: #1f2933; --as-muted: #52606d; --as-line: #d7dee5; --as-accent: #1a5490;
                  background: #fff; color: var(--as-ink); line-height: 1.65; }
    .as-surface h1, .as-surface h2, .as-surface h3, .as-surface h4, .as-surface h5 { color: var(--as-ink); font-weight: 600; line-height: 1.3; margin: 1.3em 0 .5em; }
    .as-surface h2 { font-size: 1.3rem; } .as-surface h3 { font-size: 1.12rem; } .as-surface h4, .as-surface h5 { font-size: 1rem; }
    .as-surface p { margin: 0 0 1em; }
    .as-surface ul, .as-surface ol { margin: 0 0 1em; padding-left: 1.5em; }
    .as-surface li { margin-bottom: .35em; }
    .as-surface blockquote { margin: 0 0 1em; padding: .6em 1em; border-left: 4px solid var(--as-line); color: var(--as-muted); background: #f7f9fb; }
    .as-surface blockquote p:last-child { margin-bottom: 0; }
    .as-surface img { max-width: 100%; height: auto; border-radius: 6px; }
    .as-surface a { color: var(--as-accent); }
    .as-surface code, .as-surface pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95em; }
    .as-surface pre { background: #f4f6f8; padding: .8em 1em; border-radius: 6px; overflow-x: auto; }
    .as-surface sup, .as-surface sub { font-size: .75em; line-height: 0; }
    .as-surface hr { border: 0; border-top: 1px solid var(--as-line); margin: 1.5em 0; }

    .as-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 0 1em; }
    .as-surface table { width: 100%; border-collapse: collapse; margin: 0; font-size: .95rem; }
    .as-surface th, .as-surface td { border: 1px solid var(--as-line); padding: .5em .7em; text-align: left; vertical-align: top; }
    .as-surface th { background: #f4f6f8; font-weight: 600; }

    /* ── State chips ──────────────────────────────────────────────────────
       Every state is a WORD as well as a colour. A chip that is only a colour
       excludes a colour-blind reader and a screen reader equally, and a marking
       list where "Submitted" and "Late" look alike is worse than no list. */
    .as-chip { display: inline-flex; align-items: center; gap: .35em; font-size: .78rem;
               font-weight: 600; padding: .2em .6em; border-radius: 999px; border: 1px solid transparent; }
    .as-chip-draft      { background: #f1f3f5; color: #495057; border-color: #dee2e6; }
    .as-chip-scheduled  { background: #fff3cd; color: #7a5b00; border-color: #ffe69c; }
    .as-chip-published  { background: #d1e7dd; color: #0f5132; border-color: #a3cfbb; }
    .as-chip-closed     { background: #e2e3e5; color: #383d41; border-color: #ced4da; }
    .as-chip-open       { background: #d1e7dd; color: #0f5132; border-color: #a3cfbb; }
    .as-chip-progress   { background: #fff3cd; color: #7a5b00; border-color: #ffe69c; }
    .as-chip-late       { background: #f8d7da; color: #842029; border-color: #f1aeb5; }
    .as-chip-missing    { background: #f8d7da; color: #842029; border-color: #f1aeb5; }
    .as-chip-returned   { background: #cfe2ff; color: #084298; border-color: #b6d4fe; }
    .as-chip-muted      { background: #f1f3f5; color: #495057; border-color: #dee2e6; }

    /* A visible mark, not only a hue, for each state dot. */
    .as-dot { width: .6rem; height: .6rem; border-radius: 50%; flex: 0 0 auto; display: inline-block; }
    .as-dot-draft { background: #868e96; }
    .as-dot-scheduled { background: #f0b429; }
    .as-dot-published, .as-dot-open { background: #2f9e44; }
    .as-dot-closed, .as-dot-returned { background: #1971c2; }
    .as-dot-late, .as-dot-missing { background: #c92a2a; }
    .as-dot-progress { background: #f0b429; }
    .as-dot-muted { background: #adb5bd; }

    /* ── The editor frame ─────────────────────────────────────────────────
       A roomy, fixed-height editing area. Substantial authoring in a cramped
       one-line box is the main reason people abandon a content builder. */
    .as-editor-shell { border: 1px solid var(--as-line); border-radius: 8px; overflow: hidden; background: #fff; }
    .as-editor-shell .note-editor { min-height: 380px; max-height: 68vh; overflow-y: auto; }
    .as-editor-shell .note-editable { min-height: 380px; padding: 1rem 1.15rem; }
    .as-editor-shell .note-resizebar { display: none; }
    .as-editor-shell .note-container { margin: 0; }
    .as-editor-shell .note-dropdown { z-index: 1080; }
    @media (max-width: 767.98px) {
        /* Phones: shorter, so the action bar stays reachable without a long
           scroll past the toolbar. */
        .as-editor-shell .note-editable, .as-editor-shell .note-editor { min-height: 240px; max-height: 50vh; }
    }

    /* ── The submission panel ─────────────────────────────────────────────
       A student's own work, and the two clearly separated acts of preparing it
       and handing it in. They are visually distinct ON PURPOSE: "saved" and
       "submitted" are different claims and must not look interchangeable. */
    .as-submit-panel { border: 1px solid var(--as-line); border-radius: 8px; }
    .as-submit-panel .as-draft-zone { background: #f8f9fa; border-bottom: 1px dashed var(--as-line); }
    .as-draft-note { font-size: .8rem; color: var(--as-muted); }

    /* A submitted-but-ungraded notice, so a student is never left wondering
       whether their work arrived. */
    .as-received { border-left: 4px solid #2f9e44; background: #f1fbf4; }

    /* A returned result, so the mark is the most prominent thing on the page. */
    .as-result { border-left: 4px solid #1971c2; background: #f0f6ff; }
    .as-result .as-mark { font-size: 1.6rem; font-weight: 700; line-height: 1; }

    @media print {
        .as-editor-shell .note-toolbar { display: none !important; }
    }
</style>
