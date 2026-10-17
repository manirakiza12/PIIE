@php
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
    <form method="POST" id="piie-programme-edit" enctype="multipart/form-data" class="d-block ajaxForm" data-workspace-continuous
          action="{{ $programme ? route('admin.programmes.update', $programme->id) : route('admin.programmes.store') }}">
        @csrf
        <div class="programme-editor-grid"><section class="programme-editor-card"><h5>{{ get_phrase('Programme details') }}</h5><div class="form-row">
            <div class="row">
                <div class="col-12 col-sm-6 fpb-7">
                    <label class="eForm-label" for="piie-code">{{ get_phrase('Programme Code') }} *</label>
                    <input type="text" id="piie-code" class="form-control eForm-control" name="code"
                           value="{{ old('code', $programme->code ?? '') }}" placeholder="e.g. BSC-CS" required maxlength="20">
                </div>
                <div class="col-12 col-sm-6 fpb-7">
                    <label class="eForm-label" for="piie-level">{{ get_phrase('Level') }} *</label>
                    <div class="d-flex gap-2 align-items-center">
                    <select id="piie-level" class="form-control eForm-control" name="level" required>
                        @foreach($levels ?? \App\Support\ProgrammeCatalogue\ProgrammeLevels::options((int) auth()->user()->school_id) as $lvl)
                            <option value="{{ $lvl }}" {{ (old('level', $programme->level ?? '') == $lvl) ? 'selected' : '' }}>{{ $lvl }}</option>
                        @endforeach
                    </select>
                    <button type="button" id="piie-add-level" class="btn btn-outline-primary flex-shrink-0" aria-label="Add programme level" title="Add programme level" aria-controls="piie-level-panel" aria-expanded="false">+</button>
                    </div>
                    <div id="piie-level-panel" class="border rounded p-2 mt-2" hidden>
                        <label for="piie-new-level" class="eForm-label">New programme level</label>
                        <input id="piie-new-level" type="text" class="form-control eForm-control" maxlength="100" placeholder="e.g. Higher Education Certificate (HEC)">
                        <div id="piie-level-message" class="small mt-1" role="status" aria-live="polite"></div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" id="piie-save-level" class="btn btn-primary btn-sm" data-url="{{ route('admin.programmes.levels.store') }}">Add level</button>
                            <button type="button" id="piie-cancel-level" class="btn btn-light btn-sm">Cancel</button>
                        </div>
                    </div>
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
                    <label class="eForm-label" for="piie-dept">{{ get_phrase('Department') }}</label>
                    <select id="piie-dept" class="form-control eForm-control" name="department_id">
                        <option value="">{{ get_phrase('— None —') }}</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}" {{ (string) old('department_id', $programme->department_id ?? '') === (string) $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                    @if($departments->isEmpty())
                        <small class="text-muted d-block mt-1">
                            {{ get_phrase('No departments set up yet.') }}
                            <a href="{{ route('admin.department.departments.create') }}" target="_blank" rel="noopener">{{ get_phrase('Add one') }}</a>
                        </small>
                    @endif
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
                    {{ get_phrase('Optional: enter the amount, currency and payment period to display on the website.') }}
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

        </div></section>
        <aside>@include('admin.programme._media_fields')</aside>
        </div>
        <div class="workspace-actions">
            <button class="btn btn-primary" type="submit">{{ $programme ? get_phrase('Save all changes') : get_phrase('Create programme with images') }}</button>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('piie-programme-edit');
    var add = form.querySelector('#piie-add-level');
    var panel = form.querySelector('#piie-level-panel');
    var input = form.querySelector('#piie-new-level');
    var save = form.querySelector('#piie-save-level');
    var cancel = form.querySelector('#piie-cancel-level');
    var message = form.querySelector('#piie-level-message');
    var select = form.querySelector('#piie-level');
    function close() { panel.hidden = true; add.setAttribute('aria-expanded', 'false'); add.focus(); }
    add.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        add.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) { message.textContent = ''; input.focus(); }
    });
    cancel.addEventListener('click', close);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); save.click(); }
        if (event.key === 'Escape') { event.preventDefault(); close(); }
    });
    save.addEventListener('click', async function () {
        if (!input.value.trim()) { message.textContent = 'Enter a programme level.'; input.focus(); return; }
        save.disabled = true; cancel.disabled = true; add.disabled = true;
        message.textContent = 'Saving level…';
        try {
            var response = await fetch(save.dataset.url, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                body: JSON.stringify({name: input.value})
            });
            var result = await response.json().catch(function () { return {}; });
            if (!response.ok || typeof result.name !== 'string') throw new Error(result.errors?.name?.[0] || 'Unable to add level. Please try again.');
            if (!Array.from(select.options).some(function (option) { return option.value === result.name; })) {
                select.add(new Option(result.name, result.name));
            }
            select.value = result.name;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            input.value = ''; close();
        } catch (error) { message.textContent = error.message || 'Unable to add level. Please try again.'; }
        finally { save.disabled = false; cancel.disabled = false; add.disabled = false; }
    });
})();
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
    (function () {
        var form = document.getElementById('piie-programme-edit');
        if (!form) return;
        form.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
            var preview = document.getElementById(input.dataset.preview);
            var original = preview.getAttribute('src') || '';
            var objectUrl;
            input.addEventListener('change', function () {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                objectUrl = input.files[0] ? URL.createObjectURL(input.files[0]) : null;
                preview.src = objectUrl || original;
                preview.classList.toggle('d-none', !objectUrl && !original);
                if (input.name === 'cover_image') {
                    var placeholder = form.querySelector('.piie-no-cover');
                    if (placeholder) placeholder.classList.toggle('d-none', !!objectUrl);
                }
                if (input.name === 'cover_image' && objectUrl) {
                    var remove = form.querySelector('[name="remove_cover"]');
                    if (remove) remove.checked = false;
                }
            });
        });
    })();
</script>
