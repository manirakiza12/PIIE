@extends('admin.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4>{{ get_phrase('Export Offline Exam') }}</h4>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <div class="row mb-3">
                <div class="expense_add">
                    <a class="btn btn-outline-success float-end m-1" id="export-print" href="javascript:0" onclick="printableDiv('offline_exam_list')" data-bs-toggle="tooltip">{{ get_phrase('Print') }}</a>
                    <a href="javascript:0" class="btn btn-outline-primary float-end m-1" id="download-button" data-bs-toggle="tooltip">{{ get_phrase('Export CSV') }}</a>
                    <a class="btn btn-outline-primary float-end m-1" id="export-pdf" href="javascript:0" onclick="Export()" data-bs-toggle="tooltip">{{ get_phrase('Export PDF') }}</a>
                </div>
            </div>
            <div class="card-body exam_content" id="offline_exam_list">
                <table id="offline_exam_export" class="table table-striped dt-responsive nowrap" width="100%">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ get_phrase('Exam') }}</th>
                            <th>{{ get_phrase('Starting Time') }}</th>
                            <th>{{ get_phrase('Ending Time') }}</th>
                            <th>{{ get_phrase('Total Marks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($exams as $exam)
                            <tr>
                                <td>{{ $loop->index + 1 }}</td>
                                <td>{{ $exam->name }}</td>
                                <?php $class_room = DB::table('class_rooms')->find($exam->room_number); ?>
                                <td>{{ $class_room->name }}</td>
                                <td>{{ date('d M Y - h:i A', $exam->starting_time) }}</td>
                                <td>{{ date('d M Y - h:i A', $exam->ending_time) }}</td>
                                <td>{{ $exam->total_marks }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<script type="text/javascript">

  "use strict";

    
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
        var html = document.querySelector("#offline_exam_export").outerHTML;
        htmlToCSV(html, "offline_exam.csv");
    });


    function htmlToCSV(html, filename) {
        var data = [];
        var rows = document.querySelectorAll("#offline_exam_export tr");
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

    function Export() {
        window.exportReportPdf(document.getElementById('offline_exam_export'), "offline_exam_export.pdf");
    }

    function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

</script>
@endsection
