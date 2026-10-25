@php
    $placementPattern = $calendarPattern ?? (\Illuminate\Support\Facades\Schema::hasColumn('schools', 'academic_calendar_pattern')
        ? \Illuminate\Support\Facades\DB::table('schools')->where('id', $curriculum->school_id)->value('academic_calendar_pattern')
        : null) ?? 'semester';
    $placementPeriodLabel = $periodLabel ?? ($placementPattern === 'semester' ? 'Semester' : 'Term');
@endphp
@if($isDraft && $canManage && $curriculum->stages->isNotEmpty())
    <form method="GET" action="{{ route('admin.curricula.subjects.search', $curriculum->id) }}" class="row g-2 mb-2" id="subject-search-form">
        <div class="col-md-8"><label class="form-label" for="study-plan-course-unit-search">Search Course Unit catalogue</label><input id="study-plan-course-unit-search" class="form-control" name="search" value="{{ old('search') }}" placeholder="Search by code or name"></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-primary" type="submit">Search Course Units</button></div>
    </form>
    <form method="POST" action="{{ route('admin.curricula.memberships.store', $curriculum->id) }}" id="subject-picker-form" class="border rounded p-3 mb-3">@csrf
        <h6 class="mb-1">Select Existing Course Unit</h6><p class="small text-muted">Search by code or name. The selection reuses the catalogue record and adds a curriculum placement.</p>
        <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label" for="placement-stage">Programme Year</label><select id="placement-stage" name="curriculum_stage_id" class="form-select" required>@foreach($curriculum->stages as $stage)<option value="{{ $stage->id }}">{{ $stage->sequence }}. {{ $stage->label }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label" for="placement-period">{{ $placementPeriodLabel }}</label><input id="placement-period" type="number" min="1" max="65535" name="period_sequence" class="form-control" placeholder="Optional"></div>
            <div class="col-md-2"><label class="form-label" for="placement-classification">Type</label><select id="placement-classification" name="classification" class="form-select"><option value="compulsory">Compulsory</option><option value="elective">Elective</option></select></div>
            <div class="col-md-2"><label class="form-label" for="placement-credits">Credits</label><input id="placement-credits" type="number" min="0" max="9999.99" step="0.01" name="credits" value="0.00" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label" for="placement-order">Order</label><input id="placement-order" type="number" min="0" max="65535" name="sequence" value="0" class="form-control" required></div>
        </div>
        <input type="hidden" name="period_type" value="{{ $placementPattern }}">
        <input type="hidden" name="search" id="selected-course-unit-query" value="{{ old('search') }}">
        <div id="subject-results" class="row g-2 mb-2" aria-live="polite"><p class="text-muted">Search the shared catalogue. Remove a placement to detach it from this curriculum; the catalogue Course Unit remains.</p></div>
        <button class="btn btn-primary" type="submit">Add selected to curriculum</button>
        <a class="btn btn-outline-secondary ms-2" href="{{ route('admin.subject.setup', ['curriculum_id' => $curriculum->id]) }}">Create New Course Unit</a>
    </form>
    <script>
    (() => {
        const form = document.getElementById('subject-search-form');
        const results = document.getElementById('subject-results');
        const searchInput = document.getElementById('study-plan-course-unit-search');
        const submittedQuery = document.getElementById('selected-course-unit-query');
        const previouslySelected = @json(is_array(old('subject_ids')) ? array_map('strval', old('subject_ids')) : []);
        searchInput.addEventListener('input', () => { submittedQuery.value = searchInput.value; });

        function appendSubjects(subjects) {
            subjects.forEach((subject) => {
                const label = document.createElement('label');
                label.className = 'col-12 col-md-6';
                const card = document.createElement('span');
                card.className = 'd-flex gap-2 align-items-start border rounded p-2 h-100';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox'; checkbox.name = 'subject_ids[]'; checkbox.value = subject.id;
                checkbox.className = 'form-check-input mt-1'; checkbox.checked = previouslySelected.includes(String(subject.id));
                card.append(checkbox, document.createTextNode(`${subject.code || '—'} · ${subject.name}`));
                label.append(card); results.append(label);
            });
        }

        function appendMoreButton(url) {
            const wrapper = document.createElement('div'); wrapper.className = 'col-12';
            const button = document.createElement('button'); button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary'; button.textContent = 'Load more Course Units';
            button.addEventListener('click', async () => {
                button.disabled = true; button.textContent = 'Loading…';
                try {
                    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                    if (!response.ok) throw new Error('Catalogue search failed');
                    const payload = await response.json(); wrapper.remove(); appendSubjects(payload.data);
                    if (payload.next_page_url) appendMoreButton(payload.next_page_url);
                } catch (error) { button.disabled = false; button.textContent = 'Try loading more'; }
            });
            wrapper.append(button); results.append(wrapper);
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault(); results.replaceChildren(); results.textContent = 'Searching Course Units…';
            const url = new URL(form.action, window.location.origin); url.search = new URLSearchParams(new FormData(form));
            try {
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                if (!response.ok) throw new Error('Catalogue search failed');
                const payload = await response.json(); results.replaceChildren();
                if (!payload.data.length) { results.textContent = 'No available Course Units found.'; return; }
                appendSubjects(payload.data); if (payload.next_page_url) appendMoreButton(payload.next_page_url);
            } catch (error) { results.textContent = 'Unable to search the catalogue. Try again.'; }
        });
        if (previouslySelected.length && submittedQuery.value.trim()) { searchInput.value = submittedQuery.value; form.requestSubmit(); }
    })();
    </script>
@endif
