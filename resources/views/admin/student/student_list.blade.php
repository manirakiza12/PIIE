@extends('admin.navigation')
   
@section('content')

<?php 

use App\Http\Controllers\CommonController;
use App\Models\School;
use App\Models\Section;

$user = Auth()->user();
$menu_permission = (empty($user->menu_permission) || $user->menu_permission == 'null') ? []:json_decode($user->menu_permission, true);
?>

<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ get_phrase('Students') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="#">{{ get_phrase('Home') }}</a></li>
              <li><a href="#">{{ get_phrase('Users') }}</a></li>
              <li><a href="#">{{ get_phrase('Students') }}</a></li>
            </ul>
          </div>
          <div class="export-btn-area d-flex gap-2">
            <a href="{{ route('admin.student.export', ['search' => $search, 'class_id' => $class_id, 'section_id' => $section_id]) }}" class="export_btn bg-secondary"><i class="bi bi-download"></i> {{ get_phrase('Export CSV') }}</a>
            <a href="{{ route('admin.student.export_excel', ['search' => $search, 'class_id' => $class_id, 'section_id' => $section_id]) }}" class="export_btn bg-secondary"><i class="bi bi-file-earmark-excel"></i> {{ get_phrase('Export Excel') }}</a>
            @if(empty($user->menu_permission) || in_array('admin.offline_admission.single', $menu_permission))
            <a href="{{ route('admin.hei_admissions.wizard.create') }}" class="export_btn">{{ get_phrase('Add Application') }}</a>
            <a href="{{ route('admin.student.open_modal') }}" class="export_btn">{{ get_phrase('Add Existing Student') }}</a>
            <a href="{{ route('admin.student.import.index') }}" class="export_btn">{{ get_phrase('Import Existing Students') }}</a>
            @endif
            @if(academic_education_level($user->school_id) === 'tertiary' && $user->hasPermission('academic.programme_cohort.membership'))
            <a href="{{ route('admin.academic.direct_placement') }}" class="export_btn">{{ get_phrase('Place Student in Programme') }}</a>
            @endif
          </div>
        </div>
      </div>
    </div>
