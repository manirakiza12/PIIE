<?php

namespace App\Http\Controllers;

use App\Models\StudentCurriculumAssignment;
use App\Models\User;
use App\Support\Progression\ProgressionService;
use Illuminate\Http\Request;

class StudentProgressionController extends Controller
{
    public function __construct(private ProgressionService $progression) {}

    public function index(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $students = User::query()->where('school_id', $schoolId)->where('role_id', 7)->orderBy('name')->paginate(20);
        return view('admin.progression.index', compact('students'));
    }

    public function show(Request $request, int $studentId)
    {
        $schoolId = (int) $request->user()->school_id;
        $student = User::query()->where('school_id', $schoolId)->where('role_id', 7)->findOrFail($studentId);
        $evaluation = $this->progression->evaluate($schoolId, $studentId);
        $assignments = StudentCurriculumAssignment::query()->where('school_id', $schoolId)->where('student_id', $studentId)->latest('id')->get();
        return view('admin.progression.show', compact('student', 'evaluation', 'assignments'));
    }

    public function decide(Request $request, int $studentId)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'decision' => ['required', 'in:promote,retain,conditional,exceptional'],
            'notes' => ['nullable', 'string'],
        ]);
        $this->progression->recordDecision($schoolId, $studentId, $data['decision'], $request->user()->id, $data['notes'] ?? null);
        return redirect()->route('admin.progression.show', $studentId)->with('message', 'Progression decision recorded.');
    }
}