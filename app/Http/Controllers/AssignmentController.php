<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Classes;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Support\SafeUpload;

class AssignmentController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    // ── Admin / Teacher ────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search   = $request->search ?? '';
        // ->k12(): the legacy K12 screen. Course Offering assignments have their
        // own lecturer workspace and their own grading rules, so listing them
        // here would show a lecturer marks they cannot set and deadlines that
        // behave differently. Legacy rows have course_offering_id NULL and are
        // completely unaffected by the partition.
        $assignments = Assignment::k12()->where('school_id', $this->school_id)
            ->when($search, fn($q) => $q->where('title', 'like', "%$search%"))
            ->with(['subject'])
            ->withCount('submissions')
            ->latest()
            ->paginate(20);

        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes  = Classes::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('admin.assignment.index', compact('assignments', 'subjects', 'classes', 'search'));
    }

    public function openModal(Request $request)
    {
        $id         = $request->id;
        $assignment = $id ? Assignment::k12()->where('school_id', $this->school_id)->findOrFail($id) : null;
        $subjects   = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes    = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        return view('admin.assignment.modal', compact('assignment', 'subjects', 'classes'));
    }

    public function store(Request $request)
    {
        Log::info('AssignmentController@store called', ['payload' => $request->all(), 'user_id' => Auth::id()]);
        $validated = $request->validate([
            'title'           => 'required|max:255',
            'subject_id'      => 'nullable|exists:subjects,id',
            'class_id'        => 'nullable|exists:classes,id',
            'instructions'    => 'nullable|string',
            'due_date'        => 'nullable|date',
            'max_marks'       => 'required|integer|min:1',
            'submission_type' => 'required|in:file,text,link,any',
        ]);
        $validated['school_id']   = $this->school_id;
        $validated['teacher_id']  = Auth::id();
        $validated['is_published'] = 1;

        // Course Offering assignments are created through the lecturer workspace,
        // which enforces allocation authority and the governed lifecycle, so a
        // Course Offering id can never arrive here from this form. The validated
        // payload carries no such field, which is what makes that guarantee.
        $a = Assignment::create($validated);
        AuditLog::record('create', 'Assignments', "Created assignment: {$a->title}");
        Log::info('Assignment created', ['id' => $a->id, 'attrs' => $a->toArray()]);
        return response()->json(['status' => 'success', 'message' => get_phrase('Assignment created')]);
    }

    public function update(Request $request, $id)
    {
        $assignment = Assignment::k12()->where('school_id', $this->school_id)->findOrFail($id);
        $validated  = $request->validate([
            'title'           => 'required|max:255',
            'subject_id'      => 'nullable|exists:subjects,id',
            'class_id'        => 'nullable|exists:classes,id',
            'instructions'    => 'nullable|string',
            'due_date'        => 'nullable|date',
            'max_marks'       => 'required|integer|min:1',
            'submission_type' => 'required|in:file,text,link,any',
        ]);
        $assignment->update($validated);
        return response()->json(['status' => 'success', 'message' => get_phrase('Assignment updated')]);
    }

    public function destroy($id)
    {
        $a = Assignment::k12()->where('school_id', $this->school_id)->findOrFail($id);
        AuditLog::record('delete', 'Assignments', "Deleted assignment: {$a->title}");
        $a->delete();
        return redirect()->back()->with('success', get_phrase('Assignment deleted'));
    }

    public function submissions($id)
    {
        $assignment  = Assignment::k12()->where('school_id', $this->school_id)->findOrFail($id);
        $submissions = AssignmentSubmission::where('assignment_id', $id)->with('student')->orderByDesc('submitted_at')->paginate(30);
        return view('admin.assignment.submissions', compact('assignment', 'submissions'));
    }

    public function gradeSubmission(Request $request, $submission_id)
    {
        // Security Phase 2G: a submission belongs to a school through its assignment.
        // Scoped to a K12 assignment as well as to the school: this legacy grading
    // action does not apply the Course Offering rules (bounded marks, release
    // gate), so a HEI submission must be graded in the lecturer workspace.
    $sub = AssignmentSubmission::k12()
        ->whereHas('assignment', fn ($q) => $q->k12()->where('school_id', auth()->user()->school_id))
        ->findOrFail($submission_id);
        $request->validate(['marks_awarded' => 'required|numeric|min:0', 'feedback' => 'nullable|string']);
        $sub->update(['marks_awarded' => $request->marks_awarded, 'feedback' => $request->feedback, 'status' => 'graded']);
        return redirect()->back()->with('success', get_phrase('Submission graded'));
    }

    // ── Student ────────────────────────────────────────────────────────────

    public function studentList()
    {
        $student_id = Auth::id();
        $school_id  = Auth::user()->school_id;
        $class_id   = resolve_student_academic_context($student_id, $school_id)['class_id'];

        // ->k12() is LOAD-BEARING here, not cosmetic. This query matches
        // `class_id IS NULL`, and a Course Offering assignment has class_id NULL
        // by design - so without the partition every HEI assignment in the school
        // would be listed to every K12 student, who could then submit to it
        // through the two routes below. Legacy rows are unaffected.
        $assignments = Assignment::k12()->where('school_id', $school_id)
            ->where('is_published', 1)
            ->where(fn($q) => $q->whereNull('class_id')->orWhere('class_id', $class_id))
            ->with(['subject'])
            ->latest()
            ->get()
            ->map(function ($a) use ($student_id) {
                $a->my_submission = AssignmentSubmission::where('assignment_id', $a->id)
                    ->where('student_id', $student_id)->first();
                return $a;
            });

        return view('student.assignment.list', compact('assignments'));
    }

    public function submitModal($id)
    {
        $school_id  = Auth::user()->school_id;
        $assignment = Assignment::k12()->where('school_id', $school_id)->findOrFail($id);
        return view('student.assignment.submit_modal', compact('assignment'));
    }

    public function studentSubmit(Request $request, $id)
    {
        $school_id  = Auth::user()->school_id;
        $assignment = Assignment::k12()->where('school_id', $school_id)->where('is_published', 1)->findOrFail($id);
        $student_id = Auth::id();

        $existing = AssignmentSubmission::where('assignment_id', $id)->where('student_id', $student_id)->first();
        if ($existing) {
            return redirect()->back()->with('error', get_phrase('You have already submitted this assignment'));
        }

        $data = [
            'assignment_id' => $id,
            'student_id'    => $student_id,
            'submitted_at'  => now(),
            'status'        => now()->gt($assignment->due_date) ? 'late' : 'submitted',
        ];

        if ($request->hasFile('file')) {
            $file         = $request->file('file');
            // Security Phase 2F: generated name; script/web-executable types refused.
            $filename     = SafeUpload::store($file, public_path('assets/uploads/assignments')) ?? abort(422, 'This file type is not allowed.');
            $data['file_path'] = 'assets/uploads/assignments/' . $filename;
        }

        if ($request->filled('submission')) {
            $data['submission'] = $request->submission;
        }

        if ($request->filled('link')) {
            $data['link'] = $request->link;
        }

        AssignmentSubmission::create($data);
        return redirect()->back()->with('success', get_phrase('Assignment submitted successfully'));
    }
}
