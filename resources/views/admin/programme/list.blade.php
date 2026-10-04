@extends('admin.navigation')

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
                <div class="export-btn-area d-flex gap-2">
                    <a href="{{ route('admin.department_list') }}" class="export_btn export_btn-outline"><i class="bi bi-diagram-3"></i> {{ get_phrase('Manage Faculties') }}</a>
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
                        <option value="">{{ get_phrase('All Faculties') }}</option>
                        <option value="none" {{ $departmentId === 'none' ? 'selected' : '' }}>{{ get_phrase('Unassigned (no faculty)') }}</option>
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

            @if($departments->isEmpty())
                <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>{{ get_phrase('No faculties are set up yet, so every programme below is grouped as Unassigned.') }}</span>
                    <a href="{{ route('admin.department_list') }}" class="eBtn eBtn-sm eBtn-primary">{{ get_phrase('Add a Faculty') }}</a>
                </div>
            @endif

            <div class="accordion" id="programmeFacultyAccordion">
                @forelse($groups as $index => $group)
                    @php
                        $department = $group['department'];
                        $groupProgrammes = $group['programmes'];
                        // The last group is the catch-all: it holds programmes with no
                        // faculty set AND programmes whose faculty no longer exists, so
                        // no programme can ever be fetched-and-counted yet go unrendered.
                        $isUnresolved = $group['isUnresolvedGroup'] ?? false;
                        $groupKey = $department ? 'dept-' . $department->id : 'unassigned';
                        $groupTitle = $department
                            ? $department->name
                            : ($isUnresolved
                                ? get_phrase('Unassigned / Unknown Department')
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
                                    <p class="text-muted text-center py-3 mb-0">{{ get_phrase('No programmes in this faculty yet.') }}</p>
                                @else
                                    @if($isUnresolved)
                                        {{-- Say why these are here, so an orphaned programme
                                             reads as "needs a faculty assigned" rather than as
                                             an unexplained extra row. --}}
                                        <p class="text-muted small px-3 pt-3 mb-0">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            {{ get_phrase('These programmes have no faculty, or point at a faculty that no longer exists. Assign a faculty to each so they are grouped correctly.') }}
                                        </p>
                                    @endif
                                    <div class="table-responsive">
                                        <table class="table eTable mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>{{ get_phrase('Code') }}</th>
                                                    <th>{{ get_phrase('Name') }}</th>
                                                    <th>{{ get_phrase('Level') }}</th>
                                                    <th>{{ get_phrase('Mode') }}</th>
                                                    <th>{{ get_phrase('Duration') }}</th>
                                                    <th>{{ get_phrase('Tuition Fee') }}</th>
                                                    <th>{{ get_phrase('Status') }}</th>
                                                    <th>{{ get_phrase('Actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($groupProgrammes as $i => $prog)
                                                <tr>
                                                    <td>{{ $i + 1 }}</td>
                                                    <td><strong>{{ $prog->code }}</strong></td>
                                                    <td>{{ $prog->name }}</td>
                                                    <td><span class="badge bg-primary">{{ $prog->level }}</span></td>
                                                    <td>{{ $prog->mode }}</td>
                                                    <td>{{ $prog->duration ?? '—' }}</td>
                                                    <td>{{ number_format($prog->tuition_fee, 0) }}</td>
                                                    <td>
                                                        @if($prog->is_active)
                                                            <span class="badge bg-success">{{ get_phrase('Active') }}</span>
                                                        @else
                                                            <span class="badge bg-secondary">{{ get_phrase('Inactive') }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="d-flex flex-wrap gap-1">
                                                        @if($canSeeProgrammes && auth()->user()->hasPermission('academic.curriculum.view'))
                                                            <a href="{{ route('admin.curricula.index', ['programme_id' => $prog->id]) }}" class="eBtn eBtn-sm eBtn-info" title="{{ get_phrase('Manage Programme Study Plans') }}">{{ get_phrase('Study Plans') }}</a>
                                                        @endif
                                                        @if($canSeeProgrammes && app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'academic.course_offering.view'))
                                                            <a href="{{ route('admin.course_offerings.index', ['programme_id' => $prog->id]) }}" class="eBtn eBtn-sm eBtn-info">{{ get_phrase('Offerings') }}</a>
                                                        @endif
                                                        <a href="javascript:;" class="eBtn eBtn-sm eBtn-primary" title="{{ get_phrase('Edit') }}" onclick="rightModal('{{ route('admin.programmes.open_modal', ['id' => $prog->id]) }}', '{{ get_phrase('Edit Programme') }}')"><i class="bi bi-pencil"></i></a>
                                                        <a href="{{ route('admin.programmes.toggle', $prog->id) }}" class="eBtn eBtn-sm eBtn-warning" title="{{ $prog->is_active ? get_phrase('Deactivate') : get_phrase('Activate') }}"><i class="bi bi-toggle-on"></i></a>
                                                        <a href="{{ route('admin.programmes.destroy', $prog->id) }}" class="eBtn eBtn-sm eBtn-danger" title="{{ get_phrase('Delete') }}" onclick="return confirm('{{ get_phrase('Delete this programme? This only works if it has no applications or students linked to it.') }}')"><i class="bi bi-trash"></i></a>
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
    </div>
</div>
@endsection
