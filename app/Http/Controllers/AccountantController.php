<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Classes;
use App\Models\Programme;
use App\Models\StudentFeeManager;
use App\Models\Session;
use App\Models\ExpenseCategory;
use App\Models\Expense;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\Noticeboard;
use App\Models\FrontendEvent;
use App\Models\MessageThrade;
use App\Models\Chat;
use App\Support\StudentFeeInvoiceGenerator;

use Illuminate\Support\Facades\DB;
use App\Support\ProfilePhoto;
use App\Support\Audit\StatusChangeAudit;

class AccountantController extends Controller
{
    /**
     * Show the accountant/bursar dashboard — real finance KPIs (invoiced,
     * collected, outstanding, collection rate, per-class/programme
     * breakdown, recent payments, biggest outstanding balances) instead of
     * the previous generic Students/Teachers/Parents/Staff counts, which
     * told a finance user nothing about the one thing their role owns:
     * money. Also queried the dead, unused `enrollments` (plural) table —
     * same bug this session already fixed on the student dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function accountantDashboard()
    {
        $schoolId = auth()->user()->school_id;
        $activeSession = get_school_settings($schoolId)->value('running_session');

        $invoices = StudentFeeManager::where('school_id', $schoolId)
            ->where('session_id', $activeSession)
            ->get();

        $totalInvoiced = (float) $invoices->sum('total_amount');
        $totalCollected = (float) $invoices->sum('paid_amount');
        $totalOutstanding = (float) $invoices->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount));
        $collectionRate = $totalInvoiced > 0 ? round($totalCollected / $totalInvoiced * 100, 1) : 0;

        $statusCounts = [
            'paid' => $invoices->where('status', 'paid')->count(),
            'processing' => $invoices->where('status', 'processing')->count(),
            'unpaid' => $invoices->where('status', 'unpaid')->count(),
        ];

        // Per-class breakdown (class_id = 0 is the "not class-based"
        // sentinel for Programme-track students — excluded here, covered
        // by the per-programme breakdown instead).
        $classBreakdown = $invoices->where('class_id', '>', 0)
            ->groupBy('class_id')
            ->map(fn ($group, $classId) => [
                'name' => Classes::find($classId)?->name ?? get_phrase('Unknown'),
                'invoiced' => (float) $group->sum('total_amount'),
                'collected' => (float) $group->sum('paid_amount'),
                'outstanding' => (float) $group->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount)),
            ])
            ->sortByDesc('outstanding')
            ->values();

        $programmeBreakdown = $invoices->whereNotNull('programme_id')->where('programme_id', '>', 0)
            ->groupBy('programme_id')
            ->map(fn ($group, $programmeId) => [
                'name' => Programme::find($programmeId)?->name ?? get_phrase('Unknown'),
                'invoiced' => (float) $group->sum('total_amount'),
                'collected' => (float) $group->sum('paid_amount'),
                'outstanding' => (float) $group->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount)),
            ])
            ->sortByDesc('outstanding')
            ->values();

        $recentPayments = $invoices->where('paid_amount', '>', 0)
            ->sortByDesc('timestamp')
            ->take(8)
            ->map(function ($invoice) {
                $student = (new CommonController)->get_student_details_by_id($invoice->student_id);
                return (object) [
                    'invoice' => $invoice,
                    'student_name' => $student['name'] ?? get_phrase('Unknown'),
                ];
            });

        $topOutstanding = $invoices->groupBy('student_id')
            ->map(function ($group, $studentId) {
                $balance = (float) $group->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount));
                $student = (new CommonController)->get_student_details_by_id($studentId);
                return (object) [
                    'student_id' => $studentId,
                    'student_name' => $student['name'] ?? get_phrase('Unknown'),
                    'class_name' => $student['class_name'] ?? null,
                    'balance' => $balance,
                ];
            })
            ->filter(fn ($row) => $row->balance > 0)
            ->sortByDesc('balance')
            ->take(8)
            ->values();

        return view('accountant.dashboard', compact(
            'totalInvoiced',
            'totalCollected',
            'totalOutstanding',
            'collectionRate',
            'statusCounts',
            'classBreakdown',
            'programmeBreakdown',
            'recentPayments',
            'topOutstanding'
        ));
    }

    /**
     * "Sync Invoices" — a bulk action to backfill StudentFeeManager rows for
     * students who never got any, either because they were admitted before
     * a FeeStructure existed for their class/programme, or through a path
     * that doesn't auto-generate invoices (see StudentFeeInvoiceGenerator's
     * own docblock: it only fires at admission/conversion time, never
     * retroactively). Reuses the exact same idempotent generator those call
     * sites already use, so running this twice never double-invoices anyone.
     */
    public function feeSyncForm()
    {
        $schoolId = auth()->user()->school_id;
        $classes = Classes::where('school_id', $schoolId)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $schoolId)->where('is_active', 1)->orderBy('name')->get();

