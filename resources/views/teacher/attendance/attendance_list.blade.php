@extends('teacher.navigation')

@section('content')
    <?php
    
    use App\Http\Controllers\CommonController;
    
    use App\Models\Classes;
    use App\Models\Section;
    use App\Models\DailyAttendances;
    use App\Models\Session;
    
    $class_name = Classes::find($page_data['class_id'])->name;
    $section_name = Section::find($page_data['section_id'])->name;
    $active_session = Session::where('status', 1)
        ->where('school_id', auth()->user()->school_id)
        ->first();
    $student_id_count = 0;
    
    ?>

    <style>
        .custom_cs {
            padding: 0.375rem 5.75rem;

        }

        .att-custom_div {

            background-color: white !important;

        }

        .att-date {
            display: flex;
            gap: 29px;
        }

        .att-wDay {
            display: flex;
            gap: 29px;

        }

        .att-count-stu {
            display: flex;

            gap: 33px;
        }

        .att-count-stu div {
            min-width: 16px;
        }
    </style>
    <div class="mainSection-title">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                    <div class="d-flex flex-column">
                        <h4>{{ get_phrase('Daily Attendance') }}</h4>
                        <ul class="d-flex align-items-center eBreadcrumb-2">
                            <li><a href="#">{{ get_phrase('Home') }}</a></li>
                            <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                            <li><a href="#">{{ get_phrase('Daily Attendance') }}</a></li>
                        </ul>
                    </div>
                    <div class="export-btn-area">
                        <a href="#" class="export_btn" onclick="rightModal('{{ route('teacher.take_attendance.open_modal') }}', '{{ get_phrase('Take Attendance') }}')">{{ get_phrase('Take Attendance') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="eSection-wrap-2">
                <!-- Filter area -->
                <form method="GET" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('teacher.daily_attendance.filter') }}">
                    <div class="att-filter d-flex flex-wrap">
                        <div class="att-filter-option">
                            <select name="month" id="month" class="form-select eForm-select eChoice-multiple-with-remove" required>
                                <option value="">{{ get_phrase('Select a month') }}</option>
                                <option value="Jan"{{ $page_data['month'] == 'Jan' ? 'selected' : '' }}>{{ get_phrase('January') }}</option>
                                <option value="Feb"{{ $page_data['month'] == 'Feb' ? 'selected' : '' }}>{{ get_phrase('February') }}</option>
                                <option value="Mar"{{ $page_data['month'] == 'Mar' ? 'selected' : '' }}>{{ get_phrase('March') }}</option>
                                <option value="Apr"{{ $page_data['month'] == 'Apr' ? 'selected' : '' }}>{{ get_phrase('April') }}</option>
                                <option value="May"{{ $page_data['month'] == 'May' ? 'selected' : '' }}>{{ get_phrase('May') }}</option>
                                <option value="Jun"{{ $page_data['month'] == 'Jun' ? 'selected' : '' }}>{{ get_phrase('June') }}</option>
                                <option value="Jul"{{ $page_data['month'] == 'Jul' ? 'selected' : '' }}>{{ get_phrase('July') }}</option>
                                <option value="Aug"{{ $page_data['month'] == 'Aug' ? 'selected' : '' }}>{{ get_phrase('August') }}</option>
                                <option value="Sep"{{ $page_data['month'] == 'Sep' ? 'selected' : '' }}>{{ get_phrase('September') }}</option>
                                <option value="Oct"{{ $page_data['month'] == 'Oct' ? 'selected' : '' }}>{{ get_phrase('October') }}</option>
                                <option value="Nov"{{ $page_data['month'] == 'Nov' ? 'selected' : '' }}>{{ get_phrase('November') }}</option>
                                <option value="Dec"{{ $page_data['month'] == 'Dec' ? 'selected' : '' }}>{{ get_phrase('December') }}</option>
                            </select>
                        </div>
                        <div class="att-filter-option">
                            <select name="year" id="year" class="form-select eForm-select eChoice-multiple-with-remove" required>
                                <option value="">{{ get_phrase('Select a year') }}</option>
                                <?php for($year = 2015; $year <= date('Y'); $year++){ ?>
                                <option value="{{ $year }}"{{ $page_data['year'] == $year ? 'selected' : '' }}>{{ $year }}</option>
                                <?php } ?>

                            </select>
                        </div>
                        <div class="att-filter-option">
                            <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove" onchange="classWiseSection(this.value)" required>
                                <option value="">{{ get_phrase('Select a class') }}</option>
                                <?php foreach($classes as $class): ?>
                                <option value="{{ $class['id'] }}" {{ $page_data['class_id'] == $class['id'] ? 'selected' : '' }}>{{ $class['name'] }}</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="att-filter-option">
                            <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove" required>
                                <option value="">{{ get_phrase('Select a section') }}</option>
                                <?php $sections = Section::where(['class_id' => $page_data['class_id']])->get(); ?>
                                <?php foreach($sections as $section): ?>
                                <option value="{{ $section['id'] }}" {{ $page_data['section_id'] == $section['id'] ? 'selected' : '' }}>{{ $section['name'] }}</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="att-filter-btn">
                            <button class="eBtn eBtn btn-secondary" type="submit">{{ get_phrase('Filter') }}</button>
                        </div>
                        @if (count($attendance_of_students) > 0)
                            <div class="position-relative">
                                <button class="eBtn-3 dropdown-toggle" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                    <span class="pr-10">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77" viewBox="0 0 10.771 12.31">
                                            <path id="arrow-right-from-bracket-solid"
                                                d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z"
                                                transform="translate(0 12.31) rotate(-90)" fill="#00a3ff" />
                                        </svg>
                                    </span>
                                    {{ get_phrase('Export') }}
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2">
                                    <li>
                                        <button class="dropdown-item" href="#" onclick="download_csv()">{{ get_phrase('CSV') }}</button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item" href="#" onclick="Export()">{{ get_phrase('PDF') }}</button>
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </div>
                </form>
                <!-- Attendance banner -->
                @if (count($attendance_of_students) > 0)
                    <div class="att-report-banner d-flex justify-content-center justify-content-md-between align-items-center flex-wrap">
                        <div class="att-report-summary order-1">
                            <h4 class="title">{{ get_phrase('Attendance Report Of') . ' ' . date('F', $page_data['attendance_date']) . ', ' . date('Y', $page_data['attendance_date']) }}</h4>
                            <p class="summary-item">{{ get_phrase('Class') }}: <span>{{ $class_name }}</span></p>
                            <p class="summary-item">{{ get_phrase('Section') }}: <span>{{ $section_name }}</span></p>
                            <p class="summary-item">
                                {{ get_phrase('Last Update at') }}:
                                <?php $last_row = array_slice($attendance_of_students, -1, 1, true);
                                
                                foreach ($last_row as $row) {
                                    $last_row = $row['updated_at'];
                                }
                                
                                ?>
                                <span>
                                    <?php if ($last_row==""): ?>
                                    {{ get_phrase('Not updated yet') }}
                                    <?php else: ?>
                                    {{ date('d-M-Y', strtotime($last_row)) }} <br>
                                    <?php endif; ?>
                                </span>
                            </p>
                            <p class="summary-item">{{ get_phrase('Time') }}:
                                <span>
                                    <?php if ($last_row==""): ?>
                                    {{ get_phrase('Not updated yet') }}
                                    <?php else: ?>
                                    {{ date('h:i:s', strtotime($last_row)) }}
                                    <?php endif; ?>
                                </span>
                            </p>
                        </div>
                        <div class="att-banner-img order-0 order-md-1">
                            <img src="{{ asset('assets/images/attendance-report-banner.png') }}" alt="" />
                        </div>
                    </div>
                    <!-- Attendance table -->
                    <div class="att-table" id="pdf_table">
                        <div class="att-title">
                            <h4 class="att-title-header"> {{ ucfirst('Student') }} / {{ get_phrase('Date') }}</h4>
                            <ul class="att-stuName-items">
                                <?php foreach(array_slice($attendance_of_students, 0, $no_of_users) as $attendance_of_student ):  ?>
                                <?php $user_details = (new CommonController)->get_user_by_id_from_user_table($attendance_of_student['student_id'])->toArray(); ?>
                                <?php if(date('m', (int)$page_data['attendance_date']) == date('m', $attendance_of_student['timestamp'])): ?>
                                <?php if($student_id_count != $attendance_of_student['student_id']): ?>
                                <li class="att-stuName-item">
                                    <a href="#"> {{ $user_details['name'] }}</a>
                                </li>
                                <?php endif; ?>
                                <?php $student_id_count = $attendance_of_student['student_id']; ?>
                                <?php endif; ?>
                                <?php endforeach;  ?>
                            </ul>
                        </div>
                        <div class="att-content">
                            <div class="att-dayWeek">
                                <div class="att-wDay d-flex">
                                    <?php
                                                    $number_of_days = date('m', $page_data['attendance_date']) == 2 ? (date('Y', $page_data['attendance_date']) % 4 ? 28 : (date('m', $page_data['attendance_date']) % 100 ? 29 : (date('m', $page_data['attendance_date']) % 400 ? 28 : 29))) : ((date('m', $page_data['attendance_date']) - 1) % 7 % 2 ? 30 : 31);
                                                    $month_year='-'.date('m', $page_data['attendance_date']).'-'.date('Y', $page_data['attendance_date']);
                                    
                                                    for ($i = 1; $i <= $number_of_days; $i++):
                                                      $weekname=$i.$month_year;
                                    
                                                      $day=date("l", strtotime($weekname));?>

                                    <div>
                                        <p>{{ substr($day, 0, 1) }}</p>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="att-date d-flex">
                                    <?php
                                                    $number_of_days = date('m', $page_data['attendance_date']) == 2 ? (date('Y', $page_data['attendance_date']) % 4 ? 28 : (date('m', $page_data['attendance_date']) % 100 ? 29 : (date('m', $page_data['attendance_date']) % 400 ? 28 : 29))) : ((date('m', $page_data['attendance_date']) - 1) % 7 % 2 ? 30 : 31);
                                                    for ($i = 1; $i <= $number_of_days; $i++): ?>
                                    <div>
                                        <p>{{ $i }}</p>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <ul class="att-count-items">
                                <?php
                                
                                              $student_id_count = 0;
                                
                                              foreach(array_slice($attendance_of_students, 0, $no_of_users) as $attendance_of_student )     :  ?>
                                <li class="att-count-item">
                                    <div class="att-count-stu d-flex">
                                        <?php
                                                          $user_details = (new CommonController)->get_user_by_id_from_user_table($attendance_of_student['student_id']);
                                        
                                                          if(date('m', $page_data['attendance_date']) == date('m', $attendance_of_student['timestamp'])):
                                        
                                                            if($student_id_count != $attendance_of_student['student_id']): ?>

                                        <?php for ($i = 1; $i <= $number_of_days; $i++): ?>

                                        <?php
                                        
                                        $page_data['date'] = $i . ' ' . $page_data['month'] . ' ' . $page_data['year'];
                                        
                                        $timestamp = strtotime($page_data['date']);
                                        $attendance_by_id = DailyAttendances::where(['student_id' => $attendance_of_student['student_id'], 'school_id' => auth()->user()->school_id, 'timestamp' => $timestamp])->first();
                                        ?>

                                        <?php if(isset($attendance_by_id->status) && $attendance_by_id->status == 1): ?>

                                        <div class="present"></div>

                                        <?php elseif(isset($attendance_by_id->status) && $attendance_by_id->status == 0): ?>

                                        <div class="absent"></div>

                                        <?php else: ?>
                                        <div class="att-custom_div"></div>
                                        <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php endif; ?>

                                        <?php $student_id_count = $attendance_of_student['student_id']; ?>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach;  ?>
                            </ul>
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
            let url = "{{ route('class_wise_sections', ['id' => ':classId']) }}";
            url = url.replace(":classId", classId);
            $.ajax({
                url: url,
                success: function(response) {
                    $('#section_id').html(response);
                }
            });
        }

        function Export() {

            html2canvas(document.getElementById('pdf_table'), {
                onrendered: function(canvas) {
                    var data = canvas.toDataURL();
                    var docDefinition = {
                        content: [{
                            image: data,
                            width: 500
                        }]
                    };
                    pdfMake.createPdf(docDefinition).download("AttendenceReport.pdf");
                }
            });

        }

        var download_csv = function() {

            var month = $('#month').val();
            var year = $('#year').val();
            var role_id = '7';
            if (role_id != "" && month != "" && year != "") {

                var url = '{{ route('teacher.dailyAttendanceFilter_csv', 'month,year,role_id') }}';
                url = url.replace('month', month).replace('year', year).replace('role_id', role_id);
                var win = window.open(url, '_blank');
                win.focus();

            } else {
                toastr.error('{{ get_phrase('Please select the required fields') }}');
            }


        }
    </script>
@endsection
