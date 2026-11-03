@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('New Academic Exemption') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li><a href="{{ route('admin.exemptions.index') }}">{{ get_phrase('Exemptions') }}</a></li>
                <li aria-current="page">{{ get_phrase('Create') }}</li>
            </ul>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger mt-3"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="eSection-wrap mt-3">
    <form method="POST" action="{{ route('admin.exemptions.store') }}">
        @csrf
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Student') }} <span class="text-danger">*</span></label>
            <select name="student_id" class="form-select eForm-select" required>
                <option value="">{{ get_phrase('Select student') }}</option>
                @foreach($students as $s)
                    <option value="{{ $s->id }}" @selected(old('student_id') == $s->id)>{{ $s->name }} ({{ $s->code }})</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Programme') }} <span class="text-danger">*</span></label>
            <select name="programme_id" id="exemption_programme" class="form-select eForm-select" required>
                <option value="">{{ get_phrase('Select programme') }}</option>
                @foreach($programmes as $p)
                    <option value="{{ $p->id }}" @selected(old('programme_id') == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Curriculum') }} <span class="text-danger">*</span></label>
            <select name="curriculum_id" id="exemption_curriculum" class="form-select eForm-select" required>
                <option value="">{{ get_phrase('Select programme first') }}</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Exemption Type') }} <span class="text-danger">*</span></label>
            <select name="exemption_type" id="exemption_type" class="form-select eForm-select" required>
                <option value="course_unit" @selected(old('exemption_type') === 'course_unit')>{{ get_phrase('Individual Course Unit') }}</option>
                <option value="level" @selected(old('exemption_type') === 'level')>{{ get_phrase('Entire Academic Level') }}</option>
            </select>
        </div>
        <div class="mb-3" id="level_field" style="display:none;">
            <label class="eForm-label">{{ get_phrase('Academic Level (Stage)') }}</label>
            <select name="target_curriculum_stage_id" id="exemption_stage" class="form-select eForm-select">
                <option value="">{{ get_phrase('Select curriculum first') }}</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Previous Institution') }}</label>
            <input type="text" name="previous_institution" class="form-control eForm-control" value="{{ old('previous_institution') }}">
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Previous Qualification') }}</label>
            <input type="text" name="previous_qualification" class="form-control eForm-control" value="{{ old('previous_qualification') }}">
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Previous Results / Notes') }}</label>
            <textarea name="previous_results" class="form-control eForm-control" rows="3">{{ old('previous_results') }}</textarea>
        </div>
        <div class="mb-3">
            <label class="eForm-label">{{ get_phrase('Reason for Exemption') }}</label>
            <textarea name="reason" class="form-control eForm-control" rows="3">{{ old('reason') }}</textarea>
        </div>
        <div class="mb-3" id="items_field">
            <label class="eForm-label">{{ get_phrase('Course Units to Exempt') }}</label>
            <div id="exemption_items_container"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add_exemption_item">{{ get_phrase('Add Course Unit') }}</button>
        </div>
        <div class="pt-2">
            <a href="{{ route('admin.exemptions.index') }}" class="eBtn eBtn-outline-secondary">{{ get_phrase('Cancel') }}</a>
            <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Submit for Approval') }}</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const programmeSelect = document.getElementById('exemption_programme');
    const curriculumSelect = document.getElementById('exemption_curriculum');
    const typeSelect = document.getElementById('exemption_type');
    const levelField = document.getElementById('level_field');
    const itemsField = document.getElementById('items_field');
    const stageSelect = document.getElementById('exemption_stage');
    const itemsContainer = document.getElementById('exemption_items_container');
    const addBtn = document.getElementById('add_exemption_item');

    function toggleType() {
        if (typeSelect.value === 'level') {
            levelField.style.display = '';
            itemsField.style.display = 'none';
        } else {
            levelField.style.display = 'none';
            itemsField.style.display = '';
        }
    }
    typeSelect.addEventListener('change', toggleType);
    toggleType();

    programmeSelect.addEventListener('change', function () {
        const pid = this.value;
        curriculumSelect.innerHTML = '<option value="">{{ get_phrase("Loading...") }}</option>';
        if (!pid) { curriculumSelect.innerHTML = '<option value="">{{ get_phrase("Select programme first") }}</option>'; return; }
        fetch('/admin/curriculum/by-programme/' + pid)
            .then(r => r.json())
            .then(list => {
                curriculumSelect.innerHTML = '<option value="">{{ get_phrase("Select curriculum") }}</option>';
                list.forEach(c => {
                    const o = document.createElement('option');
                    o.value = c.id; o.textContent = c.name + ' (' + (c.version || '') + ')';
                    curriculumSelect.appendChild(o);
                });
            }).catch(() => { curriculumSelect.innerHTML = '<option value="">{{ get_phrase("Error loading") }}</option>'; });
    });

    curriculumSelect.addEventListener('change', function () {
        const cid = this.value;
        if (!cid) return;
        fetch('/admin/exemptions/curriculum/' + cid + '/stages')
            .then(r => r.json())
            .then(list => {
                stageSelect.innerHTML = '<option value="">{{ get_phrase("Select level") }}</option>';
                list.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id; o.textContent = s.name;
                    stageSelect.appendChild(o);
                });
            });
        // load memberships for item dropdown
        fetch('/admin/exemptions/curriculum/' + cid + '/memberships')
            .then(r => r.json())
            .then(list => { window.__memberships = list; });
    });

    let itemIndex = 0;
    addBtn.addEventListener('click', function () {
        const list = window.__memberships || [];
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 exemption-item-row';
        div.innerHTML = '<div class="col-6"><select name="items[' + itemIndex + '][curriculum_membership_id]" class="form-select eForm-select">' +
            '<option value="">{{ get_phrase("Select course unit") }}</option>' +
            list.map(m => '<option value="' + m.id + '">' + (m.code || '') + ' — ' + (m.name || '') + '</option>').join('') +
            '</select></div>' +
            '<div class="col-5"><input type="text" name="items[' + itemIndex + '][notes]" class="form-control eForm-control" placeholder="{{ get_phrase("Notes") }}"></div>' +
            '<div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger remove-item">✕</button></div>';
        itemsContainer.appendChild(div);
        itemIndex++;
        div.querySelector('.remove-item').addEventListener('click', function () { div.remove(); });
    });
});
</script>
@endsection