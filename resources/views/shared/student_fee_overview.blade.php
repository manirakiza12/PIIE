<section class="eSection-wrap mb-4" id="student_fee_overview" aria-label="Students by fee session">
    <h5>Students by academic session</h5>
    <p>Every enabled student account in this school is listed, including students with no invoice or zero balance. Academic placement does not control this list. The invoice report's date, class, programme and status filters below do not hide students here.</p>
    <form method="GET" action="{{ url()->current() }}" class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['eDateRange', 'class', 'programme', 'status'] as $reportFilter)
            @if(request()->filled($reportFilter))<input type="hidden" name="{{ $reportFilter }}" value="{{ request($reportFilter) }}">@endif
        @endforeach
        <label for="fee_session_id">Academic / fee session<select name="fee_session_id" id="fee_session_id" class="form-select">
            @foreach($feeSessions as $session)<option value="{{ $session->id }}" @selected((int)$session->id === $feeSessionId)>{{ $session->session_title }}</option>@endforeach
        </select></label>
        <label for="fee_search">Find student<input id="fee_search" class="form-control" name="fee_search" value="{{ request('fee_search') }}" placeholder="Name, email or registration number"></label>
        <button class="btn btn-primary align-self-end">Find students</button>
    </form>
    <p>Session: <strong>{{ $feeSessions->firstWhere('id', $feeSessionId)?->session_title ?? ('Session #' . $feeSessionId) }}</strong> · {{ $feeStudents->total() }} matching student(s); showing {{ $feeStudents->firstItem() ?? 0 }}–{{ $feeStudents->lastItem() ?? 0 }}.</p>
    <p class="text-muted">Prior payments are verified pre-PIIE cash/receipts, separate from new collections and awards. Awards already reduce the net fee. Pending items do not reduce the balance. A student without an invoice has an unknown amount due, not a paid-up balance.</p>
    <div class="table-responsive"><table class="table eTable align-middle"><thead><tr><th>Student</th><th>Base fee / discount</th><th>Awards</th><th>Net fee</th><th>Verified prior payments<br>(pre-PIIE cash)</th><th>New payments</th><th>Balance / status</th><th>Invoices / confirmation</th></tr></thead><tbody>
    @forelse($feeStudents as $feeStudent)
        @php($summary = $feeStudent->fee_summary)
        <tr><td>{{ $feeStudent->name }}<br>{{ $feeStudent->code }}<br><small>{{ $feeStudent->email }} · ID {{ $feeStudent->id }}</small></td>
        @if($feeStudent->sessionInvoices->isEmpty())
            <td colspan="6">Needs invoice for this session — amount due not yet established.</td><td><a class="btn btn-sm btn-primary" href="{{ route($feeArea . '.student.invoice_setup', ['id' => $feeStudent->id, 'fee_academic_year_id' => optional($feeSessions->firstWhere('id', $feeSessionId))->academic_year_id]) }}">Set up invoice for {{ $feeStudent->name }}</a></td>
        @else
            <td>{{ school_currency($summary['base'] / 100) }}<br><small>Discount: {{ school_currency($summary['discount'] / 100) }}</small></td>
            <td>{{ school_currency($summary['awards'] / 100) }}</td><td>{{ school_currency($summary['fee'] / 100) }}</td>
            <td>{{ school_currency($summary['prior'] / 100) }}</td><td>{{ school_currency($summary['new'] / 100) }}</td>
            <td>{{ school_currency($summary['balance'] / 100) }}<br><strong>{{ $summary['balance'] > 0 ? 'Owing' : 'Fully settled' }}</strong></td>
            <td>@foreach($feeStudent->sessionInvoices as $sessionInvoice)
                <div class="mb-2"><strong>#{{ sprintf('%08d', $sessionInvoice->id) }}</strong> {{ $sessionInvoice->title }}<br>
                <a href="{{ route($feeArea . '.tuition_payments.show', $sessionInvoice->id) }}">Review invoice and financial setup</a>
                @foreach($sessionInvoice->overview['pending'] as $pending)<div class="text-warning">{{ $pending }}</div>@endforeach
                @if($sessionInvoice->status === 'pending')<div class="text-warning">Invoice status pending — review confirmation.</div>@endif
                @if($sessionInvoice->overview['reconcile'])<div class="text-warning">Payment totals need reconciliation; review the preserved history.</div>@endif
                </div>
            @endforeach</td>
        @endif</tr>
    @empty<tr><td colspan="8">No enabled students match this search in this school. Clear the search to view all students.</td></tr>@endforelse
    </tbody></table></div>{{ $feeStudents->links() }}
</section>
