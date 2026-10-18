    @if($isDraft && $canManage && $curriculum->stages->isNotEmpty())
        <form method="GET" action="{{ route('admin.curricula.subjects.search', $curriculum->id) }}" class="row g-2 mb-2" id="subject-search-form"><div class="col-md-8"><label class="form-label" for="study-plan-course-unit-search">{{ get_phrase('Search Course Units') }}</label><input id="study-plan-course-unit-search" class="form-control" name="search" placeholder="{{ get_phrase('Search by Course Unit name or code') }}"></div><div class="col-md-4 d-flex align-items-end"><button class="eBtn eBtn-outline-primary" type="submit">{{ get_phrase('Search Course Units') }}</button></div></form>
        <form method="POST" action="{{ route('admin.curricula.memberships.store', $curriculum->id) }}" id="subject-picker-form" class="border rounded p-3 mb-3">@csrf
            <label class="form-label">{{ get_phrase('Add Course Units to Programme Year') }}</label><select name="curriculum_stage_id" class="form-select mb-2" required>@foreach($curriculum->stages as $stage)<option value="{{ $stage->id }}">{{ $stage->sequence }}. {{ $stage->label }}</option>@endforeach</select>
            <div id="subject-results" class="row g-2 mb-2" aria-live="polite"><p class="text-muted">{{ get_phrase('Search the shared Course Unit catalogue. A catalogue Programme association is legacy metadata; adding a unit here creates the separate Curriculum membership.') }}</p></div>
            <button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Add selected as draft memberships') }}</button>
        </form>
        <script>
        (() => {
            const form = document.getElementById('subject-search-form');
            const results = document.getElementById('subject-results');
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const url = new URL(form.action, window.location.origin);
                url.search = new URLSearchParams(new FormData(form));
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                if (!response.ok) { results.textContent = 'Unable to search Subjects.'; return; }
                const payload = await response.json();
                results.replaceChildren();
                if (!payload.data.length) { results.textContent = 'No available Course Units found.'; return; }
                payload.data.forEach((subject) => {
                    const wrapper = document.createElement('label'); wrapper.className = 'col-lg-6';
                    const box = document.createElement('span'); box.className = 'd-block border rounded p-2';
                    const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.name = 'subject_ids[]'; checkbox.value = subject.id; checkbox.className = 'me-2';
                    const legacyLabel = subject.programme_id ? (subject.programme_id == {{ (int)$curriculum->programme_id }} ? ' · catalogue association: this Programme' : ' · catalogue association: another Programme') : '';
                    const text = document.createTextNode(`${subject.code || '—'} · ${subject.name}${legacyLabel}`);
                    box.append(checkbox, text); wrapper.append(box); results.append(wrapper);
                });
                if (payload.next_page_url) { const more = document.createElement('a'); more.href = payload.next_page_url; more.textContent = 'Next results'; more.className = 'd-block mt-2'; more.addEventListener('click', async (event) => { event.preventDefault(); const next = await (await fetch(more.href, {headers: {'Accept':'application/json'}})).json(); next.data.forEach((subject) => { const label=document.createElement('label'); label.className='d-block'; const checkbox=document.createElement('input'); checkbox.type='checkbox'; checkbox.name='subject_ids[]'; checkbox.value=subject.id; checkbox.className='me-2'; label.append(checkbox, document.createTextNode(`${subject.code || '—'} · ${subject.name}`)); results.append(label); }); if (next.next_page_url) more.href=next.next_page_url; else more.remove(); }); results.append(more); }
            });
        })();
        </script>
    @endif
