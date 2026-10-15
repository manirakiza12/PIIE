<?php 

use App\Models\User;
use App\Models\Subject;
use App\Models\Section;

$index = 0;

?>

@extends('teacher.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Gradebooks') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                        <li><a href="#">{{ get_phrase('Gradebooks') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <form method="GET" class="d-block ajaxForm" action="{{ route('teacher.gradebook') }}">
                <div class="row mt-3">

                    <div class="col-md-2"></div>

                    <div class="col-md-2">
                        <label for="class_id" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                        <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove" required onchange="classWiseSection(this.value)">
                            <option value="">{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id) }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $class_id == $class->id ?  'selected':'' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if(count($filter_list) <= 0)
                    <div class="col-md-3">
                    @else
                    <div class="col-md-2">
                    @endif
                        <label for="section_id" class="eForm-label">{{ academic_term('section', auth()->user()->school_id) }}</label>
                        <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove" required >
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

                    @if(count($filter_list) <= 0)
                    <div class="col-md-3">
                    @else
                    <div class="col-md-2">
                    @endif
                        <label for="exam_category_id" class="eForm-label">{{ get_phrase('Exam') }}</label>
                        <select name="exam_category_id" id="exam_category_id" class="form-select eForm-select eChoice-multiple-with-remove" required>
                            <option value="">{{ get_phrase('Select a exam category') }}</option>
                            @foreach($exam_categories as $exam_category)
                                <option value="{{ $exam_category->id }}" {{ $exam_category_id == $exam_category->id ?  'selected':'' }}>{{ $exam_category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2 pt-2">
                        <button class="eBtn eBtn btn-secondary mt-4" type="submit" id = "filter_routine">{{ get_phrase('Filter') }}</button>
                    </div>

                    @if(count($filter_list) > 0)
                    <div class="flex-wrap col-md-2">
                        <div class="position-relative flex-wrap mt-4">
                          <button class="eBtn-3 dropdown-toggle float-end" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
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
                                <a class="dropdown-item" id="download-button" href="javascript:0">{{ get_phrase('CSV') }}</a>
                            </li>
                            <li>
                                <a class="dropdown-item" id="print" href="javascript:;" onclick="printableDiv('gradebook_report')">{{ get_phrase('Print') }}</a>
                            </li>
                          </ul>
                        </div>
                    </div>
                    @endif

                    <div class="card-body gradebook_content pt-4" id="gradebook_report">
                        @if(count($filter_list) > 0)
                            <table class="table eTable" id="gradebook_report">
                                <thead>
                                    <th>#</th>
                                    <th>{{ get_phrase('Student Name') }}</th>
                                    @foreach($subjects as $subject)
                                       <th>{{ $subject->name }}</th>
                                    @endforeach
                                </thead>
                                <tbody>
                                    @foreach($filter_list as $student)
                                    <?php $subject_list = json_decode($student->marks, true); ?>
                                    <tr>
                                        <td>{{ $index = $index+1 }}</td>
                                        <?php 
                                        $student_details = User::find($student->student_id);
                                        $info = json_decode($student_details->user_information);
                                        ?>
                                        <td>{{ $student_details->name }}</td>
                                        @foreach($subject_list as $key => $mark)
                                            <?php $subject_details = json_decode(Subject::find($key), true); ?>
                                           <td>{{ $mark }}</td>
                                        @endforeach
                                    <tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="empty_box center">
                                <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
                            </div>
                        @endif
                    </div>
                    
                </div>
            </form>
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

    function manage_gradebook(){
        var class_id = $('#class_id').val();
        var section_id = $('#section_id').val();
        var exam_category_id = $('#exam_category_id').val();
        if(class_id != "" && section_id != "" && exam_category_id != ""){
            show_student_list();
        }else{
            toastr.error("Please select all the fields correctly");
        }
    }

    function downloadCSVFile(csv, filename) {
        var csv_file, download_link;

        csv_file = new Blob([csv], {type: "text/csv"});

        download_link = document.createElement("a");

        download_link.download = filename;

        download_link.href = window.URL.createObjectURL(csv_file);

        download_link.style.display = "none";

        document.body.appendChild(download_link);

        download_link.click();

    }

    document.getElementById("download-button").addEventListener("click", function () {
        var html = document.querySelector("#gradebook_report").outerHTML;
        htmlToCSV(html, "gradebook_report.csv");
    });


    function htmlToCSV(html, filename) {
        var data = [];
        var rows = document.querySelectorAll("#gradebook_report tr");
        for (var i = 0; i < rows.length; i++) {
            var row = [], cols = rows[i].querySelectorAll("td, th");


            for (var j = 0; j < cols.length; j++) {
                row.push(cols[j].innerText);
                console.log(cols[j].innerText)

            }

            data.push(row.join(","));

        }
        downloadCSVFile(data.join("\n"), filename);
    }

    var specialElementHandlers = {
        // element with id of "bypass" - jQuery style selector
        '.no-export': function (element, renderer) {
            // true = "handled elsewhere, bypass text extraction"
            return true;
        }
    };

    function Export() {
        html2canvas(document.getElementById('gradebook_report'), {
            onrendered: function(canvas) {
                var data = canvas.toDataURL();
                var docDefinition = {
                    content: [{
                        image: data,
                        width: 500
                    }]
                };
                pdfMake.createPdf(docDefinition).download("gradebook_report.pdf");
            }
        });
    }

    function printableDiv(printableAreaDivId) {
        var printContents = document.getElementById(printableAreaDivId).innerHTML;
        var originalContents = document.body.innerHTML;

        document.body.innerHTML = printContents;

        window.print();

        document.body.innerHTML = originalContents;
    }

</script>
@endsection