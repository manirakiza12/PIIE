<?php
use App\Models\User;
use App\Models\Role;
use App\Http\Controllers\CommonController;
use App\Models\DailyAttendances;
?>

<style>
      .table_cap{
    caption-side: top;

  }
</style>

<?php

if(count($attendance_of_students) > 0): ?>
<div class="row">
    <div class="col-md-4"></div>
    <div class="col-md-4">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <div class="text-center">
                    <h4>
                        {{ get_phrase('Attendance report').' '.get_phrase('Of').' '.date('F', $page_data['attendance_date']) }}
                    </h4>


                    <h5>
                        {{ get_phrase('Name') }} :

                        {{ $userName['name'] }}
                    </h5>

                    <h5>
                        {{ get_phrase('Last updated at') }} :
                        <?php
                        if ($attendance_of_students[0]['timestamp'] == ""): ?>
                        {{ get_phrase('Not updated yet') }}
                        <?php else: ?>
                        {{ date('d-M-Y', $attendance_of_students[0]['timestamp']) }} <br>

                        <?php endif; ?>
                    </h5>
                </div>
            </div> <!-- end card-body-->
        </div>
    </div>
    <div class="col-md-4"></div>
</div>



<div class="table-responsive">

    <table class="table table-bordered table-responsive-sm table-responsive-md table-responsive-lg table-responsive-xl table-sm" id="attendence_report">
        <caption class="table_cap">
            <h4>{{ get_phrase('Attendence Report') }} </h4>
          </caption>

        <thead class="thead-dark">
            <tr>
                <th width="40px">

                {{ get_phrase('Date') }} <i class="mdi mdi-arrow-right"></i>
                </th>
                <?php
                $number_of_days = date('m', $page_data['attendance_date']) == 2 ? (date('Y', $page_data['attendance_date']) % 4 ? 28 : (date('m', $page_data['attendance_date']) % 100 ? 29 : (date('m', $page_data['attendance_date']) % 400 ? 28 : 29))) : ((date('m', $page_data['attendance_date']) - 1) % 7 % 2 ? 30 : 31);
                for ($i = 1; $i <= $number_of_days; $i++): ?>
                <th>
                    {{ $i }}
                </th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $student_id_count = 0;
            foreach($attendance_of_students as $attendance_of_student){ ?>
            <?php $user_details = (new CommonController)->get_user_by_id_from_user_table($attendance_of_student['student_id']); ?>
            <?php if(date('m', $page_data['attendance_date']) == date('m', $attendance_of_student['timestamp'])): ?>
            <?php if($student_id_count != $attendance_of_student['student_id']): ?>
            <tr>
                <td>
                    {{ $user_details['name'] }}
                </td>
                <?php for ($i = 1; $i <= $number_of_days; $i++): ?>
                <?php $page_data['date'] = $i.' '.$page_data['month'].' '.$page_data['year']; ?>
                <?php $timestamp = strtotime($page_data['date']); ?>
                <td class="text-center">
                    <?php $attendance_by_id = DailyAttendances::where([ 'student_id' => $attendance_of_student['student_id'], 'school_id' => auth()->user()->school_id, 'timestamp' => $timestamp])->first();  ?>
                    <?php if(isset($attendance_by_id->status) && $attendance_by_id->status == 1){ ?>
                    <i class=" text-success"> <b>P</b>  </i>
                    <?php }elseif(isset($attendance_by_id->status) && $attendance_by_id->status == 0){ ?>
                    <i class=" text-danger"> <b>A</b> </i>
                    <?php } ?>
                </td>
                <?php endfor; ?>
            </tr>
            <?php endif; ?>
            <?php $student_id_count = $attendance_of_student['student_id']; ?>
            <?php endif; ?>
            <?php } ?>
        </tbody>
    </table>
    <button class="btn btn-custom" onclick="Export()">{{ get_phrase('PDF') }} </button>

</div>

<?php else: ?>

<div class="empty_box text-center">
    <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
    <br>
    <span class="">
        {{ get_phrase('No data found') }}
    </span>
</div>
<?php endif; ?>



<script type="text/javascript">
    
    "use strict";

    var specialElementHandlers = {
        // element with id of "bypass" - jQuery style selector
        '.no-export': function (element, renderer) {
            // true = "handled elsewhere, bypass text extraction"
            return true;
        }
    };

    function Export() {
        window.exportReportPdf(document.getElementById('attendence_report'), "attendence_report.pdf");
    }
</script>
