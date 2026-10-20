<?php 
use App\Http\Controllers\CommonController; 

if($selected_class == ""){
    $sel_class = 'all-class';
} else {
    $sel_class = $selected_class;
}

if($date_from == "") {
    $date_from = date('d-M-Y', strtotime(' -30 day'));
} else {
    $date_from = date('d-M-Y', $date_from);
}

if($date_to == "") {
    $date_to = date('d-M-Y');
} else {
    $date_to = date('d-M-Y', $date_to);
}

if($selected_class == ""){
    $sel_status = 'paid-and-unpaid';
} else {
    $sel_status = $selected_status;
}

?>

@extends('accountant.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4>{{ get_phrase('Genarated Fee Report') }}</h4>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <div class="row mb-3">
                <div class="expense_add">
                    <a class="btn btn-outline-success float-end m-1" id="export-print" href="javascript:0" onclick="printableDiv('student_fee_manager')" data-bs-toggle="tooltip">{{ get_phrase('Print') }}</a>
                    <a class="btn btn-outline-primary float-end m-1" id="export-pdf" href="javascript:0" onclick="Export()" data-bs-toggle="tooltip">{{ get_phrase('Export PDF') }}</a>
                </div>
            </div>
            <div class="invoice_content" id="student_fee_manager">
                <table id="student_fee_export" class="table eTable">
                    <thead>
                        <tr>
                            <th>{{ get_phrase('Invoice No') }}</th>
                            <th>{{ get_phrase('Student') }}</th>
                            <th>{{ get_phrase('Class') }}</th>
                            <th>{{ get_phrase('Invoice Title') }}</th>
                            <th>{{ get_phrase('Total Amount') }}</th>
                            <th>{{ get_phrase('Created at') }}</th>
                            <th>{{ get_phrase('Paid Amount') }}</th>
                            <th>{{ get_phrase('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <?php $student_details = (new CommonController)->get_student_details_by_id($invoice['student_id']); ?>
                            <tr>
                                <td> 
                                    {{ sprintf('%08d', $invoice['id']) }} 
                                </td>
                                <td>
                                    {{ $student_details['name'] }}
                                </td>
                                <td>
                                    <small>{{ $student_details['class_name'] }}</small>
                                </td>
                                <td> 
                                    {{ $invoice['title'] }}
                                </td>
                                <td>
                                    {{ school_currency($invoice['total_amount']) }}
                                </td>
                                <td>
                                    <small>{{ date('d-M-Y', $invoice['timestamp']) }} </small>
                                </td>
                                <td>
                                    {{ school_currency($invoice['paid_amount']) }}
                                </td>
                                <td>
                                    <?php if (strtolower($invoice['status']) == 'unpaid'): ?>
                                        <span class="bg bg-danger">{{ ucfirst($invoice['status']) }}</span>
                                    <?php else: ?>
                                        <span class="bg bg-success">{{ ucfirst($invoice['status']) }}</span>
                                    <?php endif; ?>
                                </td>
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


    function Export() {
        window.exportReportPdf(document.getElementById('student_fee_export'), "student_fee-{{ $date_from.'-'.$date_to.'-'.$sel_class.'-'.$sel_status }}.pdf");
    }

    function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

</script>
@endsection