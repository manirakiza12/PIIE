@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ get_phrase('Designations') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Home') }}</a></li>
              <li><a href="{{ route('admin.rbac.staff.index') }}">{{ get_phrase('Staff') }}</a></li>
              <li><span>{{ get_phrase('Designations') }}</span></li>
            </ul>
          </div>
          <div class="export-btn-area">
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.designation.open_modal') }}', '{{ get_phrase('Create Designation') }}')"><i class="bi bi-plus"></i>{{ get_phrase('Add designation') }}</a>
          </div>
        </div>
      </div>
    </div>
</div>

<div class="row">
    <div class="col-7 offset-md-2">
        <div class="eSection-wrap">
            <div class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15">
              <form action="{{ route('admin.designation_list') }}">
                <div
                  class="search-input d-flex justify-content-start align-items-center"
                >
                  <input
                    type="text"
                    id="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search Designation"
                    class="form-control"
                  />
                </div>
              </form>
            </div>
            @if(count($designations) > 0)
            <div class="table-responsive tScrollFix pb-2">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ get_phrase('Name') }}</th>
                            <th>{{ get_phrase('Staff Using') }}</th>
                            <th class="text-end">{{ get_phrase('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($designations as $key => $designation)
                            @php
                                $inUse = (int) ($usage[$designation->id] ?? 0);
                                $inUseTitle = str_replace(':count', (string) $inUse,
                                    get_phrase('Used by :count staff member(s). Reassign them to another designation before deleting this one.'));
                            @endphp
                             <tr>
                                <td>{{ $designations->firstItem() + $key }}</td>
                                <td>{{ $designation->name }}</td>
                                <td>
                                    @if($inUse > 0)
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $inUse }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="adminTable-action">
                                        <button
                                          type="button"
                                          class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
                                          data-bs-toggle="dropdown"
                                          aria-expanded="false"
                                        >
                                          {{ get_phrase('Actions') }}
                                        </button>
                                        <ul
                                          class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action"
                                        >
                                          <li>
                                            <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.edit.designation', ['id' => $designation->id]) }}', '{{ get_phrase('Edit Designation') }}')">Edit</a>
                                          </li>
                                          <li>
                                            @if($inUse > 0)
                                                {{-- Referenced by staff: never a hard delete. --}}
                                                <span class="dropdown-item disabled" aria-disabled="true" title="{{ $inUseTitle }}">
                                                    {{ get_phrase('In use — cannot delete') }}
                                                </span>
                                            @else
                                                <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.designation.delete', ['id' => $designation->id]) }}', 'undefined');">{{ get_phrase('Delete') }}</a>
                                            @endif
                                          </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {!! $designations->appends(request()->all())->links() !!}
            </div>
            @else
            <div class="empty_box center">
              <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
              <br>
              <span class="">{{ get_phrase('No data found') }}</span>
            </div>
            @endif
        </div>
    </div>
</div>

@endsection
