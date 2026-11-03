@extends('admin.navigation')
@section('content')

<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div class="d-flex flex-column">
                <h4>{{ get_phrase('Document Requirements') }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="{{ route('admin.hei_admissions.index') }}">{{ get_phrase('Admissions') }}</a></li>
                    <li><a href="#">{{ get_phrase('Document Requirements') }}</a></li>
                </ul>
            </div>
            <div class="export-btn-area d-flex gap-2">
                <a href="{{ route('admin.hei_admissions.index') }}" class="export_btn export_btn-outline">
                    <i class="bi bi-arrow-left"></i> {{ get_phrase('Back to Applications') }}
                </a>
                <a href="{{ route('admin.admissions_documents.restore') }}" class="export_btn export_btn-outline"
                   onclick="return confirm('{{ get_phrase('Install the standard requirement set? Existing entries with the same key will be reset to their defaults.') }}')">
                    {{ get_phrase('Restore Defaults') }}
                </a>
                <button type="button" class="export_btn" onclick="resetRequirementForm(); document.getElementById('formCard').scrollIntoView({behavior:'smooth'});">
                    <i class="bi bi-plus-lg"></i> {{ get_phrase('Add Requirement') }}
                </button>
            </div>
        </div>
    </div></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@if($usingDefaults)
    <div class="alert alert-info">
        <strong>{{ get_phrase('No requirements configured.') }}</strong>
        {{ get_phrase('Applicants are currently being asked for the built-in standard set. Add your own below, or install the standard set to edit it.') }}
    </div>
@endif

{{-- Horizontal row of requirement cards --}}
<div class="eSection-wrap mb-3">
    <div class="d-flex flex-wrap align-items-stretch gap-3">
        @forelse($requirements as $requirement)
            <div class="card flex-shrink-0" style="min-width:260px; max-width:300px;">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong>{{ $requirement->label }}</strong>
                            @unless($requirement->is_active)
                                <span class="badge bg-secondary ms-1">{{ get_phrase('Disabled') }}</span>
                            @endunless
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <button type="button" class="dropdown-item"
                                            onclick="editRequirement({{ $requirement->toJson() }})">
                                        <i class="bi bi-pencil me-2"></i>{{ get_phrase('Edit') }}
                                    </button>
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger"
                                       href="{{ route('admin.admissions_documents.destroy', $requirement->id) }}"
                                       onclick="return confirm('{{ get_phrase('Remove this requirement? Files already uploaded against it are kept and stay visible on the review screen.') }}')">
                                        <i class="bi bi-trash me-2"></i>{{ get_phrase('Delete') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    @if($requirement->description)
                        <small class="text-muted d-block mb-2">{{ $requirement->description }}</small>
                    @endif
                    <div class="mb-2">
                        <code style="font-size:11px;">{{ $requirement->key }}</code>
                    </div>
                    <div class="mb-2">
                        @if(empty($requirement->applies_to_levels))
                            <span class="badge bg-light text-dark">{{ get_phrase('All levels') }}</span>
                        @else
                            @foreach($requirement->applies_to_levels as $level)
                                <span class="badge bg-light text-dark">{{ $level }}</span>
                            @endforeach
                        @endif
                    </div>
                    <div>
                        <span class="badge bg-{{ $requirement->is_required ? 'danger' : 'secondary' }}">
                            {{ $requirement->is_required ? get_phrase('Required') : get_phrase('Optional') }}
                        </span>
                        @if($requirement->allow_multiple)
                            <span class="badge bg-info">{{ get_phrase('Multiple files') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-muted py-4">{{ get_phrase('No requirements configured yet.') }}</div>
        @endforelse
    </div>
</div>

{{-- Form card --}}
<div class="row g-3" id="formCard">
    <div class="col-12">
        <div class="eSection-wrap">
            <h5 class="mb-3" id="formTitle">{{ get_phrase('Add Requirement') }}</h5>

            <form method="POST" id="requirementForm" action="{{ route('admin.admissions_documents.store') }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ get_phrase('Label') }} <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="fLabel" class="form-control eForm-control" required>
                    </div>
                    <div class="col-md-6" id="keyWrap">
                        <label class="form-label">{{ get_phrase('Key') }} <span class="text-danger">*</span></label>
                        <input type="text" name="key" id="fKey" class="form-control eForm-control" pattern="[a-z0-9_]+">
                        <small class="text-muted">
                            {{ get_phrase('Lowercase letters, numbers and underscores. Uploaded files are filed under this, so it cannot be changed later.') }}
                        </small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ get_phrase('Description') }}</label>
                        <textarea name="description" id="fDescription" class="form-control eForm-control" rows="2"
                                  placeholder="{{ get_phrase('Shown to the applicant under the label.') }}"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ get_phrase('Applies to programme levels') }}</label>
                        <select name="applies_to_levels[]" id="fLevels" class="form-control eForm-control" multiple size="6">
                            @foreach($levels as $level)
                                <option value="{{ $level }}">{{ $level }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{ get_phrase('Select none to require it of every applicant.') }}</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ get_phrase('Sort Order') }}</label>
                        <input type="number" name="sort_order" id="fSort" class="form-control eForm-control" value="0" min="0" max="999">
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" name="is_required" id="fRequired" value="1" checked>
                            <label class="form-check-label" for="fRequired">{{ get_phrase('Required to submit') }}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="allow_multiple" id="fMultiple" value="1">
                            <label class="form-check-label" for="fMultiple">{{ get_phrase('Allow multiple files') }}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="fActive" value="1" checked>
                            <label class="form-check-label" for="fActive">{{ get_phrase('Active') }}</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Save') }}</button>
                    <button type="button" class="eBtn eBtn-outline d-none" id="cancelEdit" onclick="resetRequirementForm()">
                        {{ get_phrase('Cancel') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var storeUrl = "{{ route('admin.admissions_documents.store') }}";
    var updateUrlTemplate = "{{ route('admin.admissions_documents.update', ':id') }}";

    function editRequirement(requirement) {
        document.getElementById('formTitle').textContent = "{{ get_phrase('Edit Requirement') }}";
        document.getElementById('requirementForm').action = updateUrlTemplate.replace(':id', requirement.id);

        document.getElementById('fLabel').value       = requirement.label || '';
        document.getElementById('fKey').value         = requirement.key || '';
        document.getElementById('fDescription').value = requirement.description || '';
        document.getElementById('fSort').value        = requirement.sort_order || 0;
        document.getElementById('fRequired').checked  = !!requirement.is_required;
        document.getElementById('fMultiple').checked  = !!requirement.allow_multiple;
        document.getElementById('fActive').checked    = !!requirement.is_active;

        document.getElementById('fKey').readOnly = true;

        var levels = requirement.applies_to_levels || [];
        Array.from(document.getElementById('fLevels').options).forEach(function (option) {
            option.selected = levels.indexOf(option.value) !== -1;
        });

        document.getElementById('cancelEdit').classList.remove('d-none');
        window.scrollTo({ top: document.getElementById('formTitle').offsetTop - 100, behavior: 'smooth' });
    }

    function resetRequirementForm() {
        document.getElementById('formTitle').textContent = "{{ get_phrase('Add Requirement') }}";
        document.getElementById('requirementForm').reset();
        document.getElementById('requirementForm').action = storeUrl;
        document.getElementById('fKey').readOnly = false;
        document.getElementById('cancelEdit').classList.add('d-none');

        Array.from(document.getElementById('fLevels').options).forEach(function (option) {
            option.selected = false;
        });
    }
</script>

@endsection