</div>
<!-- Start Students area -->
<div class="row">
    <div class="col-12">
        <div class="eSection-wrap-2">
          <!-- Search and filter -->
            <div
              class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15"
            >
              <form action="{{ route('admin.student') }}">
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
                    placeholder="Search Students"
                    class="form-control"
                  />
                  @if($class_id != '')
                  <input type="hidden" name="class_id" id="class_id" value="{{ $class_id }}">
                  @endif
                  @if($section_id != '')
                  <input type="hidden" name="section_id" id="section_id" value="{{ $section_id }}">
                  @endif
                </div>
              </form>
              <div class="filter-export-area d-flex align-items-center">
                <div class="position-relative">
                  <button
                    class="eBtn-3 dropdown-toggle"
                    type="button"
                    id="defaultDropdown"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="true"
                    aria-expanded="false"
                  >
                    <span class="pr-10">
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="14.028"
                        height="12.276"
                        viewBox="0 0 14.028 12.276"
                      >
                        <path
                          id="filter-solid"
                          d="M.106,32.627A1.1,1.1,0,0,1,1.1,32H12.934a1.092,1.092,0,0,1,.989.627,1.054,1.054,0,0,1-.164,1.164l-4.99,6.126V43.4a.877.877,0,0,1-1.4.7L5.612,42.786a.871.871,0,0,1-.351-.7V39.917L.248,33.79a1.1,1.1,0,0,1-.142-1.164Z"
                          transform="translate(0 -32)"
                          fill="#F15F23"
                        />
                      </svg>
                    </span>
                    {{ get_phrase('Filter') }}
                  </button>
                  <div
                    class="dropdown-menu dropdown-menu-end filter-options"
                    aria-labelledby="defaultDropdown"
                  >
                    <h4 class="title">{{ get_phrase('Filter Options') }}</h4>
                    <form action="{{ route('admin.student') }}">
                      <div class="filter-option d-flex flex-column">
                        @if($search != '')
                        <input type="hidden" name="search" id="search" value="{{ $search }}">
                        @endif
                        <div>
                          <label for="class_id" class="eForm-label"
                            >{{ academic_term('class', auth()->user()->school_id) }}</label
                          >
                          <select
                            class="form-select" name="class_id"
                            id="class_id" onchange="classWiseSection(this.value)" required
                          >
                            <option value="">{{ get_phrase('Select a class') }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $class_id == $class->id ?  'selected':'' }}>{{ $class->name }}</option>
                            @endforeach
                          </select>
                        </div>
                        <div>
                          <label for="section_id" class="eForm-label"
                            >{{ academic_term('section', auth()->user()->school_id) }}</label
                          >
                          <select
                            class="form-select"
                            name="section_id" id="section_id"
                            data-placeholder="Type to search..."
                          >
                            <?php if($class_id !=""){
                                $sections = Section::get()->where('class_id', $class_id); ?>
                                @foreach($sections as $section)
                                    <option value="{{ $section->id }}" {{ $section_id == $section->id ?  'selected':'' }}>{{ $section->name }}</option>
                                @endforeach
                            <?php } else { ?>
                                <option value="">{{ get_phrase('First select a class') }}</option>
                            <?php } ?>
                          </select>
                        </div>
                      </div>
                      <div
                        class="filter-button d-flex justify-content-end align-items-center"
                      >
                        <a class="form-group">
                          <button class="eBtn eBtn btn-primary" type="submit">{{ get_phrase('Apply') }}</button>
                        </a>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            @if(count($students) > 0)
            <!-- Table -->
            <div class="table-responsive">
              <table class="table eTable eTable-2">
                <thead>
                  <tr>
                    <th scope="col">#</th>
                    <th scope="col">{{ get_phrase('Name') }}</th>
                    <th scope="col">{{ get_phrase('Email') }}</th>
                    <th scope="col">{{ get_phrase('User Info') }}</th>
                    <th scope="col">{{ get_phrase('Account Status') }}</th>
                    <th scope="col">{{ get_phrase('Options') }}</th>
                </thead>
                <tbody>
                    @foreach($students as $key => $user)
                    <?php 

                        $student = DB::table('users')->where('id', $user->user_id)->first();

                        $user_image = get_user_image($user->user_id);
                        $info = (object) array_merge(['phone' => null, 'address' => null], (array) (json_decode($student->user_information ?? '') ?: []));

                        $student_details = (new CommonController)->get_student_academic_info($student->id);
                    ?>
                      <tr>
                        <th scope="row">
                          <p class="row-number">{{ $students->firstItem() + $key }}</p>
                        </th>
                        <td>
                          <div
                            class="dAdmin_profile d-flex align-items-center min-w-200px"
                          >
                            <div class="dAdmin_profile_img">
                              <img
                                class="img-fluid"
                                width="50"
                                height="50"
                                src="{{ $user_image }}"
                              />
                            </div>
                            <div class="dAdmin_profile_name dAdmin_info_name">
                              <h4>{{ $student->name }}</h4>
                              <p>
                                @if(!empty($student_details->class_name))
                                <span>{{ academic_term('class', auth()->user()->school_id) }}:</span> {{ $student_details->class_name }}
                                <br>
                                <span>{{ academic_term('section', auth()->user()->school_id) }}:</span> {{ $student_details->section_name }}
                                @elseif(!empty($student_details->programme_name))
                                <span>{{ academic_term('programme', auth()->user()->school_id) }}:</span> {{ $student_details->programme_name }}
                                @else
                                <span>{{ academic_term('class', auth()->user()->school_id) }}:</span>
                                 {{ get_phrase('Removed') }}
                                 <br>
                                <span>{{ academic_term('section', auth()->user()->school_id) }}:</span>
                                {{ get_phrase('Removed') }}
                                @endif
                              </p>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="dAdmin_info_name min-w-250px">
                            <p>{{ $student->email }}</p>
                          </div>
                        </td>
                        <td>
                          <div class="dAdmin_info_name min-w-250px">
                            <p><span>{{ get_phrase('Phone') }}:</span> {{ $info->phone }}</p>
                            <p>
                              <span>{{ get_phrase('Address') }}:</span> {{ $info->address }}
                            </p>
                          </div>
                        </td>
                        <td>
                          <div class="dAdmin_info_name min-w-100px">
                            @if(!empty($student->account_status == 'disable'))
                            <span class="eBadge ebg-soft-danger">{{get_phrase('Disabled')}}</span>
                            @else
                            <span class="eBadge ebg-soft-success">{{get_phrase('Enable')}}</span>
                            @endif
                          </div>
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
                                <a class="dropdown-item" href="javascript:;" onclick="largeModal('{{ route('admin.student.id_card', ['id' => $student->id]) }}', '{{ get_phrase('Generate id card') }}')">{{ get_phrase('Generate Id card') }}</a>
                              </li>

                              <li>
                                <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.student_edit_modal', ['id' => $student->id]) }}', 'Edit / Complete details')">{{ get_phrase('Edit / Complete details') }}</a>
                              </li>
                              @if(auth()->user()->hasPermission('finance.invoices'))
                              <li><a class="dropdown-item" href="{{ route('admin.student.invoice_setup', ['id' => $student->id]) }}">{{ get_phrase('Set up invoice') }}</a></li>
                              @endif
                              @if(auth()->user()->hasPermission('students.delete'))
                              <li>
                                <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteStudent{{ $student->id }}">{{ get_phrase('Permanently delete') }}</button>
                              </li>
                              @endif
                              <li>
                                <a class="dropdown-item" href="javascript:;" onclick="largeModal('{{ route('admin.student.student_profile', ['id' => $student->id]) }}','{{ get_phrase('Student Profile') }}')">{{ get_phrase('Profile') }}</a>
                              </li>
                              <li>
                                <a class="dropdown-item" href="{{ route('admin.student.documents', ['id' => $student->id]) }}">{{ get_phrase('Documents') }}</a>
                                @if(auth()->user()->hasPermission('academic.programme_cohort.membership'))
                                    <a class="dropdown-item" href="{{ route('admin.academic.placements.index', ['student_id' => $student->id]) }}">Placement Status</a>
                                @endif
                              </li>
                              <li>
                                <a class="dropdown-item" href="{{ route('admin.transcripts.show', ['id' => $student->id]) }}" target="_blank">{{ get_phrase('Generate Transcript') }}</a>
                              </li>
                              <li>
                                <a class="dropdown-item" href="{{ route('admin.student.profile_pdf', ['id' => $student->id, 'inline' => 1]) }}" target="_blank">{{ get_phrase('Print Profile') }}</a>
                              </li>
                              <li>
                                <a class="dropdown-item" href="{{ route('admin.student.profile_pdf', ['id' => $student->id]) }}">{{ get_phrase('Export PDF') }}</a>
                              </li>
                              <li>
                                <a class="dropdown-item" href="{{ route('admin.student.profile_excel', ['id' => $student->id]) }}">{{ get_phrase('Export Excel') }}</a>
                              </li>
                              <li>
                                <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.student.reset_password', ['id' => $student->id]) }}', 'undefined');">{{ get_phrase('Reset Password') }}</a>
                              </li>
                              <li><a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.student.resend_activation', ['id' => $student->id]) }}', 'undefined');">{{ get_phrase('Send Portal Activation') }}</a>
                              </li>
                              @if(!empty($student->account_status == 'disable'))
                              <li>
                                <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.account_enable', ['id' => $student->id]) }}', 'undefined');">{{ get_phrase('Enable') }}</a>
                              </li>
                              @else
                              <li>
                                <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.account_disable', ['id' => $student->id]) }}', 'undefined');">{{ get_phrase('Disable') }}</a>
                              </li>
                              @endif
                            </ul>
                          </div>
                          @if(auth()->user()->hasPermission('students.delete'))
                          <div class="modal fade" id="deleteStudent{{ $student->id }}" tabindex="-1" aria-labelledby="deleteStudentLabel{{ $student->id }}" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                              <div class="modal-content">
                                <form method="POST" action="{{ route('admin.student.delete', ['id' => $student->id]) }}">
                                  @csrf
                                  @method('DELETE')
                                  <div class="modal-header">
                                    <h5 class="modal-title text-danger" id="deleteStudentLabel{{ $student->id }}">{{ get_phrase('Permanently delete student') }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ get_phrase('Close') }}"></button>
                                  </div>
                                  <div class="modal-body text-start">
                                    <p><strong>{{ get_phrase('This permanently deletes the student account and their associated academic, attendance, assessment, library, hostel, messaging, and other account records. This cannot be undone.') }}</strong></p>
                                    <p>{{ get_phrase('Financial invoices and transaction history that must be retained for reconciliation will remain under the documented finance retention rules.') }}</p>
                                    <label class="form-label" for="deleteConfirmation{{ $student->id }}">{{ get_phrase('Type DELETE to confirm') }}</label>
                                    <input class="form-control" id="deleteConfirmation{{ $student->id }}" name="confirmation" type="text" required pattern="DELETE" autocomplete="off">
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ get_phrase('Cancel') }}</button>
                                    <button type="submit" class="btn btn-danger">{{ get_phrase('Permanently delete student') }}</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                          </div>
                          @endif
                        </td>
                      </tr>
                    @endforeach
                </tbody>
              </table>
              
              <div
                  class="admin-tInfo-pagi d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15"
                >
                  <p class="admin-tInfo">{{ get_phrase('Showing').' 1 - '.count($students).' '.get_phrase('from').' '.$students->total().' '.get_phrase('data') }}</p>
                  <div class="admin-pagi">
                    {!! $students->appends(request()->all())->links() !!}
                  </div>
                </div>
              </div>
              
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

<script type="text/javascript">

  "use strict";

  function classWiseSection(classId) {
    let url = "{{ route('class_wise_sections', ['id' => ":classId"]) }}";
    url = url.replace(":classId", classId);
    $.ajax({
        url: url,
        success: function(response){
            $('#section_id').html(response);
        }
    });
  }

</script>


<!-- End Students area -->
@endsection
