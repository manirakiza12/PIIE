@extends('admin.navigation')

@section('content')
@php
    $pageLabel = $isHigherEducation ? 'Course Units' : ($isMixed ? 'Subjects / Course Units' : 'Subjects');
    $createLabel = $isHigherEducation ? 'Create Course Unit' : ($isMixed ? 'Create Subject / Course Unit' : 'Create Subject');
@endphp
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase($pageLabel) }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li aria-current="page">{{ get_phrase($pageLabel) }}</li>
            </ul>
            @if($isHigherEducation)
                <p class="text-muted mb-0">{{ get_phrase("Add and edit Course Units together with their Curriculum placements, credits and prerequisites.") }}</p>
            @elseif($isMixed)
                <p class="text-muted mb-0">{{ get_phrase('Maintain the shared catalogue. Choose a Class for a school subject or a Programme for a higher-education Course Unit.') }}</p>
            @endif
        </div>
        <div class="export-btn-area">
            <a href="{{ ($isHigherEducation || $isMixed) ? route('admin.subject.setup', request()->only(['curriculum_id', 'programme_id'])) : 'javascript:;' }}" class="export_btn" @if(!$isHigherEducation && !$isMixed) onclick="rightModal('{{ route('admin.subject.open_modal') }}', '{{ get_phrase($createLabel) }}')" @endif><span class="me-1" aria-hidden="true">+</span>{{ get_phrase($createLabel) }}</a>
        </div>
    </div>
</div>

@if($placementPlan ?? null)
<div class="eSection-wrap mt-3"><h5>{{ $placementPlan->programme->name }} ? {{ $placementPlan->version }}</h5>
@include('admin.subject.study_plan_picker', ['curriculum' => $placementPlan, 'isDraft' => $placementPlan->status === 'draft', 'canManage' => true])
</div>
@endif

<div class="eSection-wrap mt-3">
    <form method="GET" class="row g-3 align-items-end" action="{{ route('admin.subject_list') }}" aria-label="{{ get_phrase('Filter catalogue') }}">
        <div class="col-12 col-md-6 {{ $isHigherEducation ? 'col-lg-6' : 'col-lg-5' }}">
            <label for="catalogue-search" class="form-label">{{ get_phrase($isHigherEducation ? 'Search Course Units' : 'Search Subjects / Course Units') }}</label>
            <input type="search" name="search" id="catalogue-search" class="form-control" value="{{ $search }}" placeholder="{{ get_phrase('Search by name or code') }}">
        </div>
        @if($isHigherEducation || $isMixed)
            <div class="col-12 col-md-6 col-lg-3">
                <label for="catalogue-programme" class="form-label">{{ get_phrase('Programme') }}</label>
                <select name="programme_id" id="catalogue-programme" class="form-select">
                    <option value="">{{ get_phrase('All Programmes') }}</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" @selected((string)$programmeId === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if(!$isHigherEducation)
            <div class="col-12 col-md-6 col-lg-3">
                <label for="catalogue-class" class="form-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                <select name="class_id" id="catalogue-class" class="form-select">
                    <option value="">{{ get_phrase('All Classes') }}</option>
                    @foreach($classes as $class)
                        <option value="{{ $class->id }}" @selected((string)$classId === (string)$class->id)>{{ $class->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="col-12 col-md-6 col-lg-auto">
            <button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Apply filters') }}</button>
        </div>
    </form>
</div>

<div class="eSection-wrap mt-3">
    @if($subjects->count() > 0)
        <div class="table-responsive">
            <table class="table eTable align-middle">
                <thead>
                    <tr>
                        <th scope="col">{{ get_phrase('Code') }}</th>
                        <th scope="col">{{ get_phrase($isHigherEducation ? 'Course Unit' : ($isMixed ? 'Subject / Course Unit' : 'Subject')) }}</th>
                        @if($isHigherEducation)
                            <th scope="col">{{ get_phrase('Programme / Catalogue Association') }}</th>
                        @elseif($isMixed)
                            <th scope="col">{{ get_phrase('Catalogue Association') }}</th>
                        @else
                            <th scope="col">{{ academic_term('class', auth()->user()->school_id) }}</th>
                        @endif
                        <th scope="col" class="text-end">{{ get_phrase('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($subjects as $subject)
                        <tr>
                            <td class="text-nowrap">{{ $subject->code ?: '—' }}</td>
                            <td>{{ $subject->name }}</td>
                            @if($isHigherEducation)
                                <td>
                                    @if($subject->programme)
                                        {{ $subject->programme->code }} — {{ $subject->programme->name }}
                                    @elseif($subject->classes)
                                        <span class="text-muted">{{ get_phrase('Legacy Class association') }}:</span> {{ $subject->classes->name }}
                                    @else
                                        <span class="text-muted">{{ get_phrase('No catalogue association') }}</span>
                                    @endif
                                </td>
                            @elseif($isMixed)
                                <td>
                                    @if($subject->programme)
                                        {{ get_phrase('Programme') }}: {{ $subject->programme->code }} — {{ $subject->programme->name }}
                                    @elseif($subject->classes)
                                        {{ academic_term('class', auth()->user()->school_id) }}: {{ $subject->classes->name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @else
                                <td>{{ $subject->classes->name ?? '—' }}</td>
                            @endif
                            <td class="text-end">
                                <div class="adminTable-action">
                                    <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ get_phrase('Actions for') }} {{ $subject->name }}">{{ get_phrase('Actions') }}</button>
                                    <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                        <li><a class="dropdown-item" href="{{ ($isHigherEducation || $isMixed) ? route('admin.subject.setup', ['id' => $subject->id] + request()->only('curriculum_id')) : 'javascript:;' }}" @if(!$isHigherEducation && !$isMixed) onclick="rightModal('{{ route('admin.edit.subject', ['id' => $subject->id]) }}', '{{ get_phrase('Edit Subject') }}')" @endif>{{ get_phrase('Edit') }}</a></li>
                                        <li><a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.subject.delete', ['id' => $subject->id]) }}', 'undefined');">{{ get_phrase('Delete') }}</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {!! $subjects->links() !!}
    @else
        <div class="text-center py-5" role="status">
            <h5>{{ get_phrase($search || $classId || $programmeId ? 'No matching catalogue records' : 'No catalogue records yet') }}</h5>
            @if($isHigherEducation)
                <p class="text-muted mx-auto" style="max-width: 42rem">{{ get_phrase('Create Course Units here. Select a draft Programme Curriculum and Programme Year in Course Unit setup to define its credits, classification and prerequisites.') }}</p>
            @elseif($isMixed)
                <p class="text-muted">{{ get_phrase('Create a Subject for a Class or a Course Unit for a Programme.') }}</p>
            @else
                <p class="text-muted">{{ get_phrase('Create a Subject and associate it with a Class to make it available in the existing school workflow.') }}</p>
            @endif
            @if($search || $classId || $programmeId)
                <a class="btn btn-outline-secondary" href="{{ route('admin.subject_list') }}">{{ get_phrase('Clear filters') }}</a>
            @else
                <a href="{{ ($isHigherEducation || $isMixed) ? route('admin.subject.setup', request()->only(['curriculum_id', 'programme_id'])) : 'javascript:;' }}" class="eBtn eBtn-primary" @if(!$isHigherEducation && !$isMixed) onclick="rightModal('{{ route('admin.subject.open_modal') }}', '{{ get_phrase($createLabel) }}')" @endif>{{ get_phrase($createLabel) }}</a>
            @endif
        </div>
    @endif
</div>
@endsection
