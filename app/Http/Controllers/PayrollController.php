<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Payroll\PayrollEligibility;
use App\Support\Payroll\PayrollService;
use App\Support\Payroll\UgandaPayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDF;

/**
 * Payroll administration (see App\Support\Payroll\PayrollService for the
 * business rules). Controller-level responsibilities only:
 *   - request validation and authorisation context (school of the actor),
 *   - assembling view data for the dashboard / preview / reports,
 *   - routing legacy CSV + PDF endpoints.
 */
class PayrollController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    // ── Salary Structures ────────────────────────────────────────────────

    public function salaryIndex()
    {
        $structures = SalaryStructure::where('school_id', $this->school_id)
            ->with('user')
            ->orderByDesc('effective_from')
            ->paginate(20);
        $staff = PayrollEligibility::activeStaff($this->school_id)->get();
        return view('admin.payroll.salary_structures', compact('structures', 'staff'));
    }

    public function salaryModal()
    {
        $staff = PayrollEligibility::activeStaff($this->school_id)->get();
        return view('admin.payroll.salary_modal', compact('staff'));
    }

    public function storeSalary(Request $request)
    {
        $validated = $request->validate([
            'user_id'          => 'required|integer|exists:users,id',
            'basic'            => 'required|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'housing'          => 'nullable|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'transport'        => 'nullable|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'medical'          => 'nullable|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'other_earnings'   => 'nullable|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'payment_method'   => 'nullable|in:bank,cash,mobile_money',
            'bank_account'     => 'nullable|string|max:100',
            'effective_from'   => 'required|date_format:Y-m-d',
        ]);
        $money = ['basic', 'housing', 'transport', 'medical', 'other_earnings'];
        foreach ($money as $field) {
            $validated[$field] = number_format((float) ($validated[$field] ?? 0), 2, '.', '');
        }
        $validated['payment_method'] = $validated['payment_method'] ?? 'bank';
        $validated['bank_account'] = $validated['bank_account'] ?? null;
        $validated['school_id'] = $this->school_id;
        [$structure, $created] = DB::transaction(function () use ($validated, $money) {
            // Lock a stable parent row, including when no salary row exists yet.
            // Every save through this endpoint for the employee serializes here.
            $employee = User::where('school_id', $this->school_id)
                ->whereKey($validated['user_id'])->lockForUpdate()->firstOrFail();
            if (!PayrollEligibility::isEligible($employee)) {
                throw ValidationException::withMessages(['user_id' => 'Select an eligible employee.']);
            }
            $sameDate = SalaryStructure::where('school_id', $this->school_id)
                ->where('user_id', $employee->id)
                ->whereDate('effective_from', $validated['effective_from'])->lockForUpdate()->get();
            if ($sameDate->isNotEmpty()) {
                $existing = $sameDate->first();
                $identical = $sameDate->count() === 1;
                foreach ($money as $field) {
                    $identical = $identical && number_format((float) $existing->$field, 2, '.', '') === $validated[$field];
                }
                $identical = $identical && ($existing->payment_method ?: 'bank') === $validated['payment_method']
                    && ($existing->bank_account ?? '') === ($validated['bank_account'] ?? '');
                if (!$identical) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'A different or ambiguous salary structure already exists for this employee and effective date. Existing history was not changed.',
                    ]);
                }
                return [$existing, false];
            }
            $structure = SalaryStructure::create($validated);
            AuditLog::record('create', 'Payroll', "Salary structure created for user #{$employee->id}");
            return [$structure, true];
        }, 3);
        $message = get_phrase($created ? 'Salary structure saved' : 'Salary structure already saved');
        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => $message, 'created' => $created, 'id' => $structure->id]);
        }
        return redirect()->route('admin.salary_structures.index')->with('success', $message);
    }

    // ── Dashboard ────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $month  = $this->resolvePeriod($request->get('month'));
        $period = $month . '-01';
        $search = trim((string) $request->get('search', ''));
        $departmentId = $request->get('department_id');
        $status = trim((string) $request->get('status', ''));

        $allowedSortFields = ['name', 'pay_period', 'net_pay', 'status', 'created_at'];
        $requestedSort = $request->get('sort');
        $isValidSort = in_array($requestedSort, $allowedSortFields, true);
        $sortField = $isValidSort ? $requestedSort : 'name';
        $sortDir = $isValidSort && strtolower($request->get('dir')) === 'desc' ? 'desc' : 'asc';

        $payrolls = Payroll::where('payroll.school_id', $this->school_id)
            ->where('payroll.pay_period', PayrollService::periodValue($period))
            ->when($departmentId, fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('department_id', $departmentId)))
            ->when($search !== '', fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($status !== '' && $status !== 'all', function ($q) use ($status) {
                if ($status === 'rejected') {
                    $q->where('payroll.status', 'draft')->whereNotNull('payroll.rejected_at');
                } elseif ($status === 'pending') {
                    $q->where('payroll.status', 'draft')->whereNull('payroll.rejected_at');
                } elseif ($status === 'partially_paid') {
                    $q->where('payroll.status', 'approved')->where('payroll.paid_amount', '>', 0);
                } elseif (in_array($status, ['draft', 'approved', 'paid'], true)) {
                    $q->where('payroll.status', $status);
                }
            })
            ->join('users', 'payroll.staff_id', '=', 'users.id')
            ->select('payroll.*')
            ->with('staff')
            ->orderBy($sortField, $sortDir)
            ->paginate(30)
            ->withQueryString();

        $service   = app(PayrollService::class);
        $stats     = $service->summary($this->school_id, $period);
        $headcount = [
            'employees'     => PayrollEligibility::activeStaff($this->school_id)->count(),
            'teaching'      => PayrollEligibility::activeStaff($this->school_id)->where('role_id', 3)->count(),
            'non_teaching'  => PayrollEligibility::activeStaff($this->school_id)->where('role_id', '!=', 3)->count(),
        ];
        $departments = Department::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('admin.payroll.index', compact(
            'payrolls', 'month', 'stats', 'headcount', 'search', 'departments', 'departmentId', 'period', 'status'
        ));
    }

    /** Read-only preview of who the next payroll run will cover. */
    public function preview(Request $request)
    {
        $month  = $this->resolvePeriod($request->get('month'));
        $period = $month . '-01';
        $data   = app(PayrollService::class)->preview($this->school_id, $period, $request->get('department_id') ?: null);
        $departments = Department::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('admin.payroll.preview', ['data' => $data, 'month' => $month, 'departments' => $departments, 'departmentId' => $request->get('department_id')]);
    }

    // ── Generation (POST — this is the route that used to 405 because the view linked to it with a GET anchor) ──

    public function generate(Request $request)
    {
        if (!$request->isMethod('post')) {
            return redirect()->route('admin.payroll.index', $request->only(['month', 'department_id']));
        }

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $result = app(PayrollService::class)
            ->generate($this->school_id, Auth::id(), $validated['month'] . '-01', $request->get('department_id') ?: null);

        $messages = [
            get_phrase('Payroll generated for') . " {$result['generated']} " . get_phrase('staff members') . sprintf(' (%s %s).', $validated['month'], get_phrase('NET')) . ' UGX ' . number_format($result['total_net'], 2),
        ];
        if ($result['skipped_existing'] > 0) {
            $messages[] = get_phrase('Already generated (skipped)') . ": {$result['skipped_existing']}";
        }
        if ($result['skipped_no_structure'] !== []) {
            $messages[] = get_phrase('No salary structure') . ': ' . implode(', ', $result['skipped_no_structure']);
        }
        $flashType = $result['generated'] > 0 ? 'success' : 'info';

        return redirect()->route('admin.payroll.index', ['month' => $validated['month']])
            ->with($flashType, implode(' — ', $messages))
            ->with('message', implode(' — ', $messages));
    }

    // ── Approval workflow ────────────────────────────────────────────────

    public function approve(Request $request, $id)
    {
        app(PayrollService::class)->approve($this->school_id, Auth::id(), (int) $id);

        return redirect()->back()->with('success', get_phrase('Payslip approved'))
            ->with('message', get_phrase('Payslip approved'));
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['rejection_reason' => 'required|string|min:5|max:255']);
        app(PayrollService::class)->reject($this->school_id, Auth::id(), (int) $id, $validated['rejection_reason']);

        return redirect()->back()->with('success', get_phrase('Payslip rejected'))
            ->with('message', get_phrase('Payslip rejected'));
    }

    public function destroy(Request $request, $id)
    {
        app(PayrollService::class)->deleteDraft($this->school_id, (int) $id);

        return redirect()->back()->with('success', get_phrase('Draft payslip deleted'))
            ->with('message', get_phrase('Draft payslip deleted'));
    }

    // ── Payment recording ────────────────────────────────────────────────

    public function pay(Request $request, $id)
    {
        $validated = $request->validate([
            'payment_amount'    => 'required|numeric|min:0.01',
            'payment_method'    => 'required|in:bank,cash,mobile_money',
            'payment_reference' => 'required|string|max:100',
            'paid_on'           => 'nullable|date',
        ]);

        app(PayrollService::class)->recordPayment(
            $this->school_id,
            Auth::id(),
            (int) $id,
            (float) $validated['payment_amount'],
            $validated['payment_method'],
            $validated['payment_reference'],
            $validated['paid_on'] ?? null,
        );

        return redirect()->back()->with('success', get_phrase('Payment recorded'))
            ->with('message', get_phrase('Payment recorded'));
    }

    public function paymentHistory($id)
    {
        $pay      = Payroll::where('school_id', $this->school_id)->with('staff')->findOrFail($id);
        $payments = app(PayrollService::class)->paymentHistory($this->school_id, (int) $id);

        return view('admin.payroll.payment_history', compact('pay', 'payments'));
    }

    // ── Reporting ────────────────────────────────────────────────────────

    public function reports(Request $request)
    {
        $year = $request->get('year') ?: now()->year;
        abort_unless(ctype_digit((string) $year) && (int) $year >= 2020 && (int) $year <= 2100, 400, 'Invalid year.');

        // Canonical period values match the DATE column on every engine
        // (see PayrollService::periodValue).
        $periods = array_map(fn ($month) => PayrollService::periodValue(sprintf('%s-%02d', $year, $month)), range(1, 12));
        $periodKeys = range(1, 12);

        $monthly = Payroll::where('school_id', $this->school_id)
            ->whereIn('pay_period', $periods)
            ->selectRaw('pay_period,
                SUM(basic_salary + allowances + other_earnings) as gross,
                SUM(nssf + tax + deductions) as employee_deductions,
                SUM(employer_nssf) as employer_cost,
                SUM(net_pay) as net,
                SUM(paid_amount) as paid')
            ->groupBy('pay_period')
            ->orderBy('pay_period')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->pay_period, 0, 7));

        $departmental = Payroll::where('payroll.school_id', $this->school_id)
            ->whereIn('payroll.pay_period', $periods)
            ->join('users', 'payroll.staff_id', '=', 'users.id')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->selectRaw("COALESCE(departments.name, '— Unassigned —') as department,
                COUNT(*) as employees,
                SUM(payroll.basic_salary + payroll.allowances + payroll.other_earnings) as gross,
                SUM(payroll.net_pay) as net,
                SUM(payroll.paid_amount) as paid")
            ->groupByRaw("COALESCE(departments.name, '— Unassigned —')")
            ->orderBy('department')
            ->get();

        $yearTotals = Payroll::where('payroll.school_id', $this->school_id)
            ->whereIn('payroll.pay_period', $periods)
            ->selectRaw('SUM(basic_salary + allowances + other_earnings) as gross,
                SUM(nssf) as nssf, SUM(tax) as paye, SUM(employer_nssf) as employer_cost,
                SUM(net_pay) as net, SUM(paid_amount) as paid,
                SUM(net_pay - paid_amount) as outstanding')
            ->first();

        return view('admin.payroll.reports', compact('year', 'periodKeys', 'monthly', 'departmental', 'yearTotals'));
    }

    // ── Legacy endpoints (kept: CSV export + payslip PDF) ────────────────

    public function printSlip($id)
    {
        $pay = Payroll::where('school_id', $this->school_id)->with(['staff', 'payments'])->findOrFail($id);
        $school = DB::table('schools')->find($this->school_id);
        $payments = $pay->payments;

        $pdf = PDF::loadView('admin.payroll.payslip_pdf', compact('pay', 'school', 'payments'));
        return $pdf->download("Payslip_{$pay->staff->name}_{$pay->pay_period->format('Y_m')}.pdf");
    }

    public function exportCsv(Request $request)
    {
        $month  = $this->resolvePeriod($request->month ?? null);
        $period = $month . '-01';
        $filename = "payroll_{$month}.csv";

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $rows = Payroll::where('school_id', $this->school_id)
            ->where('pay_period', PayrollService::periodValue($period))
            ->with('staff')
            ->get();

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Staff', 'Period', 'Basic', 'Allowances', 'Other', 'Gross', 'PAYE', 'NSSF (employee)', 'NSSF (employer)', 'Other deductions', 'Net Pay', 'Paid', 'Status']);
            $rows->each(function ($p, $i) use ($out) {
                fputcsv($out, [
                    $i + 1,
                    optional($p->staff)->name,
                    $p->pay_period?->format('Y-m'),
                    number_format($p->basic_salary, 2),
                    number_format($p->allowances, 2),
                    number_format($p->other_earnings, 2),
                    number_format($p->grossPay(), 2),
                    number_format($p->tax, 2),
                    number_format($p->nssf, 2),
                    number_format($p->employer_nssf, 2),
                    number_format($p->deductions, 2),
                    number_format($p->net_pay, 2),
                    number_format($p->paid_amount, 2),
                    $p->status . ($p->isRejected() ? ' (rejected)' : ''),
                ]);
            });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Staff self-service (teacher portal) ──────────────────────────────

    public function staffPayslips()
    {
        $payslips = Payroll::where('school_id', Auth::user()->school_id)
            ->where('staff_id', Auth::id())
            ->orderByDesc('pay_period')
            ->paginate(12);
        return view('teacher.payroll.index', ['payrolls' => $payslips]);
    }

    public function staffPayslipPrint($id)
    {
        $pay = Payroll::where('school_id', Auth::user()->school_id)
            ->where('staff_id', Auth::id())
            ->with(['staff', 'payments'])
            ->findOrFail($id);
        $school = DB::table('schools')->find(Auth::user()->school_id);
        $payments = $pay->payments;

        $pdf = PDF::loadView('admin.payroll.payslip_pdf', compact('pay', 'school', 'payments'));
        return $pdf->download("Payslip_{$pay->staff->name}_{$pay->pay_period->format('Y_m')}.pdf");
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function resolvePeriod($input): string
    {
        if ($input && preg_match('/^\d{4}-\d{2}$/', (string) $input)) {
            [$y, $m] = explode('-', $input);
            if ((int) $y >= 2020 && (int) $y <= 2100 && (int) $m >= 1 && (int) $m <= 12) {
                return $input;
            }
        }

        return now()->format('Y-m');
    }
}
