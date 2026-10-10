@extends('admin.navigation')
@section('content')
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div class="d-flex flex-column">
                <h4>{{ get_phrase('HEI Admissions') }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="#">{{ get_phrase('Home') }}</a></li>
                    <li><a href="#">{{ get_phrase('Admissions') }}</a></li>
                </ul>
            </div>
            <div class="export-btn-area d-flex gap-2">
                @if((int) auth()->user()->role_id === 2)
                    <a href="{{ route('admin.hei_admissions.payment.pesapal.settings') }}" class="export_btn export_btn-outline">{{ get_phrase('Applicant PesaPal Settings') }}</a>
                @endif
                <a href="{{ route('admin.hei_admissions.export', ['search' => $search, 'status' => $status, 'session_id' => $session_id, 'source' => $source]) }}" class="export_btn export_btn-outline"><i class="bi bi-download"></i> {{ get_phrase('Export CSV') }}</a>
                <a href="{{ route('admin.intake_sessions.index') }}" class="export_btn export_btn-outline">{{ get_phrase('Intake Sessions') }}</a>
                <a href="{{ route('admin.admissions_documents.index') }}" class="export_btn export_btn-outline">{{ get_phrase('Document Requirements') }}</a>
                <a href="{{ route('admin.admissions_agents.index') }}" class="export_btn export_btn-outline">{{ get_phrase('Agents') }}</a>
                <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.hei_admissions.open_modal') }}', '{{ get_phrase('New Application') }}')">{{ get_phrase('Add Application') }}</a>
            </div>
        </div>
    </div></div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

{{-- School-wide counts, not page-wide: applying a filter must not change
     what "Under Review" says the workload is. --}}
<div class="row mb-3">
    <div class="col-md-2 col-6"><div class="eCard text-center p-3"><h4 class="text-primary">{{ $counts->sum() }}</h4><small>{{ get_phrase('Submitted') }}</small></div></div>
    <div class="col-md-2 col-6"><div class="eCard text-center p-3"><h4 class="text-info">{{ $counts['submitted'] ?? 0 }}</h4><small>{{ get_phrase('New') }}</small></div></div>
    <div class="col-md-2 col-6"><div class="eCard text-center p-3"><h4 class="text-warning">{{ $counts['under_review'] ?? 0 }}</h4><small>{{ get_phrase('Under Review') }}</small></div></div>
    <div class="col-md-2 col-6"><div class="eCard text-center p-3"><h4 style="color:#b54708;">{{ $counts['needs_correction'] ?? 0 }}</h4><small>{{ get_phrase('With Applicant') }}</small></div></div>
    <div class="col-md-2 col-6"><div class="eCard text-center p-3"><h4 class="text-success">{{ $counts['enrolled'] ?? 0 }}</h4><small>{{ get_phrase('Enrolled') }}</small></div></div>
    <div class="col-md-2 col-6">
        <a href="{{ route('admin.hei_admissions.index', ['status' => 'draft']) }}" class="text-decoration-none">
            <div class="eCard text-center p-3"><h4 class="text-secondary">{{ $draftCount }}</h4><small>{{ get_phrase('Drafts (not submitted)') }}</small></div>
        </a>
    </div>
</div>