        return view('accountant.student_fee_manager.sync', compact('classes', 'programmes'));
    }

    public function feeSyncGenerate(Request $request)
    {
        $schoolId = auth()->user()->school_id;

        $validated = $request->validate([
            'target_type' => ['required', 'in:class,programme'],
            'class_id' => ['nullable', 'required_if:target_type,class', 'integer'],
            'programme_id' => ['nullable', 'required_if:target_type,programme', 'integer'],
        ]);

        $createdCount = 0;
        $studentsAffected = 0;

        if ($validated['target_type'] === 'class') {
            $studentIds = Enrollment::where('school_id', $schoolId)
                ->where('class_id', $validated['class_id'])
                ->pluck('user_id');

            foreach (User::whereIn('id', $studentIds)->where('role_id', 7)->get() as $student) {
                $created = StudentFeeInvoiceGenerator::generateForClassBasedStudent($student, (int) $validated['class_id'], $schoolId);
                if ($created) {
                    $studentsAffected++;
                    $createdCount += count($created);
                }
            }
        } else {
            $studentIds = StudentProfile::where('school_id', $schoolId)
                ->where('programme_id', $validated['programme_id'])
                ->pluck('user_id');

            foreach (User::whereIn('id', $studentIds)->where('role_id', 7)->get() as $student) {
                $created = StudentFeeInvoiceGenerator::generateForStudent($student, (int) $validated['programme_id'], $schoolId);
                if ($created) {
                    $studentsAffected++;
                    $createdCount += count($created);
                }
            }
        }

        AuditLog::record('create', 'Student Fees', "Synced fee invoices: {$createdCount} invoice(s) created for {$studentsAffected} student(s).");

        return redirect()->route('accountant.fee_manager.sync')->with(
            'message',
            $createdCount > 0
                ? get_phrase("Created {$createdCount} invoice(s) for {$studentsAffected} student(s).")
                : get_phrase('No new invoices were needed — every matching student already has one for each mandatory fee.')
        );
    }

    /**
     * Show the student fee manager.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function studentFeeManagerList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if(count($request->all()) > 0){
            $data = $request->all();
            $date = explode('-', $data['eDateRange']);
            $date_from = strtotime($date[0].' 00:00:00');
            $date_to  = strtotime($date[1].' 23:59:59');
            $selected_class = $data['class'];
            $selected_status = $data['status'];

            if ($selected_class != "all" && $selected_status != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else if ($selected_class != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else if ($selected_status != "all"){
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            }


            $classes = Classes::where('school_id', auth()->user()->school_id)->get();

            return view('accountant.student_fee_manager.student_fee_manager', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);

         } else {
            $classes = Classes::where('school_id', auth()->user()->school_id)->get();
            $date_from = strtotime(date('d-m-Y',strtotime('first day of this month')).' 00:00:00');
            $date_to = strtotime(date('d-m-Y',strtotime('last day of this month')).' 23:59:59');
            $selected_class = "";
            $selected_status = "";
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            return view('accountant.student_fee_manager.student_fee_manager', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);
         }
    }

    public function feeManagerExport($date_from = "", $date_to = "", $selected_class = "", $selected_status = "")
    {

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($selected_class != "all" && $selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_class != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_status != "all"){
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        }

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();



        $file = "student_fee-".date('d-m-Y', $date_from).'-'.date('d-m-Y', $date_to).'-'.$selected_class.'-'.$selected_status.".csv";

        $csv_content = get_phrase('Invoice No') . ', ' . get_phrase('Student') . ', ' . get_phrase('Class') . ', ' . get_phrase('Invoice Title') . ', ' . get_phrase('Total Amount') . ', ' . get_phrase('Created At') . ', ' . get_phrase('Paid Amount') . ', ' . get_phrase('Status');

        foreach ($invoices as $invoice) {
            $csv_content .= "\n";

            $student_details = (new CommonController)->get_student_details_by_id($invoice['student_id']);
            $invoice_no = sprintf('%08d', $invoice['id']);

            $csv_content .= $invoice_no . ', ' . $student_details['name'] . ', ' . $student_details['class_name'] . ', ' . $invoice['title'] . ', ' . currency($invoice['total_amount']) . ', ' . date('d-M-Y', $invoice['timestamp']) . ', ' . currency($invoice['paid_amount']) . ', ' . $invoice['status'];
        }
        // Security Phase 2F: streamed to the requester — no copy is written to
        // the working directory (public/ under a web server) any more.
        return response($csv_content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '/', '\\'], '', $file) . '"',
            'Cache-Control'       => 'must-revalidate',
            'Expires'             => '0',
            'Pragma'              => 'public',
        ]);

    }


    public function feeManagerExportPdfPrint($date_from = "", $date_to = "", $selected_class = "", $selected_status = "")
    {

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($selected_class != "all" && $selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_class != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_status != "all"){
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        }


        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('accountant.student_fee_manager.pdf_print', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);


    }

    public function createFeeManager($value="")
    {

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        if($value == 'single'){
            return view('accountant.student_fee_manager.single', ['classes' => $classes]);
        } else if($value == 'mass'){
            return view('accountant.student_fee_manager.mass', ['classes' => $classes]);
        }
    }

    /**
     * Security Phase 2E: a fee/invoice (student_fee_managers row) reached by
     * id must belong to the caller's own school.
     */
    private function findSchoolFeeOrFail($id): StudentFeeManager
    {
        return StudentFeeManager::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
    }

    /** Security Phase 2E: true when $studentId is a student (role 7) in the caller's own school. */
    private function isSchoolStudent($studentId): bool
    {
        return User::where('id', $studentId)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->exists();
    }
    public function feeManagerCreate(Request $request, $value="")
    {
        $data = $request->all();

        if($value == 'single'){

            if ($data['paid_amount'] > $data['total_amount']) {

                return back()->with('error','Paid amount can not get bigger than total amount');

            }
            if ($data['status'] == 'paid' && $data['total_amount'] != $data['paid_amount']) {

               return back()->with('error','Paid amount is not equal to total amount');
            }


            if (!$this->isSchoolStudent($data['student_id'] ?? null)) {
                return back()->with('error', 'Student not found.');
            }
            $parent_id=User::find($data['student_id'])->toArray();
            $parent_id=$parent_id['parent_id'];
            $data['parent_id'] = $parent_id;

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            $data['timestamp'] = strtotime(date('d-M-Y'));
            $data['school_id'] = auth()->user()->school_id;
            $data['session_id'] = $active_session;


            StudentFeeManager::create($data);

            return redirect()->back()->with('message','You have successfully create a new invoice.');
        } else if($value == 'mass'){

            if ($data['paid_amount'] > $data['total_amount']) {

                return back()->with('error','Paid amount can not get bigger than total amount');

            }
            if ($data['status'] == 'paid' && $data['total_amount'] != $data['paid_amount']) {

               return back()->with('error','Paid amount is not equal to total amount');
            }

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            $data['timestamp'] = strtotime(date('d-M-Y'));
            $data['school_id'] = auth()->user()->school_id;
            $data['session_id'] = $active_session;

            $enrolments = Enrollment::where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'])
            ->where('school_id', auth()->user()->school_id)
            ->get();



            foreach ($enrolments as $enrolment) {


                $data['student_id'] = $enrolment['user_id'];
                $parent_id=User::find($data['student_id'])->toArray();
                $parent_id=$parent_id['parent_id'];
                $data['parent_id'] = $parent_id;
                StudentFeeManager::create($data);
            }

            if (sizeof($enrolments) > 0) {

                return redirect()->back()->with('message','Invoice added successfully');

            }else{

                return back()->with('error','No student found');
            }
        }
    }

    public function classWiseStudents($id='')
    {
        $enrollments = Enrollment::where('class_id', $id)
            ->where('school_id', auth()->user()->school_id)
            ->with('student')
            ->get();
        $options = '<option value="">'.'Select a student'.'</option>';
        foreach ($enrollments as $enrollment):
            if (! $enrollment->student) {
                continue;
            }
            $options .= '<option value="'.$enrollment->student->id.'">'.e($enrollment->student->name).'</option>';
        endforeach;
        echo $options;
    }

    public function editFeeManager($id='')
    {
        $this->findSchoolFeeOrFail($id);
        $invoice_details = StudentFeeManager::find($id);
        // class_id 0 is the "not class-based" sentinel used for
        // Programme-track invoices (see StudentFeeInvoiceGenerator) — every
        // Programme-track student's own Enrollment row now also uses 0 for
        // an unassigned class (see EnrollmentDefaults::ensureRow()), so this
        // still resolves correctly rather than the empty dropdown it used
        // to silently produce. Only students enrolled since that fix will
        // show up this way; older Programme-track accounts predate it and
        // have no Enrollment row at all yet.
        $enrollments = Enrollment::where('class_id', $invoice_details->class_id)
            ->where('school_id', auth()->user()->school_id)
            ->get();
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('accountant.student_fee_manager.edit', ['invoice_details' => $invoice_details, 'classes' => $classes, 'enrollments' => $enrollments]);
    }

    public function feeManagerUpdate(Request $request, $id='')
    {
        $data = $request->all();

        /*GET THE PREVIOUS INVOICE DETAILS FOR GETTING THE PAID AMOUNT*/
        $this->findSchoolFeeOrFail($id);
        if (!$this->isSchoolStudent($data['student_id'] ?? null)) {
            return redirect()->back()->with('error', 'Student not found.');
        }
        $previous_invoice_data = StudentFeeManager::find($id);

        if ($data['paid_amount'] > $data['total_amount']) {

            return redirect()->back()->with('error','Paid amount can not get bigger than total amount');
        }
        if ($data['status'] == 'paid' && $data['total_amount'] != $data['paid_amount']) {
            return redirect()->back()->with('error','Paid amount is not equal to total amount');
        }

        /*KEEPING TRACK OF PAYMENT DATE*/
        if ($data['paid_amount'] != $previous_invoice_data && $data['paid_amount'] > 0) {
            $timestamp = strtotime(date('d-M-Y'));
        }elseif ($data['paid_amount'] == 0 || $data['paid_amount'] == "") {
            $timestamp = 0;
        }

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        StudentFeeManager::where('id', $id)->update([
            'title' => $data['title'],
            'total_amount' => $data['total_amount'],
            'class_id' => $data['class_id'],
            'student_id' => $data['student_id'],
            'paid_amount' => $data['paid_amount'],
            'payment_method' => $data['payment_method'],
            'timestamp' => $timestamp,
            'status' => $data['status'],
            'school_id' => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect()->back()->with('message','You have successfully update invoice.');
    }

    public function studentFeeDelete($id)
    {
        $this->findSchoolFeeOrFail($id);
        $invoice = StudentFeeManager::find($id);
        $invoice->delete();
        return redirect()->back()->with('message','You have successfully delete invoice.');
    }


    public function offline_payment_pending(Request $request )
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
         if(count($request->all()) > 0){
            $data = $request->all();
            $date = explode('-', $data['eDateRange']);
            $date_from = strtotime($date[0].' 00:00:00');
            $date_to  = strtotime($date[1].' 23:59:59');
            $selected_class = $data['class'];
            $selected_status = 'pending';
            $payment_method = 'offline';



            if ($selected_class != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('payment_method', $payment_method)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('payment_method', $payment_method)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            }


            $classes = Classes::where('school_id', auth()->user()->school_id)->get();

            return view('accountant.student_fee_manager.student_fee_manager_pending', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);

         } else {
            $classes = Classes::where('school_id', auth()->user()->school_id)->get();
            $date_from = strtotime(date('d-m-Y',strtotime('first day of this month')).' 00:00:00');
            $date_to = strtotime(date('d-m-Y',strtotime('last day of this month')).' 23:59:59');
            $selected_class = "";
            $selected_status = 'pending';
            $payment_method = 'offline';
            $invoices = StudentFeeManager::where('status','pending')->where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('payment_method', $payment_method)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            return view('accountant.student_fee_manager.student_fee_manager_pending', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);
         }


    }

    public function update_offline_payment($id,$status)
    {
        $feeBefore = $this->findSchoolFeeOrFail($id);
        $invoice = StudentFeeManager::find($id);
        if (!$invoice) {
            return redirect()->back()->with('error', 'Invoice not found.');
        }

        $amount = $invoice->total_amount;

        if($status=='approve')
        {
            StudentFeeManager::where('id', $id)->update([
                'status' => 'paid',
                'updated_at'=>date("Y-m-d H:i:s"),
                'paid_amount' =>$amount,
                'payment_method' => 'offline']);

                StatusChangeAudit::feePayment($feeBefore, 'approved');
                return redirect()->back()->with('message','Payment Approved');
        }
        elseif($status=='decline')
        {
            StudentFeeManager::where('id',$id)->update([
                'status' => 'unpaid',
                'updated_at'=>date("Y-m-d H:i:s"),
                'paid_amount' => 0,
                'payment_method' => 'offline']);

                StatusChangeAudit::feePayment($feeBefore, 'declined');
                return redirect()->back()->with('message','Payment Decline');


        }


    }

    public function studentFeeinvoice(Request $request, $id)
    {
        $this->findSchoolFeeOrFail($id);
        $invoice_details=StudentFeeManager::find($id)->toArray();
        $student_details = (new CommonController)->get_student_details_by_id($invoice_details['student_id'])->toArray();


      return view('accountant.student_fee_manager.invoice',['invoice_details' => $invoice_details,'student_details' => $student_details]);
    }


    /**
     * Show the expense expense list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function expenseList(Request $request)
    {
        if(count($request->all()) > 0){
            $data = $request->all();

            $date = explode('-', $data['eDateRange']);
            $date_from = strtotime($date[0].' 00:00:00');
            $date_to  = strtotime($date[1].' 23:59:59');
            $expense_category_id = $data['expense_category_id'];

            $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
            $selected_category = ExpenseCategory::where('school_id', auth()->user()->school_id)->find($expense_category_id);
            if($expense_category_id != 'all'){
                $expenses = Expense::where('date', '>=', $date_from)->where('date', '<=', $date_to)->where(['expense_category_id' => $expense_category_id, 'school_id' => auth()->user()->school_id])->get();
            } else {
                $expenses = Expense::where('date', '>=', $date_from)->where('date', '<=', $date_to)->where('school_id', auth()->user()->school_id)->get();
            }

            return view('accountant.expenses.expense_manager', ['expense_categories' => $expense_categories, 'expenses' => $expenses, 'selected_category' => $selected_category, 'date_from' => $date_from, 'date_to' => $date_to]);

        } else {
            $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
            $date_from = strtotime(date('d-m-Y',strtotime('first day of this month')).' 00:00:00');
            $date_to = strtotime(date('d-m-Y',strtotime('last day of this month')).' 23:59:59');
            $expenses = Expense::where('date', '>=', $date_from)->where('date', '<=', $date_to)->where('school_id', auth()->user()->school_id)->get();
            $selected_category = "";
            return view('accountant.expenses.expense_manager', ['expense_categories' => $expense_categories, 'expenses' => $expenses, 'selected_category' => $selected_category, 'date_from' => $date_from, 'date_to' => $date_to]);
        }
    }

    public function createExpense()
    {
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
        return view('accountant.expenses.create', ['expense_categories' => $expense_categories]);
    }

    public function expenseCreate(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        Expense::create([
            'expense_category_id' => $data['expense_category_id'],
            'date' => strtotime($data['date']),
            'amount' => $data['amount'],
            'school_id' => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect()->back()->with('message','You have successfully create a new expense.');
    }

    public function editExpense($id)
    {
        $expense_details = Expense::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
        return view('accountant.expenses.edit', ['expense_categories' => $expense_categories, 'expense_details' => $expense_details]);
    }

    public function expenseUpdate(Request $request, $id)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        Expense::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
            'expense_category_id' => $data['expense_category_id'],
            'date' => strtotime($data['date']),
            'amount' => $data['amount'],
            'school_id' => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect()->back()->with('message','You have successfully update expense.');
    }

    public function expenseDelete($id)
    {
        $expense = Expense::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense->delete();
        return redirect()->back()->with('message','You have successfully delete expense.');
    }


    /**
     * Show the expense category list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function expenseCategoryList()
    {
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('accountant.expense_category.expense_category_list', compact('expense_categories'));
    }

    public function createExpenseCategory()
    {
        return view('accountant.expense_category.create');
    }

    public function expenseCategoryCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_category_check = ExpenseCategory::get()->where('name', $data['name']);

        if(count($duplicate_category_check) == 0) {

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            ExpenseCategory::create([
                'name' => $data['name'],
                'school_id' => auth()->user()->school_id,
                'session_id' => $active_session,
            ]);

            return redirect()->back()->with('message','You have successfully create a new expense category.');

        } else {
            return back()
            ->with('error','Sorry this expense category already exists');
        }
    }

    public function editExpenseCategory($id)
    {
        $expense_category = ExpenseCategory::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('accountant.expense_category.edit', ['expense_category' => $expense_category]);
    }

    public function expenseCategoryUpdate(Request $request, $id)
    {
        $data = $request->all();

        $duplicate_category_check = ExpenseCategory::get()->where('name', $data['name']);

        if(count($duplicate_category_check) == 0) {

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            ExpenseCategory::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
                'name' => $data['name'],
                'school_id' => auth()->user()->school_id,
                'session_id' => $active_session,
            ]);

            return redirect()->back()->with('message','You have successfully update expense category.');

        } else {
            return back()
            ->with('error','Sorry this expense category already exists');
        }
    }

    public function expenseCategoryDelete($id)
    {
        $expense_category = ExpenseCategory::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense_category->delete();
        return redirect()->back()->with('message','You have successfully delete expense category.');
    }

    function profile(){
        return view('accountant.profile.view');
    }

    function profile_update(Request $request){
        $data['name'] = $request->name;
        $data['email'] = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $data['designation'] = $request->designation;
        
        $user_info['birthday'] = strtotime($request->eDefaultDateRange);
        $user_info['gender'] = $request->gender;
        $user_info['phone'] = $request->phone;
        $user_info['address'] = $request->address;


        if(empty($request->photo)){
            $user_info['photo'] = $request->old_photo;
        }else{
            $file_name = ProfilePhoto::store($request->photo);
            if ($file_name === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }
            $user_info['photo'] = $file_name;
        }

        $data['user_information'] = json_encode($user_info);

        User::where('id', auth()->user()->id)->update($data);
        
        return redirect(route('accountant.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }

    function user_language(Request $request){
        $data['language'] = $request->language;
        User::where('id', auth()->user()->id)->update($data);
        
        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    function password($action_type = null, Request $request){



        if($action_type == 'update'){

            

            if($request->new_password != $request->confirm_password){
                return back()->with("error", "Confirm Password Doesn't match!");
            }


            if(!Hash::check($request->old_password, auth()->user()->password)){
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            User::where('id', auth()->user()->id)->update($data);

            return redirect(route('accountant.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('accountant.profile.password');
    }

    public function noticeboardList()
    {

        $notices = Noticeboard::get()->where('school_id', auth()->user()->school_id);

        $events = array();

        foreach ($notices as $notice) {
            if ($notice['end_date'] != "") {
                if ($notice['start_date'] != $notice['end_date']) {
                    $end_date = strtotime($notice['end_date']) + 24 * 60 * 60;
                    $end_date = date('Y-m-d', $end_date);
                } else {
                    $end_date = date('Y-m-d', strtotime($notice['end_date']));
                }
            }

            if ($notice['end_date'] == "" && $notice['start_time'] == "" && $notice['end_time'] == "") {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            } else if ($notice['start_time'] != "" && ($notice['end_date'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time']
                );
            } else if ($notice['end_date'] != "" && ($notice['start_time'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                    'end' => $end_date
                );
            } else if ($notice['end_date'] != "" && $notice['start_time'] != "" && $notice['end_time'] != "") {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                    'end' => date('Y-m-d', strtotime($notice['end_date'])) . 'T' . $notice['end_time']
                );
            } else {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            }
            array_push($events, $info);
        }

        $events = json_encode($events);

        return view('accountant.noticeboard.noticeboard', ['events' => $events]);
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('accountant.noticeboard.edit', ['notice' => $notice]);
    }

    /**
     * Show the event list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function eventList(Request $request)
    {
        $search = $request['search'] ?? "";

        if($search != "") {

            $events = FrontendEvent::where(function ($query) use($search) {
                    $query->where('title', 'LIKE', "%{$search}%");
                })->paginate(10);

        } else {
            $events = FrontendEvent::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('accountant.events.events', compact('events', 'search'));
    }

    
     //  Message

     public function allMessage(Request $request, $id)
     {
 
             $msg_user_details = DB::table('users')
             ->join('message_thrades', function ($join) {
                 // Join where the user is the sender
                 $join->on('users.id', '=', 'message_thrades.sender_id')
                     ->orWhere(function ($query) {
                         // Join where the user is the receiver
                         $query->on('users.id', '=', 'message_thrades.reciver_id');
                     });
             })
             ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
             ->where('message_thrades.id', $id)
             ->where('message_thrades.school_id', auth()->user()->school_id)
             ->where('users.id', '<>', auth()->user()->id) // Exclude the authenticated user
             ->first();
 
             
             
         if ($request->ajax()) {
             $query = $request->input('query');
             
             // Search users by name or any other criteria
             $users = User::where('name', 'LIKE', "%{$query}%")
                 ->where('school_id', auth()->user()->school_id)
                 ->get();
 
             // Prepare HTML response
             $html = '';
 
             // Check if any users were found
             if ($users->isEmpty()) {
                 return response()->json('No User found');
             }
 
             foreach ($users as $user) {
                 
                 if (!empty($user)) {
                     $userInfo = json_decode($user->user_information);
                     
                     $user_image = !empty($userInfo->photo) 
                         ? asset('assets/uploads/user-images/' . $userInfo->photo) 
                         : asset('assets/uploads/user-images/thumbnail.png');
 
                     $html .= '
                         <div class="user-item d-flex align-items-center msg_us_src_list">
                             <a href="' . route('accountant.message.messagethrades', ['id' => $user->id]).'">
                                 <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                                 <span class="ms-3">' . $user->name . '</span>
                             </a>
                         </div>
                     ';
                 }
             }
 
             return response()->json($html);
         }
 
 
         $chat_datas = Chat::where('school_id', auth()->user()->school_id)->get();
 
         $counter_condition = Chat::where('message_thrade', $id)->orderBy('id', 'desc')->first();
 
        
        if($counter_condition->sender_id != auth()->user()->id){
             Chat::where('message_thrade', $id)->update(['read_status' => 1]);
         }
         
         return view('accountant.message.all_message', ['msg_user_details' => $msg_user_details], ['chat_datas' => $chat_datas]);
     }
 
     public function messagethrades($id){
 
         $exists = MessageThrade::where('reciver_id', $id)
                             ->where('sender_id', auth()->user()->id)
                             ->exists();
         if( $id != auth()->user()->id){
             if (!$exists) {
                 $message_thrades_data = [
                     'reciver_id' => $id,
                     'sender_id' => auth()->user()->id,
                     'school_id' => auth()->user()->school_id,
                 ];
         
                 MessageThrade::create($message_thrades_data);
         
                 //return redirect()->back()->with('message', 'User added successfully');
             }
     
             
             $message_thrades = MessageThrade::where('reciver_id', $id)
                                          ->where('sender_id', auth()->user()->id)
                                          ->first();
             $msg_trd_id = $message_thrades->id;
             
             $msg_user_details = DB::table('users')
                 ->join('message_thrades', 'users.id', '=', 'message_thrades.reciver_id')
                 ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
                 ->where('message_thrades.id', $msg_trd_id)
                 ->first();
     
                 $chat_datas = Chat::where('school_id', auth()->user()->school_id)->get();
     
                 // Combine all data into a single array
                 return view('accountant.message.all_message', ['id' => $msg_trd_id, 'msg_user_details' => $msg_user_details, 'chat_datas' => $chat_datas,]);
         }
         return redirect()->back()->with('error', 'You can not add you');
         
                         
     }
 
 
     public function chat_save(Request $request)
     {
         $data = $request->all();
         $chat_data = [
             'message_thrade' => $data['message_thrade'],
             'reciver_id' => $data['reciver_id'],
             'message' => $data['message'],
             'school_id' => auth()->user()->school_id,
             'sender_id' => auth()->user()->id,
             'read_status' => 0,
 
         ];
     
         // Create feedback entry
         Chat::create($chat_data);
 
         return redirect()->back();
     }
 
     public function chat_empty(Request $request)
     {
 
         if ($request->ajax()) {
             $query = $request->input('query');
 
             $users = User::where('name', 'LIKE', "%{$query}%")
                 ->where('school_id', auth()->user()->school_id)
                 ->get();
 
             $html = '';
 
             if ($users->isEmpty()) {
                 return response()->json('No User found');
             }
 
             foreach ($users as $user) {
                 $userInfo = json_decode($user->user_information);
                 $user_image = !empty($userInfo->photo) 
                     ? asset('assets/uploads/user-images/' . $userInfo->photo) 
                     : asset('assets/uploads/user-images/thumbnail.png');
 
                 $html .= '
                     <div class="user-item d-flex align-items-center msg_us_src_list">
                         <a href="' . route('accountant.message.messagethrades', ['id' => $user->id]).'">
                             <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                             <span class="ms-3">' . $user->name . '</span>
                         </a>
                     </div>
                 ';
             }
 
             return response()->json($html);
         }
 
         // Pass the data to the view only if msg_user_details is not null
         return view('accountant.message.chat_empty');
     }
 

}
