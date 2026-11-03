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
                <a href="{{ route('admin.hei_admissions.wizard.create') }}" class="export_btn">{{ get_phrase('Add Application') }}</a>
                @if(auth()->user()->hasPermission('students.create'))
                    <a href="{{ route('admin.student.open_modal') }}" class="export_btn">{{ get_phrase('Add Existing Student') }}</a>
                @endif
            </div>
        </div>
    </div></div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

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
            <table class="table eTable align-middle mb-0" style="font-size:12px;">
                <thead><tr>
                    <th style="width:34px;">#</th>
                    <th>{{ get_phrase('App No.') }}</th>
                    <th>{{ get_phrase('Applicant') }}</th>
                    <th>{{ get_phrase('Programme') }}</th>
                    <th>{{ get_phrase('Session') }}</th>
                    <th>{{ get_phrase('Status') }}</th>
                    <th>{{ get_phrase('Fee') }}</th>
                    <th>{{ get_phrase('Source') }}</th>
                    <th>{{ get_phrase('Activation') }}</th>
                    <th>{{ get_phrase('Date') }}</th>
                    <th class="text-end">{{ get_phrase('Actions') }}</th>
                </tr></thead>
                <tbody>
                @forelse($admissions as $i => $app)
                @php $studentUser = $studentsByEmail->get(strtolower($app->email ?? '')); @endphp
                <tr>
                    <td style="width:34px;">{{ $admissions->firstItem() + $i }}</td>
                    <td class="text-truncate" style="max-width:110px;" title="{{ $app->app_number }}"><small class="text-muted">{{ $app->app_number }}</small></td>
                    <td style="min-width:150px;">
                        <div class="text-truncate fw-semibold" style="max-width:170px;" title="{{ $app->first_name }} {{ $app->last_name }}">{{ $app->first_name }} {{ $app->last_name }}</div>
                        <div class="text-truncate small text-muted" style="max-width:170px;" title="{{ $app->email }}">{{ $app->email }}</div>
                    </td>
                    <td class="text-truncate" style="max-width:150px;" title="{{ $app->programme?->name ?? '' }}">
                        @if(!empty($app->programme?->code))
                            <span class="fw-semibold">{{ $app->programme->code }}</span>
                        @else
                            {{ $app->programme?->name ?? '—' }}
                        @endif
                    </td>
                    <td class="text-truncate" style="max-width:130px;" title="{{ $app->intakeSession?->name ?? '' }}">{{ $app->intakeSession?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $app->statusColor() }}" style="font-size:11px;">{{ $app->statusLabel() }}</span>
                    </td>
                    <td>
                        <span class="badge bg-{{ $app->isFeeSettled() ? 'success' : ($app->fee_status === 'pending' ? 'primary' : 'light') }} {{ $app->fee_status === 'unpaid' ? 'text-dark' : '' }}" style="font-size:11px;">
                            {{ ucfirst($app->fee_status) }}
                        </span>
                    </td>
                    <td>
                        @if($app->source === 'public')
                            <span class="badge bg-info-subtle text-info" style="font-size:11px;" title="{{ get_phrase('Submitted by the applicant through the public apply form') }}">{{ get_phrase('Public') }}</span>
                        @else
                            <span class="badge bg-light text-dark" style="font-size:11px;" title="{{ get_phrase('Entered by staff in the Admin panel') }}">{{ get_phrase('Staff Entry') }}</span>
                        @endif
                    </td>
                    <td>
                        @if(!$studentUser)
                            <span class="badge bg-secondary" style="font-size:11px;">{{ get_phrase('Not Created') }}</span>
                        @elseif($studentUser->force_password_change)
                            <span class="badge bg-warning text-dark" style="font-size:11px;">{{ get_phrase('Activation Pending') }}</span>
                        @else
                            <span class="badge bg-success" style="font-size:11px;">{{ get_phrase('Activated') }}</span>
                        @endif
                    </td>
                    <td><small>{{ $app->created_at->format('d M Y') }}</small></td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                <li><a class="dropdown-item" href="{{ route('admin.hei_admissions.review', $app->id) }}">{{ get_phrase('Review Application') }}</a></li>
                                @if(\App\Support\Admissions\ApplicationWorkflow::hasBeenSubmitted($app) && ! $app->isFeeSettled())
                                    <li><span class="dropdown-item-text small text-muted">{{ get_phrase('Applicant payment pending') }}</span></li>
                                @else
                                    <li><a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.hei_admissions.open_modal', ['id' => $app->id]) }}', '{{ get_phrase('Edit Application') }}')">{{ get_phrase('Edit Application') }}</a></li>
                                @endif
                                @if($app->status === 'accepted' && (! \App\Support\Admissions\ApplicationWorkflow::hasBeenSubmitted($app) || $app->isFeeSettled()))
                                    <li><a class="dropdown-item" href="{{ route('admin.hei_admissions.offer_letter', $app->id) }}">{{ get_phrase('Offer Letter') }}</a></li>
                                @endif
                                @if($studentUser && (! \App\Support\Admissions\ApplicationWorkflow::hasBeenSubmitted($app) || $app->isFeeSettled()))
                                    <li><a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.student.resend_activation', $studentUser->id) }}', 'undefined');">{{ get_phrase('Resend Activation Email') }}</a></li>
                                @endif
                                @if($app->status === \App\Models\Admission::STATUS_DRAFT)
                                    @if($app->source === 'staff_entry')
                                        <li><a class="dropdown-item" href="{{ route('admin.hei_admissions.wizard.step', [$app->id, 'personal']) }}">{{ get_phrase('Complete Application') }}</a></li>
                                    @else
                                        <li><span class="dropdown-item-text small text-muted">{{ get_phrase('Awaiting applicant submission') }}</span></li>
                                    @endif
                                @endif
                                @unless(\App\Support\Admissions\ApplicationWorkflow::hasBeenSubmitted($app) && ! $app->isFeeSettled())
                                    <li><a class="dropdown-item text-danger" href="{{ route('admin.hei_admissions.destroy', $app->id) }}" onclick="return confirm('Delete?')">{{ get_phrase('Delete') }}</a></li>
                                @endunless
                                @unless(\App\Support\Admissions\ApplicationWorkflow::hasBeenSubmitted($app) && ! $app->isFeeSettled())
                                    <li><hr class="dropdown-divider"></li>
                                    <li class="px-3 py-1">
                                        <form action="{{ route('admin.hei_admissions.status', $app->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                @if(!in_array($app->status, \App\Models\Admission::STAFF_SETTABLE_STATUSES, true))
                                                    <option value="" selected disabled>{{ $app->statusLabel() }}</option>
                                                @endif
                                                @foreach(\App\Models\Admission::STAFF_SETTABLE_STATUSES as $s)
                                                    <option value="{{ $s }}" {{ $app->status == $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    </li>
                                @endunless
                            </ul>
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
        <div class="mt-3">{{ $admissions->withQueryString()->links() }}</div>
    </div></div>
</div>
@endsection
