{{-- Compact Academic Setup quick-access bar. UI-only; reuses existing routes and RBAC checks. --}}
@php
    $setupItems = [
        ['label' => 'Programmes', 'icon' => 'bi-mortarboard', 'index' => 'admin.programmes.index', 'create' => 'admin.programmes.open_modal'],
        ['label' => 'Programme Cohorts', 'icon' => 'bi-people', 'index' => 'admin.programme_cohorts.index', 'create' => 'admin.programme_cohorts.create'],
        ['label' => 'Programme Curriculum', 'icon' => 'bi-list-check', 'index' => 'admin.curricula.index', 'create' => null],
        ['label' => 'Programme Years', 'icon' => 'bi-list-ol', 'index' => 'admin.programme_years.index', 'create' => null],
        ['label' => 'Academic Calendar', 'icon' => 'bi-calendar-range', 'index' => 'admin.academic_structure.index', 'create' => null],
    ];
@endphp
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-nowrap overflow-auto gap-2 align-items-center" style="scrollbar-width:thin;">
            @foreach($setupItems as $item)
                @if(\Illuminate\Support\Facades\Route::has($item['index']) && $navCan($item['index']))
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi {{ $item['icon'] }}"></i><span>{{ get_phrase($item['label']) }}</span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="{{ route($item['index']) }}">{{ get_phrase('View All') }}</a></li>
                        @if($item['create'] && \Illuminate\Support\Facades\Route::has($item['create']) && $navCan($item['create']))
                        <li><a class="dropdown-item" href="{{ route($item['create']) }}">{{ get_phrase('Create New') }}</a></li>
                        @endif
                        <li><a class="dropdown-item" href="{{ route($item['index']) }}">{{ get_phrase('Edit Existing') }}</a></li>
                        <li><a class="dropdown-item text-danger" href="{{ route($item['index']) }}">{{ get_phrase('Delete Existing') }}</a></li>
                    </ul>
                </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
