<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LeaveType;
use App\Models\Leavelist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    // ── Leave Types ────────────────────────────────────────────────────────

    public function types(Request $request)
    {
        $types = LeaveType::where('school_id', $this->school_id)->orderBy('name')->get();
        return view('admin.leave.types', compact('types'));
    }

    public function typeModal()
    {
        return view('admin.leave.type_modal');
    }

    public function storeType(Request $request)
    {
        $validated = $request->validate(['name' => 'required|max:100', 'max_days' => 'required|integer|min:0']);
        $validated['school_id'] = $this->school_id;
        $validated['is_paid']   = $request->has('is_paid') ? 1 : 0;
        LeaveType::create($validated);
        return redirect()->back()->with('success', get_phrase('Leave type created'));
    }

    public function destroyType($id)
    {
        LeaveType::where('school_id', $this->school_id)->findOrFail($id)->delete();
        return redirect()->back()->with('success', get_phrase('Leave type deleted'));
    }

    // ── Leave Applications ─────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search   = $request->search ?? '';
        $status   = $request->status ?? '';
        $leaves   = Leavelist::where('school_id', $this->school_id)
            ->when($status, fn($q) => $q->where('status', $status))
            ->with(['user', 'leaveType', 'approver'])
            ->latest()
            ->paginate(20);

        $types    = LeaveType::where('school_id', $this->school_id)->get();

        return view('admin.leave.index', compact('leaves', 'types', 'search', 'status'));
    }

    // ── Staff Self-Service ──────────────────────────────────────────────────

    public function myIndex(Request $request)
    {
        $leaves = Leavelist::where('school_id', $this->school_id)
            ->where('user_id', Auth::id())
            ->with(['leaveType', 'approver'])
            ->latest()
            ->paginate(20);

        $types = LeaveType::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('leave.my.index', compact('leaves', 'types'));
    }

    public function myOpenModal()
    {
        $types = LeaveType::where('school_id', $this->school_id)->orderBy('name')->get();
        return view('leave.my.modal', compact('types'));
    }

    public function myStore(Request $request)
    {
        $validated = $request->validate([
            // Security Phase 2G: the leave type must be one of this school's own.
            'leave_type_id' => 'nullable|exists:leave_types,id,school_id,' . $this->school_id,
            'from_date'     => 'required|date',
            'to_date'       => 'required|date|after_or_equal:from_date',
            'reason'        => 'required|string',
        ]);

        $from = \Carbon\Carbon::parse($validated['from_date']);
        $to   = \Carbon\Carbon::parse($validated['to_date']);
        $days = \App\Support\Compatibility\WholeDateIntervals::days($from, $to) + 1;

        $lt = !empty($validated['leave_type_id']) ? LeaveType::where('school_id', $this->school_id)->find($validated['leave_type_id']) : null;

        Leavelist::create([
            'school_id'     => $this->school_id,
            'user_id'       => Auth::id(),
            'leave_type_id' => $validated['leave_type_id'] ?? null,
            'leave_type'    => $lt?->name,
            'from_date'     => $validated['from_date'],
            'to_date'       => $validated['to_date'],
            'days'          => $days,
            'reason'        => $validated['reason'],
            'status'        => Leavelist::STATUS_PENDING,
        ]);

        AuditLog::record('create', 'Leave', 'Leave applied by user #' . Auth::id() . ": {$validated['from_date']} to {$validated['to_date']}");

        return redirect()->back()->with('success', get_phrase('Leave application submitted'));
    }

    public function approve(Request $request, $id)
    {
        $leave = Leavelist::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate(['comment' => 'nullable|string|max:1000']);

        $leave->update([
            'status'        => Leavelist::STATUS_APPROVED,
            'approved_by'   => Auth::id(),
            'admin_comment' => $validated['comment'] ?? null,
        ]);

        AuditLog::record('approve', 'Leave', "Approved leave #{$id}" . (!empty($validated['comment']) ? " - Comment: {$validated['comment']}" : ''));
        return redirect()->back()->with('success', get_phrase('Leave approved'));
    }

    public function returnLeave(Request $request, $id)
    {
        $leave = Leavelist::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate(['comment' => 'required|string|max:1000']);

        $leave->update([
            'status'        => Leavelist::STATUS_RETURNED,
            'approved_by'   => Auth::id(),
            'admin_comment' => $validated['comment'],
        ]);

        AuditLog::record('return', 'Leave', "Returned leave #{$id} - Comment: {$validated['comment']}");
        return redirect()->back()->with('success', get_phrase('Leave returned to staff for correction'));
    }

    public function reject(Request $request, $id)
    {
        $leave = Leavelist::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate(['comment' => 'required|string|max:1000']);

        $leave->update([
            'status'        => Leavelist::STATUS_REJECTED,
            'approved_by'   => Auth::id(),
            'admin_comment' => $validated['comment'],
        ]);

        AuditLog::record('reject', 'Leave', "Denied leave #{$id} - Reason: {$validated['comment']}");
        return redirect()->back()->with('success', get_phrase('Leave denied'));
    }

    public function destroy($id)
    {
        Leavelist::where('school_id', $this->school_id)->findOrFail($id)->delete();
        return redirect()->back()->with('success', get_phrase('Leave deleted'));
    }
}
