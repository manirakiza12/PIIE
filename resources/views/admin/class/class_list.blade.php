<?php use App\Models\Section; ?>

@extends('admin.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ academic_term('classes', auth()->user()->school_id) }}</h4>
            @if(academic_education_level(auth()->user()->school_id) === 'tertiary')
            <p class="text-muted">Manage school classes and sections.</p>
            @endif
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="#">{{ get_phrase('Home') }}</a></li>
              <li><a href="#">{{ get_phrase('Academic') }}</a></li>
              <li><a href="#">{{ academic_term('classes', auth()->user()->school_id) }}</a></li>
            </ul>
          </div>
          <div class="export-btn-area">
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.class.open_modal') }}', '{{ get_phrase('Create').' '.academic_term('class', auth()->user()->school_id) }}')">{{ get_phrase('Add').' '.academic_term('class', auth()->user()->school_id) }}</a>
          </div>
        </div>
      </div>
    </div>
</div>
<div class="row">
    <div class="col-12 col-lg-10 offset-lg-1">
        <div class="eSection-wrap">
            <div class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15">
              <form action="{{ route('admin.class_list') }}">
                <div
                  class="search-input d-flex justify-content-start align-items-center"
                >
                  <span>
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="16"
                      height="16"
                      viewBox="0 0 16 16"
                    >
                      <path
                        id="Search_icon"
                        data-name="Search icon"
                        d="M2,7A4.951,4.951,0,0,1,7,2a4.951,4.951,0,0,1,5,5,4.951,4.951,0,0,1-5,5A4.951,4.951,0,0,1,2,7Zm12.3,8.7a.99.99,0,0,0,1.4-1.4l-3.1-3.1A6.847,6.847,0,0,0,14,7,6.957,6.957,0,0,0,7,0,6.957,6.957,0,0,0,0,7a6.957,6.957,0,0,0,7,7,6.847,6.847,0,0,0,4.2-1.4Z"
                        fill="#797c8b"
                      />
                    </svg>
                  </span>
                  <input
                    type="text"
                    id="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="{{ get_phrase('Search').' '.academic_term('classes', auth()->user()->school_id) }}"
                    class="form-control"
                  />
                </div>
              </form>
            </div>
            @if(count($class_lists) > 0)
            <div class="table-responsive tScrollFix pb-2">
                  <table class="table eTable">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">{{ get_phrase('Name') }}</th>
                            <th scope="col">{{ academic_term('section', auth()->user()->school_id) }}</th>
                            <th scope="col" class="text-end">{{ get_phrase('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($class_lists as $key => $class_list)
                             <tr>
                                <td>{{ $class_lists->firstItem() + $key }}</td>
                                <td>{{ $class_list->name }}</td>
                                <td>
                                    <ul>
                                        <?php $sections = Section::get()->where('class_id', $class_list['id']); ?>
                                        @foreach($sections as $section)
                                            <li>{{ $section->name }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="text-start">
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
                                            <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.edit.section', ['id' => $class_list->id]) }}', '{{ get_phrase('Edit').' '.academic_term('section', auth()->user()->school_id) }}')">{{ get_phrase('Edit').' '.academic_term('section', auth()->user()->school_id) }}</a>
                                          </li>
                                          <li>
                                            <a class="dropdown-item" href="{{ route('admin.teacher.permission', ['class_id' => $class_list->id]) }}">{{ get_phrase('Assign').' '.academic_term('teachers', auth()->user()->school_id) }}</a>
                                          </li>
                                          <li>
                                            <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.edit.class', ['id' => $class_list->id]) }}', '{{ get_phrase('Edit').' '.academic_term('class', auth()->user()->school_id) }}')">{{ get_phrase('Edit').' '.academic_term('class', auth()->user()->school_id) }}</a>
                                          </li>
                                          <li>
                                            <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.class.delete', ['id' => $class_list->id]) }}', 'undefined');">{{ get_phrase('Delete') }}</a>
                                          </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {!! $class_lists->appends(request()->all())->links() !!}
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
