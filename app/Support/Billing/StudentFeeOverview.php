<?php

namespace App\Support\Billing;

use App\Models\{Session, StudentFeeAward, StudentFeeManager, StudentFinancialSetupReview, TuitionPayment, User};
use App\Support\Payments\DecimalAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Read-only session balances. Student discovery must never depend on an invoice or placement. */
final class StudentFeeOverview
{
    public function sessionId(Request $request): int
    {
        $schoolId = (int) $request->user()->school_id;
        $request->validate(['fee_session_id' => ['nullable', 'integer', Rule::exists('sessions', 'id')->where('school_id', $schoolId)]]);
        return $request->filled('fee_session_id') ? $request->integer('fee_session_id')
            : (int) get_school_settings($schoolId)->value('running_session');
    }

    public function build(Request $request): array
    {
        $schoolId = (int) $request->user()->school_id;
        $sessionId = $this->sessionId($request);
        $request->validate(['fee_search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) $request->input('fee_search', ''));
        // Use the application's active-account rule. A missing profile or academic placement is not a finance exclusion.
        $students = User::where('school_id', $schoolId)->where('role_id', 7)
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name', 'email', 'code'], 'students_page')->withQueryString();
        $fees = StudentFeeManager::where('school_id', $schoolId)->where('session_id', $sessionId)
            ->whereIn('student_id', $students->pluck('id'))->orderBy('id')->get();
        $feeIds = $fees->pluck('id');
        $awards = Schema::hasTable('student_fee_awards') ? StudentFeeAward::where('school_id', $schoolId)
            ->whereIn('fee_id', $feeIds)->whereNull('revoked_at')->get()->groupBy('fee_id') : collect();
        $reviews = Schema::hasTable('student_financial_setup_reviews') ? StudentFinancialSetupReview::where('school_id', $schoolId)
            ->where('session_id', $sessionId)->whereIn('fee_id', $feeIds)->get()->keyBy('fee_id') : collect();
        $pendingPayments = Schema::hasTable('tuition_payments') ? TuitionPayment::where('school_id', $schoolId)
            ->whereIn('fee_id', $feeIds)->where('status', 'pending')->get()->groupBy('fee_id') : collect();
        $fees->each(function ($fee) use ($awards, $reviews, $pendingPayments) {
            $prior = DecimalAmount::minorUnits($fee->opening_paid_amount ?? 0) ?? 0;
            $paid = DecimalAmount::minorUnits($fee->paid_amount ?? 0) ?? 0;
            $total = DecimalAmount::minorUnits($fee->total_amount ?? 0) ?? 0;
            $award = ($awards->get($fee->id) ?? collect())->sum(fn ($row) => DecimalAmount::minorUnits($row->amount) ?? 0);
            $review = $reviews->get($fee->id);
            // A malformed cross-student review must never disclose details or count as this student's confirmation.
            if ($review && (int) $review->student_id !== (int) $fee->student_id) $review = null;
            $pending = [];
            foreach (['prior_payment' => 'Prior cash', 'award' => 'Award', 'study_waiver' => 'Study waiver'] as $item => $label) {
                if ($review && $review->{$item . '_status'} === 'pending') {
                    $amount = $review->{$item}['amount'] ?? null;
                    $pending[] = $label . ($amount !== null ? ' ' . $amount : '') . ' awaiting confirmation';
                }
            }
            foreach ($pendingPayments->get($fee->id) ?? [] as $payment) $pending[] = 'New payment ' . $payment->amount . ' awaiting provider confirmation';
            $fee->setAttribute('overview', [
                'base' => DecimalAmount::minorUnits($fee->amount ?? $fee->total_amount) ?? 0,
                'discount' => DecimalAmount::minorUnits($fee->discounted_price ?? 0) ?? 0,
                'fee' => $total, 'prior' => $prior, 'new' => max(0, $paid - $prior), 'awards' => $award,
                'balance' => max(0, $total - $paid), 'pending' => $pending,
                'reconcile' => $prior > $paid,
            ]);
        });
        $byStudent = $fees->groupBy('student_id');
        $students->getCollection()->each(function ($student) use ($byStudent) {
            $invoices = $byStudent->get($student->id) ?? collect();
            $student->setRelation('sessionInvoices', $invoices);
            $student->setAttribute('fee_summary', collect(['base', 'discount', 'fee', 'prior', 'new', 'awards', 'balance'])
                ->mapWithKeys(fn ($key) => [$key => $invoices->sum(fn ($fee) => $fee->overview[$key])])->all());
        });
        return ['feeStudents' => $students, 'feeSessions' => Session::where('school_id', $schoolId)->orderByDesc('id')->get(),
            'feeSessionId' => $sessionId];
    }

    public function reportFilters(Request $request): array
    {
        $request->validate(['eDateRange' => ['nullable', 'string'], 'class' => ['nullable', 'regex:/\A(all|[0-9]+)\z/'],
            'programme' => ['nullable', 'regex:/\A(all|[0-9]+)\z/'], 'status' => ['nullable', 'in:all,paid,unpaid,pending']]);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        if ($request->filled('eDateRange')) {
            $range = explode(' - ', $request->input('eDateRange'));
            $valid = count($range) === 2;
            foreach ($range as $date) {
                $parsed = \DateTimeImmutable::createFromFormat('!m/d/Y', $date);
                $valid = $valid && $parsed && $parsed->format('m/d/Y') === $date;
            }
            if (! $valid) throw \Illuminate\Validation\ValidationException::withMessages(['eDateRange' => 'Use MM/DD/YYYY - MM/DD/YYYY for invoice creation dates.']);
            $from = \Carbon\Carbon::createFromFormat('!m/d/Y', $range[0])->startOfDay();
            $to = \Carbon\Carbon::createFromFormat('!m/d/Y', $range[1])->endOfDay();
            if ($from->gt($to)) throw \Illuminate\Validation\ValidationException::withMessages(['eDateRange' => 'The invoice end date must follow its start date.']);
        }
        return ['date_from' => $from->timestamp, 'date_to' => $to->timestamp,
            'selected_class' => $request->input('class') ?: 'all', 'selected_status' => $request->input('status') ?: 'all',
            'selected_programme' => $request->input('programme') ?: 'all'];
    }
}
