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
            'user_id'          => 'required|exists:users,id',
            'basic'            => 'required|numeric|min:0',
            'housing'          => 'nullable|numeric|min:0',
            'transport'        => 'nullable|numeric|min:0',
            'medical'          => 'nullable|numeric|min:0',
            'other_earnings'   => 'nullable|numeric|min:0',
            'payment_method'   => 'nullable|in:bank,cash,mobile_money',
            'bank_account'     => 'nullable|string|max:100',
            'effective_from'   => 'required|date',
        ]);
        // Tenant guard: the employee must belong to the actor's school.
        $employee = User::where('id', $validated['user_id'])->where('school_id', $this->school_id)->first();
        abort_unless($employee, 404);
        $validated = array_merge([
            'other_earnings' => 0,
            'payment_method' => 'bank',
        ], $validated);
        $validated['school_id'] = $this->school_id;
        SalaryStructure::create($validated);
        AuditLog::record('create', 'Payroll', "Salary structure created for user #{$validated['user_id']}");
        return response()->json(['status' => 'success', 'message' => get_phrase('Salary structure saved')]);
    }

    // ── Dashboard ────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $month  = $this->resolvePeriod($request->get('month'));
        $period = $month . '-01';
        $search = trim((string) $request->get('search', ''));
        $departmentId = $request->get('department_id');
        $status = trim((string) $request->get('status', ''));

        $payrolls = Payroll::where('school_id', $this->school_id)
            ->where('pay_period', PayrollService::periodValue($period))
            ->when($departmentId, fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('department_id', $departmentId)))
            ->when($search !== '', fn ($q) => $q->whereHas('staff', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($status !== '' && $status !== 'all', function ($q) use ($status) {
                if ($status === 'rejected') {
                    $q->where('status', 'draft')->whereNotNull('rejected_at');
                } elseif ($status === 'pending') {
                    $q->where('status', 'draft')->whereNull('rejected_at');
                } elseif ($status === 'partially_paid') {
                    $q->where('status', 'approved')->where('paid_amount', '>', 0);
                } elseif (in_array($status, ['draft', 'approved', 'paid'], true)) {
                    $q->where('status', $status);
                }
            })
            ->with('staff')
            ->orderBy('name')
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
            ->with($flashType, implode(' — ', $messages));
    }

    // ── Approval workflow ────────────────────────────────────────────────

    public function approve(Request $request, $id)
    {
        app(PayrollService::class)->approve($this->school_id, Auth::id(), (int) $id);

        return redirect()->back()->with('success', get_phrase('Payslip approved'));
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['rejection_reason' => 'required|string|min:5|max:255']);
        app(PayrollService::class)->reject($this->school_id, Auth::id(), (int) $id, $validated['rejection_reason']);

        return redirect()->back()->with('success', get_phrase('Payslip rejected'));
    }

    public function destroy(Request $request, $id)
    {
        app(PayrollService::class)->deleteDraft($this->school_id, (int) $id);

        return redirect()->back()->with('success', get_phrase('Draft payslip deleted'));
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

        return redirect()->back()->with('success', get_phrase('Payment recorded'));
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
