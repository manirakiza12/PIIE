# Shared website typography

The public website and all system portals use Nunito for text, headings, navigation, controls, tables and editor content. Regular (400), semibold (600) and bold (700) files already ship in `public/assets/css/fonts/`; `typography.css` loads them locally with `font-display: swap`. Arial/sans-serif provide temporary or missing-glyph fallbacks.

`shared.typography` is loaded through the shared workspace assets across every role layout. Standalone login, applicant authentication, payment status, card verification, installer and error pages also load the typography partial. Local assets need no database lookup on error pages.

The public heading/body design tokens and applicant font declaration use the same family. Old Google text-font imports were removed from frontend/admin styles and the corresponding Sass sources. Icon fonts remain independent so Bootstrap, Font Awesome and other icon glyphs are preserved. PDF documents and external email clients retain their existing embedded/compatible document typography; this change standardises browser screens.

Validation: `node scripts/typography-browser-verify.mjs` passed 22 representative portal/viewport checks (390 and 1440 px), loading the bundled font weights and verifying text family/icon preservation in real Chrome. Screenshots and a report are in `docs/ui-review/typography/`. The 106 related Laravel tests passed (538 assertions), covering public programmes, applicant pages, error rendering and invoice billing/email. No production deployment was performed.
