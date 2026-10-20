

@extends('admin.navigation')
   
@section('content')

<?php

use App\Models\User;
use App\Models\Section;
use App\Http\Controllers\CommonController;

?>

<style>
    .admit-card::before {
        content: "";
        background: url('{{  asset('assets/uploads/school_logo/'.DB::table('schools')->where('id', auth()->user()->school_id)->value('school_logo') ) }}') no-repeat center;
        background-size: 60%;
        opacity: 0.1;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 0;
    }

        .admitCardPrintBtn {
   
    right: 0 !important;
}
</style>

<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ get_phrase('Print Admit Card') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="#">{{ get_phrase('Home') }}</a></li>
              <li><a href="#">{{ get_phrase('Examination') }}</a></li>
              <li><a href="#">{{ get_phrase('Admit Card') }}</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
</div>


<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
             <div class="row">
                <form action="{{ route('admin.examination.admitCardFilter') }}">
                    <div class="att-filter d-flex flex-wrap">
                        <div class="att-filter-option">
                            <select class="form-select eForm-select eChoice-multiple-with-remove" id = "admit_card_id" name="admit_card_id">
                                <option value="">{{ get_phrase('Select category') }}</option>
                                @foreach ($admit_cards as $admit_card)
                                    <option value="{{ $admit_card->id }}"  {{ $selected_admit_card->id == $admit_card->id ?  'selected':'' }}>{{ $admit_card->template }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="att-filter-option">
                            <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove" onchange="classWiseSection(this.value)" required>
                                <option value="">{{ get_phrase('Select a class') }}</option>
                                <?php foreach($classes as $class): ?>
                                    <option value="{{ $class['id'] }}" {{ $page_data['class_id'] == $class['id'] ?  'selected':'' }}>{{ $class['name'] }}</option>
                                <?php endforeach; ?>
                            </select>
                            </div>

                            <div class="att-filter-option">
                            <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove" required>
                                <option value="">{{ get_phrase('Select a section') }}</option>
                                <?php $sections = Section::where(['class_id' => $page_data['class_id']])->get(); ?>
                                <?php foreach($sections as $section): ?>
                                    <option value="{{ $section['id'] }}" {{ $page_data['section_id'] == $section['id'] ?  'selected':'' }}>{{ $section['name'] }}</option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                        
                        <div class="att-filter-option">
                            <select name="session_id" id="session_id" class="form-select eForm-select eChoice-multiple-with-remove" required>
                                <option value="">{{ get_phrase('Select a session') }}</option>
                                @foreach ($sessions as $session)
                                    <option value="{{ $session->id }}" {{ $page_data['session_id'] == $session['id'] ?  'selected':'' }}>{{ $session->session_title }}</option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="att-filter-btn">
                            <button type="submit" class="eBtn eBtn btn-secondary" onclick="filter_admit_card()">{{ get_phrase('Filter') }}</button>
                        </div>
                    </div>
                </form>
                
                <div class="admitCardPrintBtn_f">
                    <div class="admitCardPrintBtn">
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
                                width="12.31"
                                height="10.77"
                                viewBox="0 0 10.771 12.31"
                            >
                                <path
                                id="arrow-right-from-bracket-solid"
                                d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z"
                                transform="translate(0 12.31) rotate(-90)"
                                fill="#F15F23"
                                />
                            </svg>
                            </span>
                            {{ get_phrase('Export') }}
                        </button>
                        <ul
                            class="dropdown-menu dropdown-menu-end eDropdown-menu-2"
                        >
                            <li>
                                <a class="dropdown-item" id="pdf" href="javascript:;" onclick="Export()">{{ get_phrase('PDF') }}</a>
                            </li>
                            <li>
                                <a class="dropdown-item" id="print" href="javascript:;" onclick="printableDiv('admit_card_body')">{{ get_phrase('Print') }}</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="admit_card_body" id="admit_card_body">
                    @foreach($enroll_students as $enroll_student)

                    <?php
                            $id = $enroll_student->user_id;

                            $student_details = (new CommonController)->get_student_details_by_id($id);

                    ?>
                        <div class="admit-card">
                            <div class="d-flex justify-content-between align-items-center">
                            @if(empty($school_data->school_logo))
                                <img class="header-logo" src="{{ asset('assets/uploads/school_logo/'.DB::table('schools')->where('id', auth()->user()->school_id)->value('school_logo') ) }}">
                            @else
                                <img class="header-logo" src="{{ asset('assets') }}/images/id_logo.png">
                            @endif
                                
                                <h3>{{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}</h3>
                                @if(empty($school_data->school_logo))
                                <img class="header-logo" src="{{ asset('assets/uploads/school_logo/'.DB::table('schools')->where('id', auth()->user()->school_id)->value('school_logo') ) }}">
                            @else
                                <img class="header-logo" src="{{ asset('assets') }}/images/id_logo.png">
                            @endif
                            </div>
                            <h4 class="mt-3">{{ $selected_admit_card->heading }}</h4>
                            <p class="mt-3 mb-3"><strong>{{ $selected_admit_card->title }}</strong></p>

                            <div class="d-flex justify-content-between">
                                <table class="table table-borderless info-table">
                                    <tr>
                                        <td><strong>ROLL NUMBER</strong></td>
                                        <td>{{$student_details->code}}</td>
                                        <td><strong>{{get_phrase('CLASS')}}</strong></td>   
                                        @if(empty($student_details->class_name))
                                        <td>{{get_phrase('Removed')}} </td>
                                        @else
                                        <td>{{ null_checker($student_details->class_name) }} </td>
                                        @endif
                                    </tr>
                                    <tr>
                                        <td><strong>CANDIDATE'S NAME</strong></td>
                                        <td>{{$student_details->name}}</td>
                                        <td><strong>{{get_phrase('SECTION')}}</strong></td>
                                        @if(empty($student_details->section_name))
                                        <td>{{get_phrase('Removed')}} </td>
                                        @else
                                        <td>{{ null_checker($student_details->section_name) }} </td>
                                        @endif
                                    </tr>
                                    <tr>
                                        <td><strong>D.O.B</strong></td>
                                        <td>{{ date('d M Y',$student_details->birthday) }}</td>
                                        
                                        <td><strong>GENDER</strong></td>
                                        <td>{{ null_checker($student_details->gender) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>PARENTS NAME</strong></td>
                                        <td>{{ null_checker($student_details->parent_name) }}</td>
                                        <td><strong>CONTACT</strong></td>
                                        <td>{{ null_checker($student_details->phone) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>ADDRESS</strong></td>
                                        <td colspan="3">{{ null_checker($student_details->address) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>SCHOOL NAME</strong></td>
                                        <td>{{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}</td>
                                        <td><strong>EXAM CENTER</strong></td>
                                        <td>{{ $selected_admit_card->exam_center }}</td>
                                    </tr>
                                </table>
                                <div class="student-image ml-3"><img src="{{ $student_details->photo }}" alt=""></div>
                            </div>

                            <div class="signature">
                            @if($selected_admit_card->sign)
                                <img src="{{ asset('assets/upload/user-docs/' . $selected_admit_card->sign) }}" alt="Signature" style="width: 150px; height: auto;">
                            @else
                                <p>No signature uploaded.</p>
                            @endif
                                <p>_________________________</p>
                                <p>Signature</p>
                            </div>
                        </div>

                    <br>
                    @endforeach
                </div>
                    <div class="admin-tInfo-pagi d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15">
                        <p class="admin-tInfo">{{ get_phrase('Showing').' 1 - '.count($enroll_students).' '.get_phrase('from').' '.$enroll_students->total().' '.get_phrase('data') }}</p>
                        <div class="admin-pagi">
                        {!! $enroll_students->appends(request()->all())->links() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
      "use strict";

    function Export() {
    const element = document.getElementById("admit_card_body");
    var opt = {
    margin:       1,
    filename:     'admit_card_' + new Date().toISOString().slice(0, 10) + '.pdf',
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2 }
    };

    window.exportReportPdf(element, opt.filename);
    }


    function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

    function classWiseSection(classId) {
        let url = "{{ route('admin.class_wise_sections', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#section_id').html(response);
                classWiseSubect(classId);
            }
        });
    }

    function classWiseSubect(classId) {
        let url = "{{ route('admin.class_wise_subject', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#subject_id').html(response);
            }
        });
    }

    function filter_admit_card(){
        var admit_card_id = $('#admit_card_id').val();
        var class_id = $('#class_id').val();
        var section_id = $('#section_id').val();
        var session_id = $('#session_id').val();
        if(admit_card_id != "" &&  class_id != "" && section_id != "" && session_id != ""){
            getFilteredAdmitCard();
        }else{
            toastr.error('{{ get_phrase('Please select all the fields') }}');
        }
    }

    var getFilteredAdmitCard = function() {
        var admit_card_id = $('#admit_card_id').val();
        var class_id = $('#class_id').val();
        var section_id = $('#section_id').val();
        var session_id = $('#session_id').val();
        if(admit_card_id != "" &&  class_id != "" && section_id!= "" && session_id != ""){
            let url = "{{ route('admin.examination.admitCardFilter') }}";
            $.ajax({
                url: url,
                headers: {
                    'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
                },
                data: {admit_card_id: admit_card_id, class_id : class_id, section_id : section_id, session_id: session_id},
                
            });
        }
    }

</script>

@endsection


