{{--
    Create / edit an academic Programme.

    STAGE 1 ADDITIONS, and the reasoning that shaped them:

    1. TUITION IS NOW THREE FIELDS, NOT ONE.
       The platform already carried `intake_sessions.application_fee` (per
       INTAKE) and an invoiced per-STUDENT balance, so a bare "Tuition Fee"
       number sat next to two other amounts with entirely different meanings and
       nothing said which it was. Amount, currency and basis are separate inputs
       now. The basis has an honest "Contact us" option, because "enquire" is a
       real answer and forcing an invented period would be worse than none.

    2. A MISSING PRICE IS A FIRST-CLASS STATE.
       The form says so under the amount, and the admin summary line states what
       the website will actually render. An administrator must never be able to
       save a number and believe the site is showing it when the site is
       withholding it for want of a basis.

    3. COVER IMAGE IS ITS OWN FORM.
       A file upload cannot share a form with the field edits it would otherwise
       be attached to, because an unrelated save of a programme's name must never
       re-run the upload pipeline or delete a photograph. Uploading and removing
       are separate POSTs; the surrounding form is unchanged in behaviour.

    4. WEBSITE PUBLICATION IS SEPARATE FROM ACADEMIC ACTIVATION.
       `is_active` decides whether the programme is OFFERED — the applicant
       portal and eligibility depend on it. `is_published` decides whether it is
       ADVERTISED. A programme can be taught and not yet marketed. Conflating
       them would make marketing a precondition of teaching.
--}}
@php
    $piieHasProgramme = (bool) ($programme ?? null);

    // The `currency` table is a global payment-gateway lookup, not part of the
    // academic schema, so it may legitimately be absent in a partially migrated
    // or partially migrated-for-tests installation. A missing table must not
    // take the whole programme modal down: the currency field degrades to a free
    // text input rather than disappearing with it.
    $piieHasCurrencyTable = \Illuminate\Support\Facades\Schema::hasTable('currency');
    $piieCurrencies = $piieHasCurrencyTable
        ? \App\Models\Currency::orderBy('code')->pluck('code', 'code')->filter()
        : collect();
@endphp

