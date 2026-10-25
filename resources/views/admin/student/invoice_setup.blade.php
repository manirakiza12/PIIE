@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12"><div class="d-flex justify-content-between align-items-center flex-wrap gr-15"><div><h4>Set up student invoice</h4><ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.student') }}">Students</a></li><li>Invoice setup</li></ul></div><a class="btn btn-outline-secondary" href="{{ route('admin.student') }}">Back to Students</a></div></div></div></div>
<div class="row"><div class="col-xl-8 col-12"><div class="eSection-wrap">
    @if(session('message'))<div class="alert alert-success" role="status">{{ session('message') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
    <h5>{{ $student->name }} <small class="text-muted">{{ $student->code }} · {{ $student->email }}</small></h5>
    <p class="text-muted">Invoice amount comes from this student's programme Fee Structure. Academic Year and Finance Session set accounting context.</p>
    @if($invoices->isNotEmpty())<div class="alert alert-info">This student already has {{ $invoices->count() }} invoice(s). Existing invoices are preserved; this form prevents a duplicate for the same fee and Finance Session.</div>@endif
    @if(!$programme)<div class="alert alert-warning">Select the student's programme before setting up an invoice. <a href="javascript:;" onclick="rightModal('{{ route('admin.student_edit_modal', $student->id) }}','Edit / Complete details')">Edit / Complete details</a>.</div>@endif
    <form id="student_invoice_setup_form" method="POST" action="{{ route($area . '.student.invoice_create', $student->id) }}" class="mt-3" data-currency="{{ school_currency() }}">
        @csrf
        <div class="mb-3"><label for="fee_academic_year_id" class="form-label">Academic Year <span class="text-danger">*</span></label>
            <select class="form-select @error('fee_academic_year_id') is-invalid @enderror" id="fee_academic_year_id" name="fee_academic_year_id" required>
                <option value="">Select academic year</option>
                @foreach($academicYears as $academicYear)<option value="{{ $academicYear->id }}" @selected((string)old('fee_academic_year_id', $year?->id) === (string)$academicYear->id)>{{ $academicYear->label }}</option>@endforeach
            </select>@error('fee_academic_year_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @if(!$year)<small class="text-warning">No configured active academic year. Select the correct year deliberately.</small>@endif
        </div>
        <div class="mb-3"><label for="fee_session_id" class="form-label">Finance Session <span class="text-danger">*</span></label>
            <select class="form-select @error('fee_session_id') is-invalid @enderror" id="fee_session_id" name="fee_session_id" required>
                <option value="">Select a Finance Session</option>
                @foreach($sessions as $financeSession)<option value="{{ $financeSession->id }}" @selected((string)old('fee_session_id') === (string)$financeSession->id)>{{ $financeSession->session_title }}</option>@endforeach
            </select>@error('fee_session_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @if($year && $sessions->isEmpty())<div class="form-text text-warning">No Finance Session is linked to {{ $year->label }}. Link one in Session Manager to create the invoice.</div>@endif
        </div>
        <div class="mb-3"><label for="fee_structure_id" class="form-label">Programme Fee Structure <span class="text-danger">*</span></label>
            <select class="form-select @error('fee_structure_id') is-invalid @enderror" id="fee_structure_id" name="fee_structure_id" required @disabled(!$programme)>
                <option value="">{{ $programme ? 'Select fee type' : 'Programme required' }}</option>
                @foreach($structures as $structure)<option value="{{ $structure->id }}" data-name="{{ $structure->name }}" data-type="{{ $structure->fee_type }}" data-amount="{{ number_format((float)$structure->amount, 2, '.', '') }}" data-basis="{{ $structure->per_semester ? 'Per semester' : 'As configured' }}" data-session="{{ $structure->session_id ?? '' }}" @selected((string)old('fee_structure_id') === (string)$structure->id)>{{ $structure->name }} · {{ ucfirst($structure->fee_type) }}</option>@endforeach
            </select>@error('fee_structure_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="mt-2 fw-semibold" id="fee_summary" aria-live="polite">Choose a fee type to see its configured amount and billing basis.</div>
        </div>
        @if(app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.payments'))
        <section class="border rounded p-3 mb-3" aria-labelledby="receipt-heading"><h6 id="receipt-heading">Confirmed amount received <small class="text-muted">Optional</small></h6>
            <p class="small text-muted">A receipt is recorded through the Finance payment ledger and reduces the remaining balance.</p>
            <div class="mb-3"><label class="form-label" for="received_payment_amount">Amount received ({{ school_currency() }})</label><input class="form-control @error('received_payment_amount') is-invalid @enderror" id="received_payment_amount" name="received_payment_amount" type="number" min="0" step="0.01" value="{{ old('received_payment_amount', 0) }}">@error('received_payment_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div id="receipt_details" class="row g-3" hidden>
                <div class="col-md-6"><label class="form-label" for="received_payment_on">Received on</label><input class="form-control" id="received_payment_on" name="received_payment_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('received_payment_on', now()->toDateString()) }}"></div>
                <div class="col-md-6"><label class="form-label" for="received_payment_method">Method</label><select class="form-select" id="received_payment_method" name="received_payment_method"><option value="">Select method</option>@foreach(['cash','bank_transfer','mobile_money','card','cheque','other'] as $method)<option value="{{ $method }}">{{ ucwords(str_replace('_',' ',$method)) }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="received_payment_reference">Receipt / transaction reference</label><input class="form-control" id="received_payment_reference" name="received_payment_reference" maxlength="150" value="{{ old('received_payment_reference') }}"></div>
                <div class="col-md-6"><label class="form-label" for="received_payment_reason">Verification note</label><textarea class="form-control" id="received_payment_reason" name="received_payment_reason" minlength="10" maxlength="500">{{ old('received_payment_reason') }}</textarea></div>
            </div>
            <div class="mt-3">Remaining balance: <strong id="remaining_balance" aria-live="polite">—</strong></div>
        </section>
        @endif
        <p class="small text-muted">Pending prior-payment claims, awards, and waivers remain for Finance review and do not reduce the balance.</p>
        <button class="btn btn-primary" type="submit" @disabled(!$programme)>Create invoice</button>
    </form>
</div></div></div>
<script src="{{ asset('assets/js/student-invoice-setup.js') }}" defer></script>
@endsection
