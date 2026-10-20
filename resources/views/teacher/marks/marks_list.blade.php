<?php

use App\Models\User;
use App\Models\Subject;
use App\Models\Gradebook;
use App\Models\Grade;

?>

<div
  class="att-report-banner d-flex justify-content-center justify-content-md-between align-items-center flex-wrap"
>
  <div class="att-report-summary order-1">
    <h4 class="title">{{ get_phrase('Manage marks') }}</h4>
    <p class="summary-item">{{ get_phrase('Class') }} : <span>{{ $page_data['class_name'] }}</span></p>
    <p class="summary-item">{{ get_phrase('Section') }} : <span>{{ $page_data['section_name'] }}</span></p>
    <p class="summary-item">{{ get_phrase('Subject') }} : <span>{{ $page_data['subject_name'] }}</span>
    </p>
  </div>
  <div class="att-banner-img order-0 order-md-1">
    <img
      src="{{ asset('assets/images/attendance-report-banner.png') }}"
      alt=""
    />
  </div>
</div>

@if(count($enroll_students) > 0)
<div class="export position-relative">
  <button class="eBtn-3 dropdown-toggle float-end mb-4" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
    <span class="pr-10">
      <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77" viewBox="0 0 10.771 12.31">
        <path id="arrow-right-from-bracket-solid" d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z" transform="translate(0 12.31) rotate(-90)" fill="#00a3ff"></path>
      </svg>
    </span>
    {{ get_phrase('Export') }}
  </button>
  <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2">
    <li>
        <a class="dropdown-item" id="pdf" href="javascript:;" onclick="Export()">{{ get_phrase('PDF') }}</a>
    </li>
    <li>
        <a class="dropdown-item" id="print" href="javascript:;" onclick="printableDiv('mark_report')">{{ get_phrase('Print') }}</a>
    </li>
  </ul>
</div>
@endif

@if(count($enroll_students) > 0)
<div class="mark_report" id="mark_report">
    <table class="table eTable">
        <thead>
            <tr>
                <th>{{ get_phrase('Student name') }}</td>
                <th>{{ get_phrase('Mark') }}</td>
                <th>{{ get_phrase('Grade point') }}</td>
                <th>{{ get_phrase('Comment') }}</td>
                <th>{{ get_phrase('Action') }}</td>
            </tr>   
        </thead>
        <tbody>
            @foreach($enroll_students as $enroll_student)
                <?php

                $student_details = User::find($enroll_student->user_id);
                $filterd_data = Gradebook::where('exam_category_id', $page_data['exam_category_id'])
                ->where('class_id', $page_data['class_id'])
                ->where('section_id', $page_data['section_id'])
                ->where('session_id', $page_data['session_id'])
                ->where('student_id', $enroll_student->user_id);

                if($filterd_data->value('marks')){
                    $subject_mark = json_decode($filterd_data->value('marks'), true);
                    if(!empty($subject_mark[$page_data['subject_id']])) {
                        $user_marks = $subject_mark[$page_data['subject_id']];
                    } else {
                        $user_marks = 0;
                    }


                } else {
                    $user_marks = 0;
                }

                if($filterd_data->value('comment')){
                    $comment = $filterd_data->value('comment');
                } else {
                    $comment = '';
                }

                ?>
                <tr>
                    <td>{{ $student_details->name }}</td>
                    <td>
                        <input class="form-control eForm-control" type="number" id="mark-{{ $enroll_student->user_id }}" name="mark" placeholder="mark" min="0" value="{{ $user_marks }}" required onchange="get_grade(this.value, this.id)">
                    </td>
                    <td>
                        <span id="grade-for-mark-{{ $enroll_student->user_id }}">{{ get_grade($user_marks) }}</span> 
                    </td>
                    <td>
                        <input class="form-control eForm-control" type="text" id="comment-{{ $enroll_student->user_id }}" name="comment" placeholder="comment" value="{{ $comment }}" required>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-success" onclick="mark_update('{{ $enroll_student->user_id }}')"><i class="bi bi-check2-circle"></i></button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@else
<div class="empty_box center">
    <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
    <br>
    <span class="">{{ get_phrase('No data found') }}</span>
</div>
@endif

<script type="text/javascript">

  "use strict";


    function mark_update(student_id){
        var class_id = '{{ $page_data['class_id'] }}';
        var section_id = '{{ $page_data['section_id'] }}';
        var subject_id = '{{ $page_data['subject_id'] }}';
        var session_id = '{{ $page_data['session_id'] }}';
        var exam_category_id = '{{ $page_data['exam_category_id'] }}';
        var mark = $('#mark-' + student_id).val();
        var comment = $('#comment-' + student_id).val();
        if(subject_id != ""){
            $.ajax({
                type : 'GET',
                url : "{{ route('update.mark') }}",
                data : {student_id : student_id, class_id : class_id, section_id : section_id, subject_id : subject_id, session_id: session_id, exam_category_id : exam_category_id, mark : mark, comment : comment},
                success : function(response){
                    toastr.success('{{ get_phrase('Value has been updated successfully') }}');
                }
            });
        }else{
            toastr.error('{{ get_phrase('Required mark field') }}');
        }
    }

    function get_grade(exam_mark, id){
        let url = "{{ route('get.grade', ['exam_mark' => ":exam_mark"]) }}";
        url = url.replace(":exam_mark", exam_mark);
        console.log(url);
        $.ajax({
            url : url,
            success : function(response){
                $('#grade-for-'+id).text(response);
            }
        });
    }

    function Export() {
        window.exportReportPdf(document.getElementById('mark_report'), "mark_report.pdf");
    }

    function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

</script>