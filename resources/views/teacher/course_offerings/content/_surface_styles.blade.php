{{-- Shared styling for the lesson AUTHORING surface and the STUDENT reader.

     One stylesheet for both, deliberately. A lecturer writes a heading, a table
     and a quote; the reader must see the same heading, table and quote. Two
     stylesheets would drift, and the lecturer would discover the drift by
     publishing and then re-editing.

     MOBILE AND LOW DATA
     The reader is built for a metered connection, so: no web fonts, no
     background images, nothing that must download before text appears. Tables
     scroll inside their own container instead of forcing the whole page wide,
     images are capped and lazy, and every font size is relative to the reader's
     own base so the whole thing scales with the device's text-size setting
     rather than fighting it. --}}
<style>
    .cc-surface { --cc-ink: #1f2933; --cc-muted: #52606d; --cc-line: #d7dee5; --cc-accent: #1a5490; background: #fff; color: var(--cc-ink); line-height: 1.65; }
    .cc-surface h1, .cc-surface h2, .cc-surface h3, .cc-surface h4, .cc-surface h5, .cc-surface h6 { color: var(--cc-ink); font-weight: 600; line-height: 1.3; margin: 1.4em 0 .5em; }
    .cc-surface h1 { font-size: 1.6rem; } .cc-surface h2 { font-size: 1.35rem; } .cc-surface h3 { font-size: 1.15rem; }
    .cc-surface h4, .cc-surface h5, .cc-surface h6 { font-size: 1rem; }
    .cc-surface p { margin: 0 0 1em; }
    .cc-surface ul, .cc-surface ol { margin: 0 0 1em; padding-left: 1.5em; }
    .cc-surface li { margin-bottom: .35em; }
    .cc-surface blockquote { margin: 0 0 1em; padding: .6em 1em; border-left: 4px solid var(--cc-line); color: var(--cc-muted); background: #f7f9fb; }
    .cc-surface blockquote p:last-child { margin-bottom: 0; }
    .cc-surface img { max-width: 100%; height: auto; border-radius: 6px; }
    .cc-surface a { color: var(--cc-accent); }
    .cc-surface code, .cc-surface pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95em; }
    .cc-surface pre { background: #f4f6f8; padding: .8em 1em; border-radius: 6px; overflow-x: auto; }

    /* A table must never widen the page on a phone. It scrolls inside itself. */
    .cc-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 0 1em; }
    .cc-surface table { width: 100%; border-collapse: collapse; margin: 0; font-size: .95rem; }
    .cc-surface th, .cc-surface td { border: 1px solid var(--cc-line); padding: .5em .7em; text-align: left; vertical-align: top; }
    .cc-surface th { background: #f4f6f8; font-weight: 600; }

    /* sup/sub are real content in a maths-flavoured unit; keep them legible
       rather than letting the default shrink them into the line. */
    .cc-surface sup, .cc-surface sub { font-size: .75em; line-height: 0; }

    /* Reserved equation notation. PIIE stores the LaTeX but does not render it
       yet, so the fallback text stays readable and nothing is executed. */
    .cc-equation { font-family: ui-serif, Georgia, serif; font-style: italic; white-space: nowrap; }

    .cc-surface hr { border: 0; border-top: 1px solid var(--cc-line); margin: 1.5em 0; }

    /* ── The editor frame ──────────────────────────────────────────────
       A roomy, fixed-height editing area. Substantial authoring in a cramped
       one-line box is the main reason people abandon a content builder. */
    .cc-editor-shell { border: 1px solid var(--cc-line); border-radius: 8px; overflow: hidden; background: #fff; }
    .cc-editor-shell .note-editor { min-height: 460px; max-height: 70vh; overflow-y: auto; }
    .cc-editor-shell .note-editable { min-height: 460px; padding: 1rem 1.15rem; }
    @media (max-width: 767.98px) {
        /* Phones: shorter, so the Save/Preview/Publish bar stays reachable
           without a long scroll past the toolbar. */
        .cc-editor-shell .note-editable { min-height: 300px; }
        .cc-editor-shell .note-editor { min-height: 300px; max-height: 55vh; }
    }
    @media print {
        .cc-editor-shell .note-toolbar { display: none !important; }
    }
</style>