<div class="eoff-form">
    <form method="POST" id="piie-programme-edit" enctype="multipart/form-data" class="d-block ajaxForm"
          action="{{ $programme ? route('admin.programmes.update', $programme->id) : route('admin.programmes.store') }}">
        @csrf
        <div class="form-row">
            <div class="row">
                <div class="col-6 fpb-7">
                    <label class="eForm-label" for="piie-code">{{ get_phrase('Programme Code') }} *</label>
                    <input type="text" id="piie-code" class="form-control eForm-control" name="code"
                           value="{{ old('code', $programme->code ?? '') }}" placeholder="e.g. BSC-CS" required maxlength="20">
                </div>
                <div class="col-6 fpb-7">
                    <label class="eForm-label" for="piie-level">{{ get_phrase('Level') }} *</label>
                    <select id="piie-level" class="form-control eForm-control" name="level" required>
                        @foreach(\App\Models\Programme::LEVELS as $lvl)
                            <option value="{{ $lvl }}" {{ (old('level', $programme->level ?? '') == $lvl) ? 'selected' : '' }}>{{ $lvl }}</option>
                        @endforeach
                        {{-- Legacy values: kept selectable only so a programme already
                             using one doesn't lose data on its next edit; not offered
                             as a first choice for a brand-new programme. --}}
                        @foreach(\App\Models\Programme::LEVELS_LEGACY as $lvl)
                            <option value="{{ $lvl }}" {{ (old('level', $programme->level ?? '') == $lvl) ? 'selected' : '' }}>{{ $lvl }} ({{ get_phrase('legacy') }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="fpb-7 mt-2">
                <label class="eForm-label" for="piie-name">{{ get_phrase('Programme Name') }} *</label>
                <input type="text" id="piie-name" class="form-control eForm-control" name="name"
                       value="{{ old('name', $programme->name ?? '') }}" placeholder="e.g. Bachelor of Science in Computer Science" required>
            </div>

            <div class="row mt-2">
                <div class="col-6 fpb-7">
                    <label class="eForm-label" for="piie-mode">{{ get_phrase('Mode') }}</label>
                    <select id="piie-mode" class="form-control eForm-control" name="mode">
                        @foreach(\App\Models\Programme::MODES as $m)
                            <option value="{{ $m }}" {{ (old('mode', $programme->mode ?? '') == $m) ? 'selected' : '' }}>{{ $m }}</option>
                        @endforeach
                        @foreach(\App\Models\Programme::MODES_LEGACY as $m)
                            <option value="{{ $m }}" {{ (old('mode', $programme->mode ?? '') == $m) ? 'selected' : '' }}>{{ ucfirst($m) }} ({{ get_phrase('legacy') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 fpb-7">
                    <label class="eForm-label" for="piie-duration">{{ get_phrase('Duration') }}</label>
                    <input type="text" id="piie-duration" class="form-control eForm-control" name="duration"
                           value="{{ old('duration', $programme->duration ?? '') }}" placeholder="e.g. 3 years">
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-12 fpb-7">
                    <label class="eForm-label" for="piie-dept">{{ get_phrase('Faculty / Department') }}</label>
                    <select id="piie-dept" class="form-control eForm-control" name="department_id">
                        <option value="">{{ get_phrase('— None —') }}</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}" {{ (string) old('department_id', $programme->department_id ?? '') === (string) $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                    @if($departments->isEmpty())
                        <small class="text-muted d-block mt-1">
                            {{ get_phrase('No faculties set up yet.') }}
                            <a href="{{ route('admin.department_list') }}" target="_blank" rel="noopener">{{ get_phrase('Add one') }}</a>
                        </small>
                    @endif
                    <small class="text-muted d-block mt-1">
                        {{ get_phrase('The faculty also chooses the colour of the branded fallback panel used when a programme has no cover image.') }}
                    </small>
                </div>
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 TUITION: AMOUNT + CURRENCY + BASIS
            ══════════════════════════════════════════════════════════ --}}
            <fieldset class="mt-3 pt-3" style="border:1px solid var(--piie-line,#e6e9ef);border-radius:8px;padding:1rem;">
                <legend class="h6" style="font-size:.95rem;margin-bottom:.15rem;">
                    {{ get_phrase('Tuition and fees') }}
                </legend>
                <p class="text-muted" style="font-size:.8125rem;line-height:1.5;">
                    {{ get_phrase('The amount alone does not say what it is. State the amount, the currency and what the amount covers, so the website can print it honestly. This does not affect invoices, balances or payments.') }}
                </p>

                <div class="row">
                    <div class="col-12 col-md-4 fpb-7">
                        <label class="eForm-label" for="piie-fee">{{ get_phrase('Tuition Amount') }}</label>
                        <input type="number" id="piie-fee" class="form-control eForm-control" name="tuition_fee"
                               value="{{ old('tuition_fee', $programme->tuition_fee ?? '') }}"
                               min="0" step="0.01" placeholder="{{ get_phrase('Leave blank if not published') }}">
                    </div>

                    <div class="col-12 col-md-4 fpb-7">
                        <label class="eForm-label" for="piie-currency">{{ get_phrase('Currency') }}</label>
                        <select id="piie-currency" class="form-control eForm-control" name="tuition_currency">
                            <option value="">
                                {{ get_phrase('— Use institution currency') }}
                                @if($tenantCurrency) ({{ $tenantCurrency }}) @endif
                            </option>
                            @if($piieHasCurrencyTable && $piieCurrencies->isNotEmpty())
                                @foreach($piieCurrencies as $piieCode)
                                    <option value="{{ $piieCode }}"
                                        {{ (string) old('tuition_currency', $programme->tuition_currency ?? '') === (string) $piieCode ? 'selected' : '' }}>
                                        {{ $piieCode }}
                                    </option>
                                @endforeach
                            @else
                                {{-- No currency table: accept the code as typed, and preserve
                                     whatever is already stored so saving an unrelated field
                                     cannot silently clear it. --}}
                                @if($programme?->tuition_currency)
                                    <option value="{{ $programme->tuition_currency }}" selected>{{ $programme->tuition_currency }}</option>
                                @endif
                            @endif
                        </select>
                    </div>

                    <div class="col-12 col-md-4 fpb-7">
                        <label class="eForm-label" for="piie-basis">{{ get_phrase('What this amount covers') }}</label>
                        <select id="piie-basis" class="form-control eForm-control" name="tuition_fee_basis">
                            <option value="">{{ get_phrase('— Not stated —') }}</option>
                            @foreach(\App\Support\ProgrammeCatalogue\ProgrammePrice::SELECTABLE_BASES as $piieBasis)
                                <option value="{{ $piieBasis }}"
                                    {{ (string) old('tuition_fee_basis', $programme->tuition_fee_basis ?? '') === (string) $piieBasis ? 'selected' : '' }}>
                                    {{ \App\Support\ProgrammeCatalogue\ProgrammePrice::BASIS_LABELS[$piieBasis] ?? $piieBasis }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="alert alert-light border mt-2 mb-0 py-2 px-3" style="font-size:.8125rem;">
                    <strong>{{ get_phrase('The website will show:') }}</strong>
                    <span data-piie-price-preview>
                        {{-- Server-rendered so it is correct on first paint with no
                             JavaScript; the inline script below only keeps it live. --}}
                        @php
                            $piieBasisLabels = \App\Support\ProgrammeCatalogue\ProgrammePrice::BASIS_LABELS;
                            $piieAmount = old('tuition_fee', $programme->tuition_fee ?? null);
                            $piieBasis  = old('tuition_fee_basis', $programme->tuition_fee_basis ?? null);
                            $piieHasAmount = $piieAmount !== null && $piieAmount !== '' && is_numeric($piieAmount) && (float) $piieAmount > 0;
                            $piieShowPrice = $piieHasAmount && ! empty($piieBasis) && $piieBasis !== 'contact';
                        @endphp
                        @if($piieShowPrice)
                            <strong>{{ $programme?->tuition_currency ?: $tenantCurrency }} {{ number_format((float) $piieAmount, 0) }}</strong>
                            — {{ $piieBasisLabels[$piieBasis] ?? $piieBasis }}
                        @elseif($piieHasAmount && $piieBasis === 'contact')
                            {{ get_phrase('Contact us for tuition fees') }}
                        @elseif($piieHasAmount)
                            {{ get_phrase('Nothing yet —') }} {{ get_phrase('the website will not print an amount whose period is unknown. Choose what it covers above.') }}
                        @else
                            {{ get_phrase('Nothing yet —') }} {{ \App\Support\ProgrammeCatalogue\ProgrammePrice::contactLabel() }}. {{ get_phrase('A blank amount is never shown as zero.') }}
                        @endif
                    </span>
                </div>
            </fieldset>

            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ $programme ? get_phrase('Update Programme') : get_phrase('Create Programme') }}</button>
            </div>
        </div>
    </form>

    {{-- ══════════════════════════════════════════════════════════════════
         COVER IMAGE — a separate form, because a file upload must not be
         submitted by an unrelated edit of the fields above.
    ══════════════════════════════════════════════════════════════════ --}}
    @if($piieHasProgramme)
        <div class="mt-4 pt-3" style="border-top:1px solid var(--piie-line,#e6e9ef);">
            <h6 style="font-size:.95rem;">{{ get_phrase('Public catalogue cover image') }}</h6>
            <p class="text-muted" style="font-size:.8125rem;line-height:1.5;">
                {{ get_phrase('This image is public: it appears on the marketing website. It is cropped to 16:9 and re-encoded on upload. A JPG, PNG or WebP up to 8 MB.') }}
            </p>

            <div class="row align-items-start g-3">
                <div class="col-12 col-md-5">
                    @if($coverUrl)
                        <img src="{{ $coverUrl }}" alt="{{ get_phrase('Current programme cover') }}"
                             style="width:100%;max-width:320px;aspect-ratio:16/9;object-fit:cover;border-radius:8px;border:1px solid #e6e9ef;">
                        <p class="text-muted mt-2 mb-0" style="font-size:.75rem;">
                            {{ $programme->cover_image_name ?: get_phrase('Current cover') }}
                            @if($programme->cover_image_updated_at)
                                &middot; {{ get_phrase('updated') }} {{ $programme->cover_image_updated_at->format('j M Y') }}
                            @endif
                        </p>
                    @else
                        <div data-testid="piie-no-cover"
                             style="width:100%;max-width:320px;aspect-ratio:16/9;border-radius:8px;border:1px dashed #c9d2de;
                                    background:linear-gradient(135deg,#0B2E4F 0%,#14507F 60%,#1D6FA8 100%);
                                    display:flex;align-items:center;justify-content:center;color:#fff;
                                    font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;text-align:center;padding:.5rem;">
                            {{ get_phrase('No cover — branded fallback will be shown') }}
                        </div>
                        <p class="text-muted mt-2 mb-0" style="font-size:.75rem;">
                            {{ get_phrase('No cover is a normal state. The catalogue shows a designed panel in the faculty colour rather than an invented photograph.') }}
                        </p>
                    @endif
                </div>

                <div class="col-12 col-md-7">
                    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.programmes.cover.store', $programme->id) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="eForm-label" for="piie-cover">{{ $coverUrl ? get_phrase('Replace cover image') : get_phrase('Upload cover image') }}</label>
                            <input type="file" id="piie-cover" class="form-control eForm-control" name="cover_image"
                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                   required>
                            @error('cover_image')
                                <div class="text-danger mt-1" style="font-size:.8125rem;" role="alert">{{ $message }}</div>
                            @enderror
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">
                            {{ $coverUrl ? get_phrase('Replace image') : get_phrase('Upload image') }}
                        </button>
                    </form>

                    @if($coverUrl)
                        <form method="POST" class="mt-2" action="{{ route('admin.programmes.cover.remove', $programme->id) }}"
                              onsubmit="return confirm('{{ get_phrase('Remove the cover image? The programme will return to the branded fallback.') }}');">
                            @csrf
                            <button class="btn btn-outline-danger btn-sm" type="submit">
                                {{ get_phrase('Remove image') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             WEBSITE PUBLICATION — separate from academic activation
        ══════════════════════════════════════════════════════════════ --}}
        <div class="mt-4 pt-3" style="border-top:1px solid var(--piie-line,#e6e9ef);">
            <h6 style="font-size:.95rem;">{{ get_phrase('Website catalogue') }}</h6>
            <p class="text-muted" style="font-size:.8125rem;line-height:1.5;">
                {{ get_phrase('Publishing projects this programme onto the public catalogue. The academic record stays the source of truth: renaming the programme here updates the website. Manually written CMS cards are never overwritten.') }}
            </p>

            <div class="d-flex flex-wrap align-items-center gap-2">
                @if($programme->is_published)
                    <span class="badge bg-success">{{ get_phrase('Published to website') }}</span>
                    <form method="POST" action="{{ route('admin.programmes.unpublish', $programme->id) }}">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm" type="submit">{{ get_phrase('Unpublish') }}</button>
                    </form>
                @else
                    <span class="badge bg-secondary">{{ get_phrase('Not published') }}</span>
                    @unless($programme->is_active)
                        <button class="btn btn-primary btn-sm" type="button" disabled
                                title="{{ get_phrase('Activate this programme before publishing it.') }}">
                            {{ get_phrase('Publish to website') }}
                        </button>
                        <small class="text-muted d-block w-100" style="font-size:.8125rem;">
                            {{ get_phrase('This programme is deactivated, so it cannot be advertised.') }}
                        </small>
                    @else
                        <form method="POST" action="{{ route('admin.programmes.publish', $programme->id) }}">
                            @csrf
                            <input type="hidden" name="section_key" value="{{ $programme->website_section_key ?? \App\Support\ProgrammeCatalogue\ProgrammePublisher::DEFAULT_SECTION }}">
                            <button class="btn btn-primary btn-sm" type="submit">{{ get_phrase('Publish to website') }}</button>
                        </form>
                    @endunless
                @endif

                <a class="btn btn-light btn-sm" href="{{ route('admin.programmes.preview', $programme->id) }}" target="_blank" rel="noopener">
                    {{ get_phrase('Preview card') }}
                </a>
            </div>

            <div class="mt-2">
                <label class="eForm-label" for="piie-order">{{ get_phrase('Catalogue order') }}</label>
                <input type="number" id="piie-order" class="form-control eForm-control" name="website_sort_order"
                       form="piie-programme-edit"
                       value="{{ old('website_sort_order', $programme->website_sort_order ?? '') }}"
                       min="0" step="1" style="max-width:160px;"
                       placeholder="{{ get_phrase('last') }}">
                <small class="text-muted d-block mt-1" style="font-size:.75rem;">
                    {{ get_phrase('Lower numbers appear first. Leave blank to sort last, after every explicitly ordered programme.') }}
                </small>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
    // Keeps the "the website will show" line live while the form is open. It is
    // a convenience only: the line is rendered server-side, so it is correct on
    // first paint and remains correct if this script never runs.
    (function () {
        var amount = document.getElementById('piie-fee');
        var basis  = document.getElementById('piie-basis');
        var out    = document.querySelector('[data-piie-price-preview]');
        if (!amount || !basis || !out) { return; }

        var LABELS = @json(\App\Support\ProgrammeCatalogue\ProgrammePrice::BASIS_LABELS);
        var CONTACT = @json(\App\Support\ProgrammeCatalogue\ProgrammePrice::contactLabel());
        var TENANT = @json($tenantCurrency);

        function fmt(n) {
            return Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });
        }

        function update() {
            var value = parseFloat(amount.value);
            var hasAmount = amount.value !== '' && !isNaN(value) && value > 0;
            var b = basis.value;

            if (!hasAmount) {
                out.textContent = CONTACT;
                return;
            }
            if (b === 'contact') {
                out.textContent = CONTACT;
                return;
            }
            if (!b) {
                out.textContent = 'Not shown yet — choose what the amount covers above.';
                return;
            }
            var currency = document.getElementById('piie-currency');
            var code = currency && currency.value ? currency.value : TENANT;
            out.textContent = (code ? code + ' ' : '') + fmt(value) + ' — ' + (LABELS[b] || b);
        }

        amount.addEventListener('input', update);
        basis.addEventListener('change', update);
        var currency = document.getElementById('piie-currency');
        if (currency) { currency.addEventListener('change', update); }
    })();
</script>
@endpush