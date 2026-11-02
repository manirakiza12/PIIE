@extends('admin.navigation')

@section('page_styles')
<style>
@media print {
    @page { size: A4 landscape; margin: 10mm; }
    .text-truncate { white-space: normal !important; overflow: visible !important; text-overflow: clip !important; }
    table { font-size: 9pt; table-layout: auto; }
    th, td { padding: 4px 6px !important; vertical-align: top; }
    .badge { border: 1px solid #999; color: #000 !important; background: transparent !important; }
}
</style>
@endsection

@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Programmes') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                        <li><a href="#">{{ get_phrase('Programmes') }}</a></li>
                    </ul>
                </div>
                @php
                    $programmeMenuRestrictions = json_decode((string) auth()->user()->menu_permission, true) ?: [];
                @endphp
                <div class="export-btn-area d-flex gap-2 flex-wrap">
                    <a href="{{ route('admin.programmes.export', ['search' => $search, 'department_id' => $departmentId]) }}" class="export_btn bg-secondary"><i class="bi bi-download"></i> {{ get_phrase('Export CSV') }}</a>
                    <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.programmes.open_modal') }}', '{{ get_phrase('Add Programme') }}')">{{ get_phrase('Add Programme') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <div class="search-filter-area d-flex justify-content-between align-items-center flex-wrap gr-15 mb-3">
                <form action="{{ route('admin.programmes.index') }}" class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ get_phrase('Search programmes') }}" class="form-control eForm-control" style="min-width:220px;">
                    <select name="department_id" class="form-control eForm-control" style="min-width:200px;" onchange="this.form.submit()">
                        <option value="">{{ get_phrase('All Departments') }}</option>
                        <option value="none" {{ $departmentId === 'none' ? 'selected' : '' }}>{{ get_phrase('Unassigned (no department)') }}</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}" {{ (string) $departmentId === (string) $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Filter') }}</button>
                    @if($search || $departmentId !== '')
                        <a href="{{ route('admin.programmes.index') }}" class="eBtn eBtn-dark">{{ get_phrase('Clear') }}</a>
                    @endif
                </form>
                <small class="text-muted">{{ $totalCount }} {{ get_phrase('programme(s)') }}</small>
            </div>

            @include('shared.page_toolbar')

            {{-- Print/PDF overrides must come AFTER the shared toolbar so their
                 @page rule wins over the toolbar's portrait default. --}}
            <style>
            @media print {
                @page { size: A4 landscape; margin: 10mm; }
                .accordion-collapse.collapse { display: block !important; height: auto !important; visibility: visible !important; }
                .accordion-item { break-inside: avoid; page-break-inside: avoid; margin-bottom: 4px !important; }
                .accordion-body { padding: 0 !important; }
                .table-responsive { overflow: visible !important; }
                table.eTable { table-layout: auto; width: 100% !important; font-size: 8.5pt; }
                th, td { padding: 3px 5px !important; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
                .text-truncate { white-space: normal !important; overflow: visible !important; text-overflow: clip !important; }
                .badge { border: 1px solid #999; color: #000 !important; background: transparent !important; }
                img { max-width: 100%; }
            }
            </style>
            @if($departments->isEmpty())
                <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>{{ get_phrase('No departments are set up yet, so every programme below is grouped as Unassigned.') }}</span>
                    <a href="{{ route('admin.department.departments.create') }}" class="eBtn eBtn-sm eBtn-primary">{{ get_phrase('Add a Department') }}</a>
                </div>
            @endif

            <div id="page-print-area">
            <div class="accordion" id="programmeFacultyAccordion">
                @forelse($groups as $index => $group)
                    @php
                        $department = $group['department'];
                        $groupProgrammes = $group['programmes'];
                        $isUnresolved = $group['isUnresolvedGroup'] ?? false;
                        $groupKey = $department ? 'dept-' . $department->id : 'unassigned';
                        $groupTitle = $department
                            ? $department->name
                            : ($isUnresolved
                                ? get_phrase('Unassigned / Needs Department')
                                : get_phrase('Unassigned (no faculty)'));
                    @endphp
                    <div class="accordion-item mb-2" style="border:1px solid #e7e9ee; border-radius:8px; overflow:hidden;">
                        <h2 class="accordion-header">
                            <button class="accordion-button {{ $groupProgrammes->isEmpty() ? 'collapsed' : '' }}" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#group-{{ $groupKey }}">
                                <i class="bi {{ $department ? 'bi-diagram-3-fill' : 'bi-question-diamond' }} me-2"></i>
                                <strong>{{ $groupTitle }}</strong>
                                <span class="badge bg-primary ms-2">{{ $groupProgrammes->count() }}</span>
                            </button>
                        </h2>
                        <div id="group-{{ $groupKey }}" class="accordion-collapse collapse {{ $groupProgrammes->isNotEmpty() ? 'show' : '' }}"
                             data-bs-parent="#programmeFacultyAccordion">
                            <div class="accordion-body p-0">
                                @if($groupProgrammes->isEmpty())
                                    <p class="text-muted text-center py-3 mb-0">{{ get_phrase('No programmes in this department yet.') }}</p>
                                @else
                                    @if($isUnresolved)
                                        <p class="text-muted small px-3 pt-3 mb-0">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            {{ get_phrase('These programmes have no Department assigned, or their previous Department needs review. Assign an existing Department to file each programme correctly.') }}
                                        </p>
                                    @endif
                                    <div class="table-responsive">
                                        <table class="table eTable align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="d-none d-xl-table-cell">#</th>
                                                    <th>{{ get_phrase('Cover') }}</th>
                                                    <th>{{ get_phrase('Programme') }}</th>
                                                    <th class="d-none d-md-table-cell">{{ get_phrase('Level') }}</th>
                                                    <th class="d-none d-lg-table-cell">{{ get_phrase('Mode') }}</th>
                                                    <th class="d-none d-xl-table-cell">{{ get_phrase('Duration') }}</th>
                                                    <th style="min-width:150px;">{{ get_phrase('Tuition') }}<br><small class="text-muted fw-normal">{{ get_phrase('amount & basis') }}</small></th>
                                                    <th>{{ get_phrase('Academic') }}</th>
                                                    <th>{{ get_phrase('Website') }}</th>
                                                    <th class="text-end">{{ get_phrase('Actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($groupProgrammes as $i => $prog)
                                                <tr>
                                                    <td class="d-none d-xl-table-cell">{{ $i + 1 }}</td>

                                                    <td>
                                                        @php
                                                            $piieThumb = app(\App\Support\ProgrammeCatalogue\ProgrammeCoverImage::class)->url($prog);
                                                        @endphp
                                                        @if($piieThumb)
                                                            <img src="{{ $piieThumb }}" alt=""
                                                                 style="width:48px;height:28px;object-fit:cover;border-radius:4px;border:1px solid #e6e9ef;display:block;">
                                                        @else
                                                            <span class="text-muted" style="font-size:.7rem;">{{ get_phrase('none') }}</span>
                                                        @endif
                                                    </td>

                                                    <td style="max-width:240px;">
                                                        <div class="fw-semibold text-truncate" title="{{ $prog->code ?: $prog->name }}"><strong>{{ $prog->code ?: '—' }}</strong></div>
                                                        <div class="text-muted small text-truncate" title="{{ $prog->name }}">{{ $prog->name }}</div>
                                                        @if($prog->legacyDepartment)
                                                            <small class="d-block text-warning text-truncate" title="{{ get_phrase('Previously under') }} {{ $prog->legacyDepartment->name }}">{{ get_phrase('Previously under') }} {{ $prog->legacyDepartment->name }}</small>
                                                        @endif
                                                    </td>

                                                    <td class="d-none d-md-table-cell"><span class="badge bg-primary">{{ $prog->level }}</span></td>
                                                    <td class="d-none d-lg-table-cell small text-nowrap">{{ $prog->mode }}</td>
                                                    <td class="d-none d-xl-table-cell small text-nowrap">{{ $prog->duration ?? '—' }}</td>

                                                    <td style="min-width:150px;font-size:.8125rem;">
                                                        <div class="text-truncate" title="{{ \App\Support\ProgrammeCatalogue\ProgrammePrice::adminSummary($prog) }}">{{ \App\Support\ProgrammeCatalogue\ProgrammePrice::adminSummary($prog) }}</div>
                                                        @if(! \App\Support\ProgrammeCatalogue\ProgrammePrice::isPriced($prog)
                                                            && \App\Support\ProgrammeCatalogue\ProgrammePrice::amount($prog) !== null)
                                                            <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem;">
                                                                {{ get_phrase('not shown publicly') }}
                                                            </span>
                                                        @endif
                                                    </td>

                                                    <td>
                                                        @if($prog->is_active)
                                                            <span class="badge bg-success">{{ get_phrase('Active') }}</span>
                                                        @else
                                                            <span class="badge bg-secondary">{{ get_phrase('Inactive') }}</span>
                                                        @endif
                                                    </td>

                                                    <td>
                                                        @if($prog->is_published)
                                                            <span class="badge bg-success">{{ get_phrase('Published') }}</span>
                                                            @unless($prog->is_active)
                                                                <br><small class="text-muted" style="font-size:.7rem;">{{ get_phrase('hidden: deactivated') }}</small>
                                                            @endunless
                                                        @else
                                                            <span class="badge bg-light text-dark border">{{ get_phrase('Not published') }}</span>
                                                        @endif
                                                    </td>

                                                    <td class="text-end">
                                                        <div class="dropdown">
                                                            <button type="button" class="eBtn eBtn-sm eBtn-black dropdown-toggle table-action-btn-2" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ get_phrase('Actions for') }} {{ $prog->code ?: $prog->name }}">{{ get_phrase('Actions') }}</button>
                                                            <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                                                <li><a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.programmes.open_modal', ['id' => $prog->id]) }}', '{{ get_phrase('Edit Programme') }}')">{{ get_phrase('Edit') }}</a></li>
                                                                <li><a class="dropdown-item" href="{{ route('admin.programmes.preview', $prog->id) }}" target="_blank" rel="noopener">{{ get_phrase('Preview') }}</a></li>
                                                                <li><a class="dropdown-item" href="{{ route('admin.programmes.toggle', $prog->id) }}">{{ $prog->is_active ? get_phrase('Deactivate') : get_phrase('Activate') }}</a></li>
                                                                @if($prog->is_published)
                                                                    <li>
                                                                        <form method="POST" action="{{ route('admin.programmes.unpublish', $prog->id) }}" class="m-0">
                                                                            @csrf
                                                                            <button type="submit" class="dropdown-item">{{ get_phrase('Unpublish') }}</button>
                                                                        </form>
                                                                    </li>
                                                                @else
                                                                    <li>
                                                                        <form method="POST" action="{{ route('admin.programmes.publish', $prog->id) }}" class="m-0">
                                                                            @csrf
                                                                            <button type="submit" class="dropdown-item" @if(!$prog->is_active) disabled @endif>{{ get_phrase('Publish') }}</button>
                                                                        </form>
                                                                    </li>
                                                                @endif
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#academic-delete-modal"
                                                                        data-delete-action="{{ route('admin.programmes.destroy', $prog->id) }}"
                                                                        data-delete-title="{{ get_phrase('Delete Programme') }}"
                                                                        data-delete-identity="{{ ($prog->code ?: $prog->name) }} (record #{{ $prog->id }})"
                                                                        data-delete-reference="{{ $prog->code ?: $prog->name }}"
                                                                        data-delete-removes="{{ get_phrase('Programme, curriculum versions, applications, finance and academic workflows.') }}"
                                                                        data-delete-keeps="{{ get_phrase('Student profiles and shared Course Units.') }}"
                                                                        aria-label="{{ get_phrase('Delete') }} {{ $prog->code ?: $prog->name }}">{{ get_phrase('Delete') }}</button>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4">{{ get_phrase('No programmes found') }}</div>
                @endforelse
            </div>
            </div>

            @include('shared.academic_delete_modal')
        </div>
    </div>
</div>
@endsection
