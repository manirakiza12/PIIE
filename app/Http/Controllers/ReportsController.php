<?php

namespace App\Http\Controllers;

use App\Models\Classes;
use App\Models\DailyAttendances;
use App\Models\Enrollment;
use App\Models\Gradebook;
use App\Models\OnlineExamSubmission;
use App\Models\Programme;
use App\Models\StudentFeeManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportsController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    public function index()
    {
        $school_id = $this->school_id;

        // ── Student stats ──────────────────────────────────────
        $total_students  = User::where('school_id', $school_id)->where('role_id', 7)->count();
        $active_students = User::where('school_id', $school_id)->where('role_id', 7)->where('status', 1)->count();
        $total_staff     = User::where('school_id', $school_id)->whereIn('role_id', [2, 3, 10, 11, 12, 13])->count();

        // By programme — count students via StudentProfile.programme_id, the
        // one reliable currently-enrolled signal for programme-based
        // students (see Programme::activeStudentCount()). Previously this
        // bridged Subject.programme_id -> class_id -> Enrollment.count(),
        // which undercounted (or reported 0 for) any school without
        // class-linked Subject rows tagged to a programme — i.e. every
        // pure-HEI school, including PIIE.
        $programmes = Programme::where('school_id', $school_id)->where('is_active', 1)
            ->get()
            ->map(function ($p) {
                $p->student_count = $p->activeStudentCount();
                return $p;
            })
            ->sortByDesc('student_count');

        // Gender breakdown
        $gender_stats = User::where('school_id', $school_id)
            ->where('role_id', 7)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(student_info, '$.gender')) as gender, COUNT(*) as count")
            ->groupBy('gender')
            ->get();

        // ── Finance stats ──────────────────────────────────────
        $fee_collected = StudentFeeManager::where('school_id', $school_id)
            ->where('status', 'paid')
            ->sum(DB::raw(StudentFeeManager::collectionAmountSql()));
        $fee_invoiced  = StudentFeeManager::where('school_id', $school_id)->sum('total_amount');
        $fee_outstanding = max(0, $fee_invoiced - (float) StudentFeeManager::where('school_id', $school_id)->sum('paid_amount'));

        // Monthly collection (last 6 months)
        $monthly_collections = StudentFeeManager::where('school_id', $school_id)
            ->where('status', 'paid')
            ->selectRaw("DATE_FORMAT(FROM_UNIXTIME(timestamp), '%Y-%m') as month, SUM(" . StudentFeeManager::collectionAmountSql() . ") as total")
            ->groupBy('month')
            ->orderBy('month')
            ->limit(6)
            ->get();

        // ── Attendance stats ───────────────────────────────────
        $today_ts     = strtotime(date('Y-m-d'));
        $today_present = DailyAttendances::where('school_id', $school_id)
            ->where('status', 'present')
            ->where('timestamp', '>=', $today_ts)
            ->where('timestamp', '<', $today_ts + 86400)
            ->count();
        // Enrollment (K-12) + StudentProfile (programme-based) are fully
        // disjoint student populations — sum both so a mixed or pure-HEI
        // school isn't reported as having 0 enrolled students.
        $total_enrolled = Enrollment::where('school_id', $school_id)->count()
            + \App\Models\StudentProfile::where('school_id', $school_id)->where('status', 'active')->count();

        // ── Exam stats ─────────────────────────────────────────
        $total_submissions = 0;
        $passed_submissions = 0;
        if (class_exists(OnlineExamSubmission::class)) {
            $total_submissions  = OnlineExamSubmission::whereHas('exam', fn($q) => $q->where('school_id', $school_id))->count();
            $passed_submissions = OnlineExamSubmission::whereHas('exam', fn($q) => $q->where('school_id', $school_id))->where('passed', 1)->count();
        }

        return view('admin.reports.index', compact(
            'total_students', 'active_students', 'total_staff',
            'programmes', 'gender_stats',
            'fee_collected', 'fee_invoiced', 'fee_outstanding', 'monthly_collections',
            'today_present', 'total_enrolled',
            'total_submissions', 'passed_submissions'
        ));
    }

    public function studentsReport(Request $request)
    {
        $school_id = $this->school_id;
        $students  = User::where('school_id', $school_id)
            ->where('role_id', 7)
            ->with(['enrollment'])
            ->orderBy('name')
            ->paginate(30);
        return view('admin.reports.students', compact('students'));
    }

    public function financeReport(Request $request)
    {
        $school_id = $this->school_id;
        $from = $request->from ?? date('Y-01-01');
        $to   = $request->to   ?? date('Y-12-31');
        $from_ts = strtotime($from);
        $to_ts   = strtotime($to) + 86400;

        $invoiceQuery = StudentFeeManager::where('school_id', $school_id)
            ->where('timestamp', '>=', $from_ts)
            ->where('timestamp', '<', $to_ts);
        if (Schema::hasTable('student_fee_awards')) {
            $invoiceQuery->with(['awards' => fn ($query) => $query->whereNull('revoked_at')]);
        }
        $invoices = $invoiceQuery->orderByDesc('timestamp')->paginate(30);

        $awardAmounts = collect();
        $awardsInPeriod = 0.0;
        if (Schema::hasTable('student_fee_awards')) {
            $awardAmounts = DB::table('student_fee_awards')->where('school_id', $school_id)->whereNull('revoked_at')
                ->whereIn('fee_id', $invoices->getCollection()->pluck('id'))
                ->selectRaw('fee_id, SUM(amount) as award_amount')->groupBy('fee_id')->pluck('award_amount', 'fee_id');
            $awardsInPeriod = (float) DB::table('student_fee_awards')->join('student_fee_managers', 'student_fee_managers.id', '=', 'student_fee_awards.fee_id')
                ->where('student_fee_awards.school_id', $school_id)->whereNull('student_fee_awards.revoked_at')
                ->where('student_fee_managers.timestamp', '>=', $from_ts)->where('student_fee_managers.timestamp', '<', $to_ts)->sum('student_fee_awards.amount');
        }
        $invoices->getCollection()->each(function ($invoice) use ($awardAmounts) {
            $invoice->award_amount = (float) ($awardAmounts[$invoice->id] ?? 0);
        });

        $totals = StudentFeeManager::where('school_id', $school_id)
            ->where('timestamp', '>=', $from_ts)
            ->where('timestamp', '<', $to_ts)
            ->selectRaw('SUM(total_amount) as invoiced, SUM(paid_amount) as paid, SUM(' . StudentFeeManager::collectionAmountSql() . ') as collected')
            ->first();
        $totals->awards = $awardsInPeriod;
        $totals->gross_invoiced = (float) $totals->invoiced + $awardsInPeriod;

        return view('admin.reports.finance', compact('invoices', 'totals', 'from', 'to'));
    }

    public function attendanceReport(Request $request)
    {
        $school_id = $this->school_id;
        $classes   = Classes::where('school_id', $school_id)->get();
        $class_id  = $request->class_id ?? ($classes->first()?->id ?? null);

        $date_from = $request->from ?? date('Y-m-01');
        $date_to   = $request->to   ?? date('Y-m-d');
        $from_ts   = strtotime($date_from);
        $to_ts     = strtotime($date_to) + 86400;

        $attendance = DailyAttendances::where('school_id', $school_id)
            ->where('class_id', $class_id)
            ->where('timestamp', '>=', $from_ts)
            ->where('timestamp', '<', $to_ts)
            ->selectRaw('student_id, SUM(status="present") as present_days, SUM(status="absent") as absent_days, COUNT(*) as total_days')
            ->groupBy('student_id')
            ->with('student')
            ->get();

        return view('admin.reports.attendance', compact('classes', 'class_id', 'date_from', 'date_to', 'attendance'));
    }

    public function examsReport(Request $request)
    {
        $school_id = $this->school_id;
        $submissions = collect();
        if (class_exists(OnlineExamSubmission::class)) {
            $submissions = OnlineExamSubmission::whereHas('exam', fn($q) => $q->where('school_id', $school_id))
                ->with(['exam', 'student'])
                ->orderByDesc('created_at')
                ->paginate(30);
        }
        return view('admin.reports.exams', compact('submissions'));
    }

    public function export($type, Request $request)
    {
        // Simple CSV export
        $school_id = $this->school_id;
        $filename  = "{$type}_report_" . date('Y-m-d') . ".csv";

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($type, $school_id, $request) {
            $out = fopen('php://output', 'w');
            if ($type === 'students') {
                fputcsv($out, ['#', 'Name', 'Email', 'Code', 'Status', 'Enrolled']);
                User::where('school_id', $school_id)->where('role_id', 7)->orderBy('name')
                    ->each(function ($s, $i) use ($out) {
                        fputcsv($out, [$i+1, $s->name, $s->email, $s->code, $s->status ? 'Active' : 'Inactive', $s->created_at->format('Y-m-d')]);
                    });
            } elseif ($type === 'finance') {
                fputcsv($out, ['#', 'Title', 'Gross billed', 'Bursary / scholarship', 'Net payable', 'Paid balance', 'Cash collected', 'Status']);
                $fees = StudentFeeManager::where('school_id', $school_id);
                if (Schema::hasTable('student_fee_awards')) {
                    $fees->with(['awards' => fn ($query) => $query->whereNull('revoked_at')]);
                }
                $fees->orderByDesc('id')->each(function ($f, $i) use ($out) {
                    $awards = $f->relationLoaded('awards') ? (float) $f->awards->sum('amount') : 0.0;
                    fputcsv($out, [$i+1, $f->title, (float) $f->total_amount + $awards, $awards, $f->total_amount,
                        $f->paid_amount, $f->collected_amount, $f->status]);
                });
            } elseif ($type === 'attendance') {
                $classes  = Classes::where('school_id', $school_id)->get();
                $class_id = $request->class_id ?? ($classes->first()?->id ?? null);
                $date_from = $request->from ?? date('Y-m-01');
                $date_to   = $request->to   ?? date('Y-m-d');
                $from_ts   = strtotime($date_from);
                $to_ts     = strtotime($date_to) + 86400;

                fputcsv($out, ['#', 'Student', 'Present days', 'Absent days', 'Total days', 'Attendance %']);
                DailyAttendances::where('school_id', $school_id)
                    ->where('class_id', $class_id)
                    ->where('timestamp', '>=', $from_ts)
                    ->where('timestamp', '<', $to_ts)
                    ->selectRaw('student_id, SUM(status="present") as present_days, SUM(status="absent") as absent_days, COUNT(*) as total_days')
                    ->groupBy('student_id')
                    ->with('student')
                    ->get()
                    ->each(function ($a, $i) use ($out) {
                        $pct = $a->total_days > 0 ? round($a->present_days / $a->total_days * 100) : 0;
                        fputcsv($out, [$i+1, optional($a->student)->name ?? "Student #{$a->student_id}", $a->present_days, $a->absent_days, $a->total_days, $pct]);
                    });
            } elseif ($type === 'exams') {
                fputcsv($out, ['#', 'Student', 'Exam', 'Score', 'Total', '%', 'Result', 'Submitted']);
                if (class_exists(OnlineExamSubmission::class)) {
                    OnlineExamSubmission::whereHas('exam', fn($q) => $q->where('school_id', $school_id))
                        ->with(['exam', 'student'])
                        ->orderByDesc('created_at')
                        ->get()
                        ->each(function ($sub, $i) use ($out) {
                            $pct = $sub->result_total_marks > 0 ? round($sub->score / $sub->result_total_marks * 100, 1) : 0;
                            fputcsv($out, [
                                $i+1,
                                optional($sub->student)->name ?? '—',
                                optional($sub->exam)->title ?? '—',
                                $sub->score,
                                $sub->result_total_marks,
                                $pct,
                                is_null($sub->passed) ? 'Pending' : ($sub->passed ? 'Pass' : 'Fail'),
                                $sub->created_at?->format('Y-m-d H:i'),
                            ]);
                        });
                }
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}
