@extends('student.navigation')

@section('content')

    <div class="mainSection-title">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                    <div class="d-flex flex-column">
                        <h4>{{ get_phrase('Hostel Fee Manager') }}</h4>
                        <ul class="d-flex align-items-center eBreadcrumb-2">
                            <li><a href="#">{{ get_phrase('Home') }}</a></li>
                            <li><a href="#">{{ get_phrase('Hostel') }}</a></li>
                            <li><a href="#">{{ get_phrase('Hostel Fee Manager') }}</a></li>
                        </ul>
                    </div>


                    @php
                        $application = App\Models\HostelApplication::where('status', 1)
                            ->where('school_id', auth()->user()->school_id)
                            ->where('student_id', auth()->user()->id)
                            ->first();

                        $payments = App\Models\HostelFee::where('status', 1)
                            ->where('school_id', auth()->user()->school_id)
                            ->where('student_id', auth()->user()->id)
                            ->paginate(20);

                        $date = Carbon\Carbon::createFromDate(now()->year, now()->month, 1);
                        $startOfMonth = $date->copy()->startOfMonth()->startOfDay();
                        $endOfMonth = $date->copy()->endOfMonth()->endOfDay();

                        $feePaid = \App\Models\HostelFee::where('school_id', auth()->user()->school_id)
                            ->where('student_id', auth()->user()->id)
                            ->where('status', 1)
                            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                            ->first();
                    @endphp

                    <div class="d-flex justify-content-end mb-3">

                        {{-- @if ($application && $allocation)
                            @php
                                $currentMonthFee = \App\Models\HostelFee::where('school_id', auth()->user()->school_id)
                                    ->where('student_id', auth()->user()->id)
                                    ->whereYear('created_at', now()->year)
                                    ->whereMonth('created_at', now()->month)
                                    ->where('status', 'paid')
                                    ->f irst();
                            @endphp

                            @if ($currentMonthFee)
                                <a href="#" class="btn btn-success disabled">
                                    {{ get_phrase('Hostel Fees Paid for ' . date('M Y')) }}
                                </a>
                            @else
                                <div class="d-flex flex-column align-items-center">
                                    <!-- Create a new hostel fee record and redirect to payment -->
                                    <a href="{{ route('student.hostel_fee.payment', ['id' => 'new']) }}" class="btn btn-primary">
                                        {{ get_phrase('Pay Hostel Fees ' . date('M Y')) }}
                                    </a>
                                </div>
                            @endif
                        @endif --}}
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="invoice_content">
        <div class="eSection-wrap">
            <div class="table-responsive">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>{{ get_phrase('Month') }}</th>
                            <th>{{ get_phrase('Paid On') }}</th>
                            <th>{{ get_phrase('Hostel') }}</th>
                            <th>{{ get_phrase('Amount') }}</th>
                            <th>{{ get_phrase('Status') }}</th>
                            <th>{{ get_phrase('Payment') }}</th>
                        </tr>
                    </thead>

                    <tbody>

                        @if (!$application || !$application->accepted_at)
                        <tr>
                            <td colspan="6" class="text-center">{{ get_phrase('No data found') }}</td>
                        </tr>
                        @else
                        @php
                            $startMonth = $application->accepted_at->format('m');
                            $startYear = $application->accepted_at->format('Y');
                            $currentYear = now()->year;
                            $currentMonth = now()->month;
                        @endphp

                        @for ($y = $startYear; $y <= $currentYear; $y++)
                            @for ($m = 1; $m <= 12; $m++)
                                {{-- Skip months before admission --}}
                                @if ($y == $startYear && $m < $startMonth)
                                    @continue
                                @endif

                                {{-- Skip future months --}}
                                @if ($y == $currentYear && $m > $currentMonth)
                                    @continue
                                @endif

                                @php
                                    $billedDate = Carbon\Carbon::createFromDate($y, $m, 1);

                                    $payment = App\Models\HostelFee::where('school_id', auth()->user()->school_id)
                                        ->where('student_id', auth()->user()->id)
                                        ->whereYear('fee_payment_date', $y)
                                        ->whereMonth('fee_payment_date', $m)
                                        ->first();
                                @endphp

                                <tr>
                                    <td>{{ $billedDate->format('Y - F') }}</td>

                                    <td>{{ $payment?->created_at?->format('d M Y') ?? '---' }}</td>

                                    <td>{{ $application->hostel?->name }} - {{ $application->room?->room_no }}</td>

                                    <td>{{ currency($application->room?->seat_fee) }}</td>

                                    <td>
                                        @if ($payment)
                                            @if ($payment->status == 1)
                                                <span class="eBadge ebg-success">{{ get_phrase('Paid') }}</span>
                                            @elseif ($payment->status == 2)
                                                <span class="eBadge ebg-danger">{{ get_phrase('Rejected') }}</span>
                                            @else
                                                <span class="eBadge ebg-warning">{{ get_phrase('Pending') }}</span>
                                            @endif
                                        @else
                                            <span class="eBadge ebg-danger">{{ get_phrase('Unpaid') }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        @if ($payment)
                                            @if ($payment->status == 1)
                                                ---
                                            @elseif ($payment->status == 2)
                                                {{-- Rejected → allow to pay again --}}
                                                <form action="{{ route('student.hostel_fee.payment') }}">
                                                    <input type="hidden" name="year" value="{{ $y }}">
                                                    <input type="hidden" name="month" value="{{ $m }}">
                                                    <button type="submit" class="btn btn-primary">
                                                        {{ get_phrase('Pay Now') }}
                                                    </button>
                                                </form>
                                            @else
                                                <button type="button" class="btn btn-secondary" disabled>
                                                    {{ get_phrase('Pending') }}
                                                </button>
                                            @endif
                                        @else
                                            <form action="{{ route('student.hostel_fee.payment') }}">
                                                <input type="hidden" name="year" value="{{ $y }}">
                                                <input type="hidden" name="month" value="{{ $m }}">
                                                <button type="submit" class="btn btn-primary">
                                                    {{ get_phrase('Pay Now') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endfor
                        @endfor
                        @endif

                    </tbody>
                </table>

            </div>
        </div>
    </div>

    <script type="text/javascript">
        "use strict";

        function generatePDF() {
            const element = document.getElementById("student_hostel_fee_report");
            var clonedElement = element.cloneNode(true);
            $(clonedElement).css("display", "block");

            var opt = {
                margin: 1,
                filename: 'hostel_fee-{{ date('d-M-Y', $date_from) . '-' . date('d-M-Y', $date_to) }}.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2
                }
            };

            window.exportReportPdf(clonedElement, opt.filename);
            clonedElement.remove();
        }

        function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }
    </script>
@endsection
