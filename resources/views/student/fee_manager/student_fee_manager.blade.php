<?php

if ($date_from == "" || $date_from === null) {
    $date_from = strtotime(date('d-M-Y', strtotime(' -30 day')));
} else {
    $date_from = strtotime(date('d-M-Y', $date_from));
}

if ($date_to == "" || $date_to === null) {
    $date_to = strtotime(date('d-M-Y'));
} else {
    $date_to = strtotime(date('d-M-Y', $date_to));
}

?>

@extends('student.navigation')

@section('content')
@include('student.partials.study_fee_status')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Fee Manager') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Fee Manager') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

@if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

@php
    $unpaidCount = $invoices->whereIn('status', ['unpaid', 'processing'])->count();
    $paidCount = $invoices->where('status', 'paid')->count();
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="student-stat-card" style="--stat-color: {{ $totalDue > 0 ? '#e5484d' : '#1fa971' }};">
            <span class="student-stat-value">{{ currency($totalDue) }}</span>
            <span class="student-stat-label">{{ get_phrase('Balance Due') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="student-stat-card" style="--stat-color: #3a86ff;">
            <span class="student-stat-value">{{ $invoices->count() }}</span>
            <span class="student-stat-label">{{ get_phrase('Total Invoices') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="student-stat-card" style="--stat-color: #f5a623;">
            <span class="student-stat-value">{{ $unpaidCount }}</span>
            <span class="student-stat-label">{{ get_phrase('Unpaid / Pending') }}</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="student-stat-card" style="--stat-color: #1fa971;">
            <span class="student-stat-value">{{ $paidCount }}</span>
            <span class="student-stat-label">{{ get_phrase('Paid') }}</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <form method="GET" class="row g-2 align-items-end mb-3" action="{{ route('student.fee_manager.list') }}">
                <div class="col-md-4">
                    <label class="eForm-label">{{ get_phrase('Date Range') }}</label>
                    <input type="text" class="form-control eForm-control" name="eDateRange"
                        value="{{ date('m/d/Y', $date_from) . ' - ' . date('m/d/Y', $date_to) }}" />
                </div>
                <div class="col-md-3">
                    <label class="eForm-label">{{ get_phrase('Status') }}</label>
                    <select name="status" class="form-select eForm-select">
                        <option value="all" {{ ($selected_status ?? '') === 'all' ? 'selected' : '' }}>{{ get_phrase('All status') }}</option>
                        <option value="paid" {{ ($selected_status ?? '') === 'paid' ? 'selected' : '' }}>{{ get_phrase('Paid') }}</option>
                        <option value="unpaid" {{ ($selected_status ?? '') === 'unpaid' ? 'selected' : '' }}>{{ get_phrase('Unpaid') }}</option>
                        <option value="processing" {{ ($selected_status ?? '') === 'processing' ? 'selected' : '' }}>{{ get_phrase('Processing') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="eBtn eBtn-primary w-100">{{ get_phrase('Filter') }}</button>
                </div>
                @if($invoices->count() > 0)
                    <div class="col-md-3 text-md-end">
                        <div class="dropdown d-inline-block">
                            <button class="eBtn eBtn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                {{ get_phrase('Export') }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="javascript:;" onclick="generatePDF()">{{ get_phrase('PDF') }}</a></li>
                                <li><a class="dropdown-item" href="javascript:;" onclick="printableDiv('student_fee_report')">{{ get_phrase('Print') }}</a></li>
                            </ul>
                        </div>
                    </div>
                @endif
            </form>

            <div id="student_fee_manager">
                @if($invoices->count() > 0)
                    <div class="table-responsive" id="student_fee_report">
                        <table class="table eTable">
                            <thead>
                                <tr>
                                    <th>{{ get_phrase('Invoice No') }}</th>
                                    <th>{{ get_phrase('Fee') }}</th>
                                    <th>{{ get_phrase('Total Amount') }}</th>
                                    <th>{{ get_phrase('Paid') }}</th>
                                    <th>{{ get_phrase('Balance') }}</th>
                                    <th>{{ get_phrase('Date') }}</th>
                                    <th>{{ get_phrase('Status') }}</th>
                                    <th class="text-end">{{ get_phrase('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($invoices as $invoice)
                                @php
                                    $balance = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
                                @endphp
                                <tr>
                                    <td>{{ sprintf('%08d', $invoice->id) }}</td>
                                    <td>
                                        {{ $invoice->title }}
                                        @if($invoice->fee_structure_id && !empty($feeStructures[$invoice->fee_structure_id]))
                                            <div class="text-muted small">{{ $feeStructures[$invoice->fee_structure_id] }}</div>
                                        @endif
                                    </td>
                                    <td>{{ currency($invoice->total_amount) }}</td>
                                    <td>{{ currency($invoice->paid_amount) }}</td>
                                    <td class="fw-semibold {{ $balance > 0 ? 'text-danger' : 'text-success' }}">{{ currency($balance) }}</td>
                                    <td>{{ $invoice->timestamp ? date('d M Y', $invoice->timestamp) : '—' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $invoice->status === 'paid' ? 'success' : ($invoice->status === 'processing' ? 'warning' : 'danger') }}">
                                            {{ ucfirst($invoice->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($balance > 0)
                                            <a href="{{ route('student.FeePayment', $invoice->id) }}" class="eBtn eBtn-sm eBtn-primary">{{ get_phrase('Pay Now') }}</a>
                                        @else
                                            <a href="{{ route('student.studentFeeinvoice', $invoice->id) }}" class="eBtn eBtn-sm eBtn-secondary">{{ get_phrase('View Invoice') }}</a>
                                        @endif
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
                        <span>{{ get_phrase('No invoices found for this filter') }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<style>
    .student-stat-card {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 16px 18px;
        background: #fff;
        border-radius: 10px;
        border-left: 4px solid var(--stat-color, #cacfd4);
        box-shadow: 0 6px 20px rgba(121, 124, 139, 0.0156862745);
    }
    .student-stat-value { font-size: 26px; font-weight: 700; line-height: 1.1; color: #181c32; }
    .student-stat-label { font-size: 12px; color: #797c8b; text-transform: uppercase; letter-spacing: .03em; }
</style>

<script type="text/javascript">
    "use strict";

    function generatePDF() {
        const element = document.getElementById("student_fee_report");
        var clonedElement = element.cloneNode(true);
        $(clonedElement).css("display", "block");
        var opt = {
            margin: 1,
            filename: 'student_fee-{{ date('d-M-Y', $date_from) . '-' . date('d-M-Y', $date_to) }}.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 }
        };
        html2pdf().set(opt).from(clonedElement).save();
        clonedElement.remove();
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
