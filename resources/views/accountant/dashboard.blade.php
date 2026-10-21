@extends('accountant.navigation')
@section('content')

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Finance Dashboard') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Dashboard') }}</a></li>
                    </ul>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('accountant.fee_manager.sync') }}" class="eBtn eBtn-secondary">{{ get_phrase('Sync Invoices') }}</a>
                    <a href="{{ route('accountant.fee_manager.list') }}" class="eBtn eBtn-primary">{{ get_phrase('Fee Manager') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap mb-3">
            <h4 class="text-dark mb-0">{{ auth()->user()->name }}</h4>
            <p class="mb-0">{{ get_phrase('Welcome, to') }} {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }} — {{ get_phrase('Finance Overview') }}</p>
        </div>
    </div>
</div>

@php
    $financeStatCards = [
        ['label' => 'Total Invoiced', 'value' => currency($totalInvoiced), 'color' => '#3a86ff'],
        ['label' => 'Prior Payments', 'value' => currency($openingPaymentsTotal), 'color' => '#6c757d'],
        ['label' => 'New Collections', 'value' => currency($totalCollected), 'color' => '#1fa971'],
        ['label' => 'Total Outstanding', 'value' => currency($totalOutstanding), 'color' => $totalOutstanding > 0 ? '#e5484d' : '#1fa971'],
        ['label' => 'Collection Rate', 'value' => $collectionRate . '%', 'color' => '#f5a623'],
    ];
@endphp
<div class="row g-3 mb-3">
    @foreach($financeStatCards as $card)
        <div class="col-6 col-md-3">
            <div class="finance-stat-card" style="--stat-color: {{ $card['color'] }};">
                <span class="finance-stat-value">{{ $card['value'] }}</span>
                <span class="finance-stat-label">{{ get_phrase($card['label']) }}</span>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="eSection-wrap h-100">
            <div class="title mb-3 pb-0 border-0"><h3 class="mb-0">{{ get_phrase('Invoice Status') }}</h3></div>
            <div class="d-flex justify-content-between mb-2">
                <span><span class="badge bg-success">{{ get_phrase('Paid') }}</span></span>
                <span class="fw-semibold">{{ $statusCounts['paid'] }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span><span class="badge bg-warning">{{ get_phrase('Processing') }}</span></span>
                <span class="fw-semibold">{{ $statusCounts['processing'] }}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span><span class="badge bg-danger">{{ get_phrase('Unpaid') }}</span></span>
                <span class="fw-semibold">{{ $statusCounts['unpaid'] }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="eSection-wrap h-100">
            <div class="title mb-3 pb-0 border-0 d-flex justify-content-between align-items-center">
                <h3 class="mb-0">{{ get_phrase('Top Outstanding Balances') }}</h3>
                <a href="{{ route('accountant.fee_manager.list') }}" class="small">{{ get_phrase('See all') }}</a>
            </div>
            @forelse($topOutstanding as $row)
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>{{ $row->student_name }}{{ $row->class_name ? ' — ' . $row->class_name : '' }}</span>
                    <span class="fw-semibold text-danger">{{ currency($row->balance) }}</span>
                </div>
            @empty
                <p class="text-muted mb-0">{{ get_phrase('No outstanding balances') }}</p>
            @endforelse
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="eSection-wrap h-100">
            <div class="title mb-3 pb-0 border-0"><h3 class="mb-0">{{ get_phrase('By Class') }}</h3></div>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead><tr><th>{{ get_phrase('Class') }}</th><th>{{ get_phrase('Invoiced') }}</th><th>{{ get_phrase('Collected') }}</th><th>{{ get_phrase('Outstanding') }}</th></tr></thead>
                    <tbody>
                    @forelse($classBreakdown as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ currency($row['invoiced']) }}</td>
                            <td>{{ currency($row['collected']) }}</td>
                            <td class="{{ $row['outstanding'] > 0 ? 'text-danger' : 'text-success' }}">{{ currency($row['outstanding']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ get_phrase('No class-based invoices yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="eSection-wrap h-100">
            <div class="title mb-3 pb-0 border-0"><h3 class="mb-0">{{ get_phrase('By Programme') }}</h3></div>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead><tr><th>{{ get_phrase('Programme') }}</th><th>{{ get_phrase('Invoiced') }}</th><th>{{ get_phrase('Collected') }}</th><th>{{ get_phrase('Outstanding') }}</th></tr></thead>
                    <tbody>
                    @forelse($programmeBreakdown as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ currency($row['invoiced']) }}</td>
                            <td>{{ currency($row['collected']) }}</td>
                            <td class="{{ $row['outstanding'] > 0 ? 'text-danger' : 'text-success' }}">{{ currency($row['outstanding']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ get_phrase('No programme-based invoices yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <div class="title mb-3 pb-0 border-0"><h3 class="mb-0">{{ get_phrase('Recent Payments') }}</h3></div>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead><tr><th>{{ get_phrase('Student') }}</th><th>{{ get_phrase('Title') }}</th><th>{{ get_phrase('Paid') }}</th><th>{{ get_phrase('Date') }}</th><th>{{ get_phrase('Status') }}</th></tr></thead>
                    <tbody>
                    @forelse($recentPayments as $row)
                        <tr>
                            <td>{{ $row->student_name }}</td>
                            <td>{{ $row->invoice->title }}</td>
                            <td>{{ currency($row->invoice->collected_amount) }}</td>
                            <td>{{ $row->invoice->timestamp ? date('d M Y', $row->invoice->timestamp) : '—' }}</td>
                            <td><span class="badge bg-{{ $row->invoice->status === 'paid' ? 'success' : 'warning' }}">{{ ucfirst($row->invoice->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ get_phrase('No payments recorded yet this session') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .finance-stat-card {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 16px 18px;
        background: #fff;
        border-radius: 10px;
        border-left: 4px solid var(--stat-color, #cacfd4);
        box-shadow: 0 6px 20px rgba(121, 124, 139, 0.0156862745);
    }
    .finance-stat-value { font-size: 24px; font-weight: 700; line-height: 1.1; color: #181c32; }
    .finance-stat-label { font-size: 12px; color: #797c8b; text-transform: uppercase; letter-spacing: .03em; }
</style>
@endsection
