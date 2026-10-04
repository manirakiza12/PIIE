{{--
    ===========================================================================
    IDENTITY STATEMENTS — Vision, Mission, Motto and Core Values.
    ===========================================================================

    WHY THIS IS A DEDICATED BLOCK
        The brief is right that these were being buried. They arrived as one long
        prose section, which made an institutional Vision indistinguishable from a
        paragraph of marketing copy — and a Vision is the single most important
        sentence an institution publishes.

    ── THE WORDING IS NOT REWRITTEN ───────────────────────────────────────────
        Every character after the "Vision:" / "Mission:" / "Motto:" label is
        rendered EXACTLY as the CMS stores it. The block below only:

          * splits the stored text on those labels,
          * promotes each to a heading and a larger measure,
          * renders the remainder as `$statementText`.

        It does not summarise, tidy, shorten or re-order. If Super Admin edits the
        wording in the CMS, this block changes with it and nothing else has to.
        That is asserted by `PublicSiteRedesignTest`, which publishes deliberately
        odd wording and requires it back on the page character-for-character.

    ── WHERE THE MOTTO COMES FROM ─────────────────────────────────────────────
        Two approved sources, and they are the same sentence today:
          - the `vision_mission_motto` section's own "Motto:" line, and
          - the `motto` website setting ("Strive. Excel. Lead.").
        The section's line is preferred because it is the Vision/Mission/Motto
        statement itself; the setting is the fallback for an institution whose CMS
        has the setting but no labelled line. The fallback is never invented — with
        neither present, no motto card renders at all.

    ── FALLBACKS, STATED ─────────────────────────────────────────────────────
        Any one of Vision / Mission / Motto may be absent from the CMS. A missing one
        omits its card rather than showing an empty heading. Nothing is invented to
        fill the gap.
--}}

@php
    use App\Support\Website\PublicContactChannels;

    /**
     * Split the stored statement text into labelled parts.
     *
     * Only `Vision:`, `Mission:` and `Motto:` at the START of a line are treated as
     * labels. A colon anywhere else in the prose is left alone, so a Mission that
     * itself contains "Vision: ..." mid-sentence is not truncated.
     *
     * Anything before the first recognised label is kept as `intro` and shown above
     * the cards, rather than being discarded.
     */
    $piieStatements = ['intro' => '', 'vision' => '', 'mission' => '', 'motto' => ''];

    foreach (preg_split("/\r?\n/", (string) ($statementContent ?? '')) ?: [] as $piieLine) {
        $piieLine = trim($piieLine);

        if ($piieLine === '') {
            continue;
        }

        if (preg_match('/^(vision|mission|motto)\s*:\s*(.+)$/i', $piieLine, $piieMatch)) {
            $piieKey = strtolower($piieMatch[1]);

            // First occurrence wins; a duplicate label would otherwise overwrite the
            // approved statement with a later one.
            if ($piieStatements[$piieKey] === '') {
                $piieStatements[$piieKey] = trim($piieMatch[2]);
            }

            continue;
        }

        if ($piieStatements['intro'] === '' && $piieStatements['vision'] === ''
            && $piieStatements['mission'] === '' && $piieStatements['motto'] === '') {
            $piieStatements['intro'] = $piieLine;
        }
    }

    // The `motto` setting as a fallback, and as the display casing source.
    $piieMottoSetting = trim((string) (($websiteSettings ?? collect())['motto'] ?? ''));

    if ($piieStatements['motto'] === '' && $piieMottoSetting !== '') {
        $piieStatements['motto'] = $piieMottoSetting;
    }

    $piieHasAny = $piieStatements['vision'] !== ''
        || $piieStatements['mission'] !== ''
        || $piieStatements['motto'] !== '';
@endphp

@if($piieHasAny || ! empty($valuesContent))
    <section class="piie-section piie-identity"
             id="identity"
             aria-labelledby="piie-identity-title">

        <div class="piie-wrap">
            <div class="piie-head-center">
                <span class="piie-eyebrow">Who we are</span>

                <h2 id="piie-identity-title">
                    {{ $statementTitle ?? 'Vision, Mission and Motto' }}
                </h2>

                @if(! empty($statementSubtitle))
                    <p class="piie-lede">{{ $statementSubtitle }}</p>
                @endif

                @if($piieStatements['intro'] !== '')
                    <p class="piie-lede">{{ $piieStatements['intro'] }}</p>
                @endif
            </div>

            {{-- ── THE MOTTO, given its own full-width treatment ─────────────────
                 It is three words and it is the most memorable thing the Institute
                 publishes, so it is set large, letter-spaced and on the brand colour
                 rather than being a third card the same size as the other two. The
                 words are the CMS's, unmodified; only the typography is ours. --}}
            @if($piieStatements['motto'] !== '')
                <div class="piie-motto-band" data-testid="identity-motto">
                    <span class="piie-motto-band__label">Motto</span>
                    <p class="piie-motto-band__text">{{ $piieStatements['motto'] }}</p>
                </div>
            @endif

            @if($piieStatements['vision'] !== '' || $piieStatements['mission'] !== '')
                <div class="piie-grid piie-grid--2 piie-identity__grid">
                    @if($piieStatements['vision'] !== '')
                        <article class="piie-statement" data-testid="identity-vision">
                            <h3 class="piie-statement__label">Vision</h3>
                            <p class="piie-statement__text">{{ $piieStatements['vision'] }}</p>
                        </article>
                    @endif

                    @if($piieStatements['mission'] !== '')
                        <article class="piie-statement" data-testid="identity-mission">
                            <h3 class="piie-statement__label">Mission</h3>
                            <p class="piie-statement__text">{{ $piieStatements['mission'] }}</p>
                        </article>
                    @endif
                </div>
            @endif

            {{-- ── CORE VALUES ───────────────────────────────────────────────────
                 The approved sentence, rendered as a statement rather than as body
                 copy inside a longer section. It is NOT split into chips: doing so
                 would mean rewriting the connective tissue of an approved sentence,
                 and the brief forbids that without approval. --}}
            @if(! empty($valuesContent))
                <article class="piie-statement piie-statement--values" data-testid="identity-values">
                    <h3 class="piie-statement__label">Core Values</h3>
                    <p class="piie-statement__text">{{ $valuesContent }}</p>
                </article>
            @endif
        </div>
    </section>
@endif