<div class="row">
    <div class="col-12"><div class="eSection-wrap">
        <form action="{{ route('admin.hei_admissions.index') }}" class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <input type="text" name="search" value="{{ $search }}" placeholder="{{ get_phrase('Search name, email, app#') }}" class="form-control eForm-control" style="max-width:240px">
            <select name="status" class="form-control eForm-control" style="max-width:170px">
                <option value="">{{ get_phrase('All Statuses') }}</option>
                @foreach(\App\Models\Admission::STATUSES as $s)
                    <option value="{{ $s }}" {{ $status == $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                @endforeach
            </select>
            <select name="fee_status" class="form-control eForm-control" style="max-width:150px">
                <option value="">{{ get_phrase('Any Fee Status') }}</option>
                @foreach(['unpaid','pending','paid','waived'] as $f)
                    <option value="{{ $f }}" {{ $fee_status == $f ? 'selected' : '' }}>{{ get_phrase('Fee') }}: {{ ucfirst($f) }}</option>
                @endforeach
            </select>
            <select name="session_id" class="form-control eForm-control" style="max-width:180px">
                <option value="">{{ get_phrase('All Sessions') }}</option>
                @foreach($sessions as $sess)
                    <option value="{{ $sess->id }}" {{ $session_id == $sess->id ? 'selected' : '' }}>{{ $sess->name }}</option>
                @endforeach
            </select>
            <select name="source" class="form-control eForm-control" style="max-width:180px">
                <option value="">{{ get_phrase('All Sources') }}</option>
                <option value="public" {{ $source == 'public' ? 'selected' : '' }}>{{ get_phrase('Public Applications') }}</option>
                <option value="staff_entry" {{ $source == 'staff_entry' ? 'selected' : '' }}>{{ get_phrase('Staff Entries') }}</option>
            </select>
            <button type="submit" class="eBtn">{{ get_phrase('Filter') }}</button>
        </form>

        <div class="table-responsive">
            <table class="table eTable">
                <thead><tr>
                    <th>#</th><th>{{ get_phrase('App No.') }}</th><th>{{ get_phrase('Applicant') }}</th>
                    <th>{{ get_phrase('Programme') }}</th><th>{{ get_phrase('Session') }}</th>
                    <th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Fee') }}</th><th>{{ get_phrase('Source') }}</th><th>{{ get_phrase('Activation') }}</th><th>{{ get_phrase('Date') }}</th><th>{{ get_phrase('Actions') }}</th>
                </tr></thead>
                <tbody>
                @forelse($admissions as $i => $app)
                @php $studentUser = $studentsByEmail->get(strtolower($app->email ?? '')); @endphp
                <tr>
                    <td>{{ $admissions->firstItem() + $i }}</td>
                    <td><small class="text-muted">{{ $app->app_number }}</small></td>
                    <td>
                        <strong>{{ $app->first_name }} {{ $app->last_name }}</strong><br>
                        <small class="text-muted">{{ $app->email }}</small>
                    </td>
                    <td>{{ $app->programme?->name ?? '—' }}</td>
                    <td>{{ $app->intakeSession?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $app->statusColor() }}">{{ $app->statusLabel() }}</span>
                    </td>
                    <td>
                        <span class="badge bg-{{ $app->isFeeSettled() ? 'success' : ($app->fee_status === 'pending' ? 'primary' : 'light') }} {{ $app->fee_status === 'unpaid' ? 'text-dark' : '' }}">
                            {{ ucfirst($app->fee_status) }}
                        </span>
                    </td>
                    <td>
                        @if($app->source === 'public')
                            <span class="badge bg-info-subtle text-info" title="{{ get_phrase('Submitted by the applicant through the public apply form') }}">{{ get_phrase('Public') }}</span>
                        @else
                            <span class="badge bg-light text-dark" title="{{ get_phrase('Entered by staff in the Admin panel') }}">{{ get_phrase('Staff Entry') }}</span>
                        @endif
                    </td>
                    <td>
                        @if(!$studentUser)
                            <span class="badge bg-secondary">{{ get_phrase('Not Created') }}</span>
                        @elseif($studentUser->force_password_change)
                            <span class="badge bg-warning text-dark">{{ get_phrase('Activation Pending') }}</span>
                        @else
                            <span class="badge bg-success">{{ get_phrase('Activated') }}</span>
                        @endif
                    </td>
                    <td><small>{{ $app->created_at->format('d M Y') }}</small></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="{{ route('admin.hei_admissions.review', $app->id) }}" class="eBtn eBtn-sm eBtn-primary" title="{{ get_phrase('Review Application') }}"><i class="bi bi-eye" aria-hidden="true"></i> {{ get_phrase('Review') }}</a>
                            <a href="javascript:;" class="eBtn eBtn-sm eBtn-outline" onclick="rightModal('{{ route('admin.hei_admissions.open_modal', ['id' => $app->id]) }}', '{{ get_phrase('Edit Application') }}')"><i class="bi bi-pencil"></i></a>
                            @if($app->status === 'accepted')
                            <a href="{{ route('admin.hei_admissions.offer_letter', $app->id) }}" class="eBtn eBtn-sm eBtn-success" title="{{ get_phrase('Offer Letter') }}"><i class="bi bi-file-pdf"></i></a>
                            @endif
                            @if($studentUser)
                            <a href="javascript:;" class="eBtn eBtn-sm eBtn-warning" title="{{ get_phrase('Resend Activation Email') }}" onclick="confirmModal('{{ route('admin.student.resend_activation', $studentUser->id) }}', 'undefined');"><i class="bi bi-envelope-arrow-up"></i></a>
                            @endif
                            {{-- Quick status change stays here for the common
                                 "move a batch to Under Review" pass; anything
                                 needing a note or a document check belongs on
                                 the review screen. --}}
                            <form action="{{ route('admin.hei_admissions.status', $app->id) }}" method="POST" class="d-inline">
                                @csrf
                                <select name="status" class="form-select form-select-sm" style="width:150px" onchange="this.form.submit()">
                                    @foreach(\App\Models\Admission::STAFF_SETTABLE_STATUSES as $s)
                                        <option value="{{ $s }}" {{ $app->status == $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                                    @endforeach
                                </select>
                            </form>
                            <a href="{{ route('admin.hei_admissions.destroy', $app->id) }}" class="eBtn eBtn-sm eBtn-danger" onclick="return confirm('Delete?')"><i class="bi bi-trash"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="11" class="text-center text-muted py-4">{{ get_phrase('No applications found') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $admissions->withQueryString()->links() }}</div>
    </div></div>
</div>
@endsection
