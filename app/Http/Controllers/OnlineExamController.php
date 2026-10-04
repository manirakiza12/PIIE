<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Classes;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamProctoringEvent;
use App\Models\OnlineExam;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Models\OnlineExamUserNotification;
use App\Models\QuestionBank;
use App\Models\QuestionTopic;
use App\Models\QuestionTag;
use App\Models\Programme;
use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Http\Requests\OnlineExam\CameraReadinessRequest;
use App\Http\Requests\OnlineExam\ManualMarkAnswerRequest;
use App\Http\Requests\OnlineExam\ProctoringEventRequest;
use App\Http\Requests\OnlineExam\SaveOnlineExamAnswerRequest;
use App\Http\Requests\OnlineExam\StartOnlineExamRequest;
use App\Http\Requests\OnlineExam\StoreOnlineExamQuestionRequest;
use App\Http\Requests\OnlineExam\StoreOnlineExamRequest;
use App\Http\Requests\OnlineExam\SubmitOnlineExamRequest;
use App\Http\Requests\OnlineExam\UpdateOnlineExamQuestionRequest;
use App\Http\Requests\OnlineExam\UpdateOnlineExamRequest;
use App\Support\Permissions\OnlineExamAuthorizer;
use App\Support\Permissions\OnlineExamPermissionService;
use App\Support\OnlineExams\QuestionContract;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\OnlineExams\OnlineExamPortalNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OnlineExamController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    // ── Exams (admin) ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->authorize('viewAny', OnlineExam::class);

        $search  = $request->search ?? '';
        $exams   = OnlineExam::forSchool($this->school_id)
            ->when($search, fn($q) => $q->where('title', 'like', "%$search%"))
            ->with(['subject', 'submissions.student', 'submissions.answerRows', 'submissions.proctoringEvents', 'submissions.exam.questions'])
            ->withCount('questions', 'submissions')
            ->latest()
            ->paginate(20);

        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes  = Classes::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('admin.online_exam.index', compact('exams', 'subjects', 'classes', 'search'));
    }

    public function openModal(Request $request)
    {
        $id       = $request->id;
        if ($id) {
            $exam = $this->findExamOrFail((int) $id);
            $this->authorize('update', $exam);
        } else {
            $this->authorize('create', OnlineExam::class);
            $exam = null;
        }

        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes  = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = $this->academicSessionsForSelection();
        return view('admin.online_exam.modal', compact('exam', 'subjects', 'classes', 'programmes', 'sessions'));
    }

    public function create()
    {
        $this->authorize('create', OnlineExam::class);

        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes  = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = $this->academicSessionsForSelection();

        return view('admin.online_exam.modal', [
            'exam' => null,
            'subjects' => $subjects,
            'classes' => $classes,
            'programmes' => $programmes,
            'sessions' => $sessions,
        ]);
    }

    public function show($id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('view', $exam);

        $exam->load(['subject', 'classRoom', 'questions', 'submissions.student']);
        $readinessErrors = $exam->publicationReadinessErrors();
        return view('admin.online_exam.show', compact('exam', 'readinessErrors'));
    }

    public function edit($id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('update', $exam);

        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $classes  = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = $this->academicSessionsForSelection();

        return view('admin.online_exam.modal', compact('exam', 'subjects', 'classes', 'programmes', 'sessions'));
    }

    public function store(StoreOnlineExamRequest $request)
    {
        $this->authorize('create', OnlineExam::class);

        $validated = $request->validated();
        if (($validated['workflow_state'] ?? 'draft') === 'published') {
            abort(422, 'Add and validate questions before publishing an exam.');
        }
        $payload = [
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            'class_id' => $validated['class_id'] ?? null,
            'programme_id' => $validated['programme_id'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'exam_type' => $validated['exam_type'],
            'start_datetime' => $validated['start_datetime'] ?? null,
            'end_datetime' => $validated['end_datetime'] ?? null,
            'duration_mins' => (int) ($validated['duration_mins'] ?? 0),
            'total_marks' => (int) $validated['total_marks'],
            'pass_mark' => (int) $validated['pass_mark'],
            'max_attempts' => (int) ($validated['max_attempts'] ?? 1),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
            'allow_previous_navigation' => (bool) ($validated['allow_previous_navigation'] ?? true),
            'result_release_policy' => $validated['result_release_policy'] ?? 'immediate',
            'webcam_required' => (bool) ($validated['webcam_required'] ?? false),
            'fullscreen_required' => (bool) ($validated['fullscreen_required'] ?? false),
            'auto_submit' => (bool) ($validated['auto_submit'] ?? true),
            'workflow_state' => $validated['workflow_state'] ?? 'draft',
            'school_id' => $this->school_id,
            'is_published' => ($validated['workflow_state'] ?? 'draft') === 'published',
            'created_by' => Auth::id(),
            'creator_id' => Auth::id(),
            'updater_id' => Auth::id(),
        ];
        if (!Schema::hasColumn('online_exams', 'programme_id')) unset($payload['programme_id']);
        if (!Schema::hasColumn('online_exams', 'session_id')) unset($payload['session_id']);

        $exam = DB::transaction(fn() => OnlineExam::create($payload));
        AuditLog::record('create', 'Online Exams', "Created exam: {$exam->title}");
        return redirect()->route('admin.online_exams.index')->with('success', get_phrase('Exam created'));
    }

    public function update(UpdateOnlineExamRequest $request, $id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('update', $exam);

        $validated = $request->validated();
        DB::transaction(function () use ($exam, $validated) {
            $payload = [
                'title' => $validated['title'],
                'instructions' => $validated['instructions'] ?? null,
                'subject_id' => $validated['subject_id'] ?? null,
                'class_id' => $validated['class_id'] ?? null,
                'programme_id' => $validated['programme_id'] ?? null,
                'session_id' => $validated['session_id'] ?? null,
                'exam_type' => $validated['exam_type'],
                'start_datetime' => $validated['start_datetime'] ?? null,
                'end_datetime' => $validated['end_datetime'] ?? null,
                'duration_mins' => (int) ($validated['duration_mins'] ?? 0),
                'total_marks' => (int) $validated['total_marks'],
                'pass_mark' => (int) $validated['pass_mark'],
                'max_attempts' => (int) ($validated['max_attempts'] ?? $exam->max_attempts),
                'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
                'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
                'allow_previous_navigation' => (bool) ($validated['allow_previous_navigation'] ?? true),
                'result_release_policy' => $validated['result_release_policy'] ?? 'immediate',
                'webcam_required' => (bool) ($validated['webcam_required'] ?? false),
                'fullscreen_required' => (bool) ($validated['fullscreen_required'] ?? false),
                'auto_submit' => (bool) ($validated['auto_submit'] ?? true),
                'workflow_state' => $validated['workflow_state'] ?? $exam->workflow_state,
                'is_published' => ($validated['workflow_state'] ?? $exam->workflow_state) === 'published',
                'updater_id' => Auth::id(),
            ];
            if (!Schema::hasColumn('online_exams', 'programme_id')) unset($payload['programme_id']);
            if (!Schema::hasColumn('online_exams', 'session_id')) unset($payload['session_id']);
            $exam->update($payload);
            if (($validated['workflow_state'] ?? $exam->workflow_state) === 'published') {
                $exam->refresh()->load('questions');
                $errors = $exam->publicationReadinessErrors();
                if (!empty($errors)) {
                    abort(422, implode(' ', $errors));
                }
            }
        });

        AuditLog::record('update', 'Online Exams', "Updated exam: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam updated'));
    }

    public function publish(Request $request, $id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('publish', $exam);
        $wasPublished = (bool) $exam->is_published;

        $readinessErrors = $exam->fresh()->publicationReadinessErrors();
        if (!empty($readinessErrors)) {
            return $this->publicationReadinessFailure($request, $readinessErrors);
        }

        $this->publishExam($exam);
        $exam->refresh();

        AuditLog::record('update', 'Online Exams', (($exam->is_published ? 'Published' : 'Unpublished') . " exam: {$exam->title}"));

        if (!$wasPublished && $exam->is_published) {
            OnlineExamPortalNotifier::teacher('exam_approved', 'Exam Approved', 'Your exam "' . $exam->title . '" was approved and published.', $exam, Auth::id(), 'exam-approved:' . $exam->id);
            OnlineExamPortalNotifier::eligibleStudents($exam, 'exam_published', 'Exam Available', 'A new exam, "' . $exam->title . '", is now available.', Auth::id(), 'exam-published:' . $exam->id);
            \App\Support\OnlineExams\OnlineExamAnnouncementNotifier::examPublished($exam->fresh());
        }
        return redirect()->back()->with('success', get_phrase('Exam status updated'));
    }

    public function unpublish($id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('unpublish', $exam);

        if ($exam->submissions()->whereIn('status', [OnlineExamSubmission::STATUS_IN_PROGRESS, OnlineExamSubmission::STATUS_SUBMITTED, OnlineExamSubmission::STATUS_PENDING_MANUAL])->exists()) {
            return redirect()->back()->withErrors(['exam' => get_phrase('Cannot unpublish an exam with active or pending attempts.')]);
        }

        DB::transaction(function () use ($exam) {
            $exam->update([
                'is_published' => 0,
                'workflow_state' => 'draft',
                'updater_id' => Auth::id(),
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Unpublished exam: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam unpublished'));
    }

    public function cancel(Request $request, $id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('cancel', $exam);

        if ($exam->submissions()->where('status', OnlineExamSubmission::STATUS_IN_PROGRESS)->exists()) {
            return redirect()->back()->withErrors(['exam' => get_phrase('Cannot cancel an exam while candidates are active.')]);
        }

        DB::transaction(function () use ($request, $exam) {
            $exam->update([
                'workflow_state' => 'cancelled',
                'is_published' => 0,
                'cancelled_at' => now(),
                'cancellation_reason' => $request->input('reason'),
                'updater_id' => Auth::id(),
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Cancelled exam: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam cancelled'));
    }

    public function lock($id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('update', $exam);

        DB::transaction(function () use ($exam) {
            $exam->update([
                'locked_at' => now(),
                'updater_id' => Auth::id(),
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Locked exam structure: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam locked'));
    }

    public function destroy($id)
    {
        $exam = $this->findExamOrFail((int) $id);
        $this->authorize('delete', $exam);

        DB::transaction(function () use ($exam) {
            $exam->questions()->delete();
            $exam->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Deleted exam: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam deleted'));
    }

    // ── Questions ─────────────────────────────────────────────────────────

    public function questions($exam_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('manageQuestions', $exam);

        $questions = OnlineExamQuestion::forExam($exam->id)->ordered()->get();
        $bank      = QuestionBank::where('school_id', $this->school_id)
            ->when($exam->subject_id, fn($q) => $q->where('subject_id', $exam->subject_id))
            ->get();
        $questionMarksTotal = (int) $questions->sum('marks');
        return view('admin.online_exam.questions', compact('exam', 'questions', 'bank', 'questionMarksTotal'));
    }

    public function storeQuestion(StoreOnlineExamQuestionRequest $request, $exam_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('manageQuestions', $exam);

        $validated = $request->validated();

        DB::transaction(function () use ($exam, $validated) {
            $nextSort = (int) OnlineExamQuestion::forExam($exam->id)->max('sort_order') + 1;
            OnlineExamQuestion::create([
                'online_exam_id' => $exam->id,
                'question' => $validated['question'],
                'type' => $validated['type'],
                'option_a' => $validated['option_a'] ?? null,
                'option_b' => $validated['option_b'] ?? null,
                'option_c' => $validated['option_c'] ?? null,
                'option_d' => $validated['option_d'] ?? null,
                'correct_ans' => $validated['correct_ans'] ?? null,
                'question_schema_version' => $validated['question_schema_version'] ?? null,
                'question_config' => $validated['question_config'] ?? null,
                'marking_config' => $validated['marking_config'] ?? null,
                'marks' => $validated['marks'],
                'sort_order' => $nextSort,
            ]);
        });

        AuditLog::record('create', 'Online Exams', "Created exam question in exam #{$exam->id}");
        return redirect()->back()->with('success', get_phrase('Question added'));
    }

    public function updateQuestion(UpdateOnlineExamQuestionRequest $request, $id)
    {
        $question = OnlineExamQuestion::with('exam')->findOrFail((int) $id);
        abort_unless($question->exam && (int) $question->exam->school_id === (int) $this->school_id, 404);
        $this->authorize('update', $question);

        $validated = $request->validated();
        DB::transaction(function () use ($question, $validated) {
            $question->update([
                'question' => $validated['question'],
                'type' => $validated['type'],
                'option_a' => $validated['option_a'] ?? null,
                'option_b' => $validated['option_b'] ?? null,
                'option_c' => $validated['option_c'] ?? null,
                'option_d' => $validated['option_d'] ?? null,
                'correct_ans' => $validated['correct_ans'] ?? null,
                'question_schema_version' => $validated['question_schema_version'] ?? null,
                'question_config' => $validated['question_config'] ?? null,
                'marking_config' => $validated['marking_config'] ?? null,
                'marks' => $validated['marks'],
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Updated exam question #{$question->id}");
        return redirect()->back()->with('success', get_phrase('Question updated'));
    }

    public function deleteQuestion($id)
    {
        return $this->destroyQuestion($id);
    }

    public function destroyQuestion($id)
    {
        $question = OnlineExamQuestion::with('exam')->findOrFail((int) $id);
        abort_unless($question->exam && (int) $question->exam->school_id === (int) $this->school_id, 404);
        $this->authorize('delete', $question);

        DB::transaction(function () use ($question) {
            $question->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Deleted exam question #{$question->id}");
        return redirect()->back()->with('success', get_phrase('Question deleted'));
    }

    // ── Question Bank ─────────────────────────────────────────────────────

    private function assertQuestionMetadataAdmin(): void
    {
        $user = Auth::user();
        $permissions = app(OnlineExamPermissionService::class);
        abort_unless($user && $permissions->has($user, 'manage_exam_questions') && $permissions->has($user, 'edit_all_online_exams'), 403);
    }

    public function questionMetadata()
    {
        $this->assertQuestionMetadataAdmin();
        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $topics = QuestionTopic::where('school_id', $this->school_id)->with('subject')->orderBy('name')->get();
        $tags = QuestionTag::where('school_id', $this->school_id)->orderBy('name')->get();
        return view('admin.online_exam.question_metadata', compact('subjects', 'topics', 'tags'));
    }

    public function storeQuestionTopic(Request $request)
    {
        $this->assertQuestionMetadataAdmin();
        $data = $request->validate(['subject_id' => ['required', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))], 'name' => ['required', 'string', 'max:150']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Topic name is required.');
        abort_if(QuestionTopic::where('school_id', $this->school_id)->where('subject_id', $data['subject_id'])->whereNull('parent_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(), 422, 'That Topic already exists for this Course.');
        QuestionTopic::create(['school_id' => $this->school_id, 'subject_id' => $data['subject_id'], 'name' => $name, 'is_active' => true, 'created_by' => Auth::id()]);
        return redirect()->back()->with('success', get_phrase('Topic created'));
    }

    public function storeQuestionSubtopic(Request $request)
    {
        $this->assertQuestionMetadataAdmin();
        $data = $request->validate(['subject_id' => ['required', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))], 'parent_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:150']]);
        $parent = QuestionTopic::where('school_id', $this->school_id)->where('subject_id', $data['subject_id'])->whereNull('parent_id')->findOrFail($data['parent_id']);
        $name = trim($data['name']); abort_if($name === '', 422, 'Subtopic name is required.');
        abort_if(QuestionTopic::where('school_id', $this->school_id)->where('subject_id', $parent->subject_id)->where('parent_id', $parent->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(), 422, 'That Subtopic already exists under this Topic.');
        QuestionTopic::create(['school_id' => $this->school_id, 'subject_id' => $parent->subject_id, 'parent_id' => $parent->id, 'name' => $name, 'is_active' => true, 'created_by' => Auth::id()]);
        return redirect()->back()->with('success', get_phrase('Subtopic created'));
    }

    public function updateQuestionTopic(Request $request, $id)
    {
        $this->assertQuestionMetadataAdmin(); $topic = QuestionTopic::where('school_id', $this->school_id)->findOrFail((int) $id);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'subject_id' => ['sometimes', 'integer']]);
        $name = trim($data['name']); $subjectId = (int) ($data['subject_id'] ?? $topic->subject_id);
        abort_unless(Subject::where('school_id', $this->school_id)->whereKey($subjectId)->exists(), 422);
        abort_if($topic->parent_id !== null && !QuestionTopic::whereKey($topic->parent_id)->where('subject_id', $subjectId)->exists(), 422, 'Subtopic parent and Course must remain consistent.');
        $hasRefs = QuestionBank::where('school_id', $this->school_id)->where(fn ($q) => $q->where('topic_id', $topic->id)->orWhere('subtopic_id', $topic->id))->exists();
        abort_if($hasRefs && $subjectId !== (int) $topic->subject_id, 422, 'A referenced taxonomy item cannot be moved to another Course.');
        abort_if(QuestionTopic::where('school_id', $this->school_id)->where('subject_id', $subjectId)->where('parent_id', $topic->parent_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '<>', $topic->id)->exists(), 422, 'That taxonomy name already exists here.');
        $topic->update(['name' => $name, 'subject_id' => $subjectId]); return redirect()->back()->with('success', get_phrase('Topic updated'));
    }

    public function toggleQuestionTopic($id)
    {
        $this->assertQuestionMetadataAdmin(); $topic = QuestionTopic::where('school_id', $this->school_id)->findOrFail((int) $id); $topic->update(['is_active' => !$topic->is_active]); return redirect()->back()->with('success', get_phrase('Topic status updated'));
    }

    public function storeQuestionTag(Request $request)
    {
        $this->assertQuestionMetadataAdmin(); $data = $request->validate(['name' => ['required', 'string', 'max:100']]); $name = trim($data['name']); $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name));
        abort_if($normalized === '', 422, 'Tag name is required.'); abort_if(QuestionTag::where('school_id', $this->school_id)->where('normalized_name', $normalized)->exists(), 422, 'That Tag already exists in this school.');
        QuestionTag::create(['school_id' => $this->school_id, 'name' => $name, 'normalized_name' => $normalized, 'is_active' => true, 'created_by' => Auth::id()]); return redirect()->back()->with('success', get_phrase('Tag created'));
    }

    public function updateQuestionTag(Request $request, $id)
    {
        $this->assertQuestionMetadataAdmin(); $tag = QuestionTag::where('school_id', $this->school_id)->findOrFail((int) $id); $data = $request->validate(['name' => ['required', 'string', 'max:100']]); $name = trim($data['name']); $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name));
        abort_if(QuestionTag::where('school_id', $this->school_id)->where('normalized_name', $normalized)->where('id', '<>', $tag->id)->exists(), 422, 'That Tag already exists in this school.'); $tag->update(['name' => $name, 'normalized_name' => $normalized]); return redirect()->back()->with('success', get_phrase('Tag updated'));
    }

    public function toggleQuestionTag($id)
    {
        $this->assertQuestionMetadataAdmin(); $tag = QuestionTag::where('school_id', $this->school_id)->findOrFail((int) $id); $tag->update(['is_active' => !$tag->is_active]); return redirect()->back()->with('success', get_phrase('Tag status updated'));
    }

    public function questionBank(Request $request)
    {
        $this->authorize('viewAny', OnlineExam::class);

        $search   = $request->search ?? '';
        $subjectId = (int) $request->input('subject_id', 0); $programmeId = (int) $request->input('programme_id', 0); $sessionId = (int) $request->input('session_id', 0); $topicId = (int) $request->input('topic_id', 0); $subtopicId = (int) $request->input('subtopic_id', 0); $tagId = (int) $request->input('tag_id', 0); $type = (string) $request->input('type', ''); $difficulty = (string) $request->input('difficulty', ''); $status = $request->input('status', '');
        $questions = QuestionBank::where('school_id', $this->school_id)
            ->when($search, fn($q) => $q->where('question', 'like', "%$search%"))
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->when($programmeId, fn($q) => $q->where('programme_id', $programmeId))
            ->when($sessionId, fn($q) => $q->where('session_id', $sessionId))
            ->when($topicId, fn($q) => $q->where('topic_id', $topicId))
            ->when($subtopicId, fn($q) => $q->where('subtopic_id', $subtopicId))
            ->when($tagId, fn($q) => $q->whereHas('tags', fn($t) => $t->where('question_tags.id', $tagId)))
            ->when($type !== '', fn($q) => $q->where('type', $type))
            ->when($difficulty !== '', fn($q) => $q->where('difficulty', $difficulty))
            ->when($status !== '', fn($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);
        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get(); $sessions = Session::where('school_id',$this->school_id)->orderByDesc('id')->get();
        $topics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNull('parent_id')->orderBy('name')->get(); $subtopics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNotNull('parent_id')->orderBy('name')->get(); $tags = QuestionTag::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get();
        return view('admin.online_exam.question_bank', compact('questions', 'subjects', 'search', 'subjectId', 'programmes', 'sessions', 'topics', 'subtopics', 'tags', 'programmeId', 'sessionId', 'topicId', 'subtopicId', 'tagId', 'type', 'difficulty', 'status'));
    }

    public function bankModal(Request $request)
    {
        $this->authorize('create', OnlineExam::class);
        $subjects = Subject::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get(); $sessions = Session::where('school_id',$this->school_id)->orderByDesc('id')->get();
        $topics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNull('parent_id')->orderBy('name')->get();
        $subtopics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNotNull('parent_id')->orderBy('name')->get();
        $tags = QuestionTag::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get();
        $question = $request->filled('id')
            ? QuestionBank::where('school_id', $this->school_id)->findOrFail((int) $request->input('id'))
            : null;
        return view('admin.online_exam.bank_modal', compact('subjects', 'question', 'programmes', 'sessions', 'topics', 'subtopics', 'tags'));
    }

    public function bankImportModal()
    {
        $this->authorize('create', OnlineExam::class);
        return view('admin.online_exam.bank_import_modal');
    }

    public function destroyBankQuestion($id)
    {
        $question = QuestionBank::where('school_id', $this->school_id)->findOrFail((int) $id);
        abort_unless((int) $question->school_id === (int) $this->school_id, 404);
        $user = Auth::user();
        $privileged = app(OnlineExamPermissionService::class)->has($user, 'manage_exam_questions')
            && app(OnlineExamPermissionService::class)->has($user, 'edit_all_online_exams');
        abort_unless($privileged || (int) ($question->created_by ?? $question->creator_id) === (int) $user->id, 403);
        DB::transaction(function () use ($question) {
            $question->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Deleted question bank question #{$id}");
        return redirect()->back()->with('success', get_phrase('Question deleted'));
    }

    public function questionModal($exam_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('manageQuestions', $exam);
        return view('admin.online_exam.question_modal', compact('exam_id'));
    }

    public function storeBankQuestion(Request $request)
    {
        $this->authorize('create', OnlineExam::class);
        $this->normalizeBankStructuredOptions($request);

        $validated = $request->validate([
            'subject_id'  => ['nullable', \Illuminate\Validation\Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
            'programme_id' => ['nullable', Rule::exists('programmes','id')->where(fn($q)=>$q->where('school_id',$this->school_id))],
            'session_id' => ['nullable', Rule::exists('sessions','id')->where(fn($q)=>$q->where('school_id',$this->school_id))],
            'topic_id' => ['nullable','integer'], 'subtopic_id' => ['nullable','integer'], 'tag_ids' => ['nullable','array','max:20'], 'tag_ids.*' => ['integer'], 'status' => ['nullable','in:draft,active,retired,archived'],
            'question'    => 'required|string',
            'type'        => 'required|in:mcq,true_false,short,essay,multiple_select,numeric,matching,ordering',
            'option_a'    => 'nullable|string',
            'option_b'    => 'nullable|string',
            'option_c'    => 'nullable|string',
            'option_d'    => 'nullable|string',
            'correct_ans' => 'nullable|string|max:255',
            'correct_answer_tf' => 'nullable|string|in:true,false',
            'marks'       => 'required|integer|min:1|max:127',
            'difficulty'  => 'required|in:easy,medium,hard',
            'structured_options' => 'nullable|array|max:8',
            'structured_options.*.id' => 'required|string|max:32|regex:/^[a-z][a-z0-9_-]*$/i',
            'structured_options.*.label' => 'required|string|max:1000',
            'correct_option_ids' => 'nullable|array|max:8',
            'correct_option_ids.*' => 'required|string|max:32',
            'numeric_target' => 'nullable', 'numeric_tolerance' => 'nullable',
            'structured_blanks' => 'nullable|array|max:16',
            'structured_blanks.*.id' => 'required|string|max:32|regex:/^[a-z][a-z0-9_-]*$/i',
            'structured_blanks.*.accepted_answers' => 'required|array|max:8',
            'structured_blanks.*.accepted_answers.*' => 'required|string|max:255',
            'case_sensitive' => 'nullable|boolean', 'trim_whitespace' => 'nullable|boolean',
            'structured_pairs' => 'nullable|array|max:16', 'structured_pairs.*.left_id'=>'required|string|max:32', 'structured_pairs.*.left_text'=>'required|string|max:1000', 'structured_pairs.*.right_id'=>'required|string|max:32', 'structured_pairs.*.right_text'=>'required|string|max:1000',
            'structured_order_items' => 'nullable|array|max:16', 'structured_order_items.*.id'=>'required|string|max:32', 'structured_order_items.*.text'=>'required|string|max:1000',
        ]);
        if (in_array($validated['type'], ['multiple_select', 'numeric','matching','ordering'], true) || ($validated['type'] === 'fill_blank' && !empty($validated['structured_blanks']))) {
            $structured = $this->structuredBankFields($validated);
            $validated = array_merge($validated, $structured);
        } else {
            $validated['correct_ans'] = $this->canonicalizeBankAnswer($validated);
        }
        unset($validated['correct_answer_tf']);
        $this->validateQuestionBankAcademicMetadata($validated);

        DB::transaction(function () use ($validated) {
            $question = QuestionBank::create([
                'school_id' => $this->school_id,
                'subject_id' => $validated['subject_id'] ?? null,
                ...$this->questionBankMetadataPayload($validated),
                'question' => $validated['question'],
                'type' => $validated['type'],
                'option_a' => $validated['option_a'] ?? null,
                'option_b' => $validated['option_b'] ?? null,
                'option_c' => $validated['option_c'] ?? null,
                'option_d' => $validated['option_d'] ?? null,
                'correct_ans' => $validated['correct_ans'] ?? null,
                'question_schema_version' => $validated['question_schema_version'] ?? null,
                'question_config' => $validated['question_config'] ?? null,
                'marking_config' => $validated['marking_config'] ?? null,
                'marks' => $validated['marks'],
                'difficulty' => $validated['difficulty'],
                'created_by' => Auth::id(),
            ]);
            if (Schema::hasTable('question_bank_tag') && !empty($validated['tag_ids'])) $question->tags()->sync($validated['tag_ids']);
        });

        return redirect()->back()->with('success', get_phrase('Question added to bank'));
    }

    public function importBankQuestions(Request $request)
    {
        $this->authorize('create', OnlineExam::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = \App\Support\OnlineExams\QuestionBankImporter::import(
            $validated['file'],
            $this->school_id,
            Auth::id()
        );

        AuditLog::record('create', 'Online Exams', "Imported {$result['imported']} question(s) into the bank from a file.");

        $summary = trans_choice(':count question imported.|:count questions imported.', $result['imported'], ['count' => $result['imported']]);

        if ($result['errors']) {
            $summary .= ' ' . count($result['errors']) . ' row(s) skipped.';
        }

        return redirect()->back()->with(
            $result['imported'] > 0 ? 'success' : 'error',
            $summary
        )->with('import_errors', $result['errors'])->with('import_warnings', $result['warnings']);
    }

    public function downloadBankImportTemplate()
    {
        $this->authorize('create', OnlineExam::class);

        $headers = \App\Support\OnlineExams\QuestionBankImporter::TEMPLATE_HEADERS;
        $example = [
            'What is the capital of Uganda?', 'mcq', 'Kampala', 'Nairobi', 'Kigali', 'Lagos', 'a', '2', 'easy', '',
        ];

        $csv = implode(',', $headers) . "\n" . implode(',', array_map(function ($v) {
            return '"' . str_replace('"', '""', $v) . '"';
        }, $example)) . "\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="question_bank_import_template.csv"',
        ]);
    }

    public function updateBankQuestion(Request $request, $id)
    {
        $question = QuestionBank::where('school_id', $this->school_id)->findOrFail((int) $id);
        $user = Auth::user();
        $privileged = app(OnlineExamPermissionService::class)->has($user, 'manage_exam_questions')
            && app(OnlineExamPermissionService::class)->has($user, 'edit_all_online_exams');
        abort_unless($privileged || (int) ($question->created_by ?? 0) === (int) $user->id, 403);
        $this->normalizeBankStructuredOptions($request);
        $validated = $request->validate([
            'subject_id'  => ['nullable', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
            'programme_id' => ['nullable', Rule::exists('programmes','id')->where(fn($q)=>$q->where('school_id',$this->school_id))], 'session_id' => ['nullable', Rule::exists('sessions','id')->where(fn($q)=>$q->where('school_id',$this->school_id))],
            'topic_id' => ['nullable','integer'], 'subtopic_id' => ['nullable','integer'], 'tag_ids' => ['nullable','array','max:20'], 'tag_ids.*' => ['integer'], 'status' => ['nullable','in:draft,active,retired,archived'],
            'question'    => ['required', 'string'],
            'type'        => ['required', 'in:mcq,true_false,short,essay,multiple_select,numeric,matching,ordering'],
            'option_a'    => ['nullable', 'string'], 'option_b' => ['nullable', 'string'],
            'option_c'    => ['nullable', 'string'], 'option_d' => ['nullable', 'string'],
            'correct_ans' => ['nullable', 'string', 'max:255'],
            'correct_answer_tf' => ['nullable', 'string', 'in:true,false'],
            'marks'       => ['required', 'integer', 'min:1', 'max:127'],
            'difficulty'  => ['required', 'in:easy,medium,hard'],
            'structured_options' => ['nullable', 'array', 'max:8'],
            'structured_options.*.id' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'],
            'structured_options.*.label' => ['required', 'string', 'max:1000'],
            'correct_option_ids' => ['nullable', 'array', 'max:8'],
            'correct_option_ids.*' => ['required', 'string', 'max:32'],
            'numeric_target' => ['nullable'], 'numeric_tolerance' => ['nullable'],
            'structured_blanks' => ['nullable', 'array', 'max:16'],
            'structured_blanks.*.id' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'],
            'structured_blanks.*.accepted_answers' => ['required', 'array', 'max:8'],
            'structured_blanks.*.accepted_answers.*' => ['required', 'string', 'max:255'],
            'case_sensitive' => ['nullable', 'boolean'], 'trim_whitespace' => ['nullable', 'boolean'],
            'structured_pairs' => ['nullable','array','max:16'], 'structured_pairs.*.left_id'=>['required','string','max:32'], 'structured_pairs.*.left_text'=>['required','string','max:1000'], 'structured_pairs.*.right_id'=>['required','string','max:32'], 'structured_pairs.*.right_text'=>['required','string','max:1000'],
            'structured_order_items' => ['nullable','array','max:16'], 'structured_order_items.*.id'=>['required','string','max:32'], 'structured_order_items.*.text'=>['required','string','max:1000'],
        ]);
        if (in_array($validated['type'], ['multiple_select', 'numeric','matching','ordering'], true) || ($validated['type'] === 'fill_blank' && !empty($validated['structured_blanks']))) {
            $validated = array_merge($validated, $this->structuredBankFields($validated));
        } else {
            $validated['correct_ans'] = $this->canonicalizeBankAnswer($validated);
        }
        unset($validated['correct_answer_tf']);
        $this->validateQuestionBankAcademicMetadata($validated);
        $updateData = $validated + ['subject_id' => $validated['subject_id'] ?? null];
        if (!Schema::hasColumn('question_banks', 'programme_id')) foreach (['programme_id','session_id','topic_id','subtopic_id','tag_ids','status'] as $key) unset($updateData[$key]);
        unset($updateData['tag_ids']);
        $question->update($updateData);
        if (Schema::hasTable('question_bank_tag') && array_key_exists('tag_ids', $validated)) $question->tags()->sync($validated['tag_ids'] ?? []);
        return redirect()->route('admin.question_bank.index')->with('success', get_phrase('Question bank item updated'));
    }

    // ── Teacher: online exams ─────────────────────────────────────────────

    public function adminImportQuestion(Request $request, OnlineExam $exam)
    {
        $this->authorize('manageQuestions', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);
        if ($exam->isStructurallyLocked()) {
            return redirect()->back()->withErrors(['questions' => get_phrase('Questions are locked after attempts have started.')]);
        }
        $validated = $request->validate([
            'question_bank_ids' => ['required', 'array', 'min:1'],
            'question_bank_ids.*' => ['required', 'integer', 'exists:question_banks,id'],
        ]);
        $bankQuestions = QuestionBank::where('school_id', $this->school_id)
            ->whereIn('id', $validated['question_bank_ids'])->get();
        $imported = 0;
        DB::transaction(function () use ($exam, $bankQuestions, &$imported) {
            $nextSort = ((int) OnlineExamQuestion::forExam($exam->id)->max('sort_order')) + 1;
            foreach ($bankQuestions as $bankQuestion) {
                OnlineExamQuestion::create([
                    'online_exam_id' => $exam->id, 'question_bank_id' => $bankQuestion->id,
                    'question' => $bankQuestion->question, 'type' => $this->snapshotStorageType($bankQuestion),
                    'option_a' => $bankQuestion->option_a, 'option_b' => $bankQuestion->option_b,
                    'option_c' => $bankQuestion->option_c, 'option_d' => $bankQuestion->option_d,
                'correct_ans' => \App\Support\OnlineExams\AnswerKey::normalize($bankQuestion->normalized_type, $bankQuestion->correct_ans, ['a' => $bankQuestion->option_a, 'b' => $bankQuestion->option_b, 'c' => $bankQuestion->option_c, 'd' => $bankQuestion->option_d]),
                    'question_schema_version' => $bankQuestion->question_schema_version,
                    'question_config' => $bankQuestion->question_config,
                    'marking_config' => $bankQuestion->marking_config,
                    'marks' => $bankQuestion->marks,
                    'sort_order' => $nextSort++,
                ]);
                $imported++;
            }
        });
        AuditLog::record('create', 'Online Exams', "Admin imported {$imported} question(s) from bank into exam #{$exam->id}");
        return redirect()->back()->with('success', get_phrase('Questions imported from bank.'));
    }

    public function teacherIndex(Request $request)
    {
        $this->authorize('viewAny', OnlineExam::class);

        $user = Auth::user();
        $authorizer = app(OnlineExamAuthorizer::class);
        $permissionService = app(OnlineExamPermissionService::class);
        $canEditAll = $authorizer->can($user, 'edit_all_online_exams');
        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);

        $query = OnlineExam::forSchool($this->school_id)
            // `courseOffering` is loaded only for its `reference`, which is a column on
            // the row. Its `subject` relation is deliberately NOT eager loaded: that
            // relation applies its tenant constraint at definition time, so eager
            // loading it would compile `where school_id is null` and quietly match
            // nothing. See `CourseOffering::academicYear()` for the full account.
            //
            // And it is loaded ONLY when the table exists. Most of this engine's suites
            // hand-roll a partial schema that predates the Course Offering work -
            // `TeacherOnlineExamTest` among them - and an eager load of a relation onto
            // a table that was never created is an unconditional 500 on the list every
            // lecturer opens. Guarded, not assumed: see the identical guard on
            // `CourseOfferingExamAccess::teachableOfferings()`.
            ->when(
                Schema::hasTable('course_offerings'),
                fn ($q) => $q->with(['courseOffering'])
            )
            ->with(['subject', 'classRoom'])
            ->withCount('questions', 'submissions');
        $this->applyTeacherOwnershipScope($query, $user, $assignedClassIds, $canEditAll);

        $stats = $this->teacherExamLifecycleCounts($user, $assignedClassIds, $canEditAll);

        $search = trim((string) $request->input('title', ''));
        $subjectId = (int) $request->input('subject_id', 0);
        $programmeId = (int) $request->input('programme_id', 0); $sessionId = (int) $request->input('session_id', 0); $status = $request->input('status', '');
        $programmeId = (int) $request->input('programme_id', 0); $sessionId = (int) $request->input('session_id', 0); $status = $request->input('status', '');
        $classId = (int) $request->input('class_id', 0);
        $courseOfferingId = (int) $request->input('course_offering_id', 0);
        $workflowState = trim((string) $request->input('workflow_state', ''));
        $lifecycleState = trim((string) $request->input('lifecycle_state', ''));
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $tab = trim((string) $request->input('tab', ''));

        $query->when($search !== '', function ($q) use ($search) {
            $q->where('title', 'like', '%' . $search . '%');
        });

        if ($subjectId > 0) {
            $query->where('subject_id', $subjectId);
        }
        if ($programmeId > 0) $query->where('programme_id', $programmeId);
        if ($sessionId > 0) $query->where('session_id', $sessionId);
        if ($status !== '') $query->where('status', $status);
        if ($programmeId > 0) $query->where('programme_id', $programmeId);
        if ($sessionId > 0) $query->where('session_id', $sessionId);
        if ($status !== '') $query->where('status', $status);

        if ($classId > 0) {
            $query->where('class_id', $classId);
        }

        // Course Offering filter, offered only for Offerings this lecturer actually
        // teaches. The id is intersected with their own allocations before it reaches
        // the query, so a forged value narrows to nothing rather than exposing
        // another course's exams.
        if ($courseOfferingId > 0 && Schema::hasColumn('online_exams', 'course_offering_id')) {
            $mine = $this->lecturerCourseOfferingIds((int) $user->id);

            if ($canEditAll) {
                $mine = \App\Models\CourseOffering::where('school_id', $this->school_id)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            if (in_array($courseOfferingId, $mine, true)) {
                $query->where('course_offering_id', $courseOfferingId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($workflowState !== '') {
            $query->where('workflow_state', $workflowState);
        }

        if ($lifecycleState !== '') {
            $this->applyLifecycleFilter($query, $lifecycleState);
        }

        if (!empty($dateFrom)) {
            $query->whereDate('start_datetime', '>=', $dateFrom);
        }

        if (!empty($dateTo)) {
            $query->whereDate('end_datetime', '<=', $dateTo);
        }

        if ($tab !== '') {
            $this->applyTabFilter($query, $tab);
        }

        $exams = $query->latest()->paginate(20)->appends($request->all());

        $subjects = $this->teacherAssignableSubjects((int) $user->id, $assignedClassIds);
        $programmes = Programme::where('school_id',$this->school_id)->where('is_active',1)->whereIn('id', TeacherProgrammeAssignment::where('school_id',$this->school_id)->where('teacher_id',$user->id)->pluck('programme_id'))->orderBy('name')->get();
        $sessions = Session::where('school_id',$this->school_id)->orderByDesc('id')->get();
        $programmes = Programme::where('school_id',$this->school_id)->where('is_active',1)->whereIn('id', TeacherProgrammeAssignment::where('school_id',$this->school_id)->where('teacher_id',$user->id)->pluck('programme_id'))->orderBy('name')->get(); $sessions = Session::where('school_id',$this->school_id)->orderByDesc('id')->get();
        $classes = $this->teacherAssignableClasses($assignedClassIds);
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $topics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNull('parent_id')->orderBy('name')->get(); $subtopics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNotNull('parent_id')->orderBy('name')->get(); $tags = QuestionTag::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get();

        // The Course Offerings this lecturer is allocated to, so the page can be
        // OFFERED them and can tell a lecturer who has none whether that matters.
        // This is the same list the create page offers, from the same authority -
        // their own allocations - so a lecturer cannot be shown a course here that
        // they would be refused when creating an exam in it.
        $courseOfferings = app(CourseOfferingExamAccess::class)->teachableOfferings($user);

        return view('teacher.online_exam.index', compact(
            'exams',
            'subjects',
            'classes',
            'sessions',
            'search',
            'subjectId',
            'classId',
            'workflowState',
            'lifecycleState',
            'dateFrom',
            'dateTo',
            'tab',
            'canEditAll',
            'stats',
            'courseOfferings',
            'courseOfferingId'
        ))->with([
            'canPublish' => $permissionService->has($user, 'publish_online_exams'),
            'canCreate' => $permissionService->has($user, 'create_online_exams'),
            'canManageQuestions' => $permissionService->has($user, 'manage_exam_questions'),
            'canMark' => $permissionService->has($user, 'mark_exam_answers'),
        ]);
    }

    /**
     * "Ongoing" (published, currently within its start/end window) and
     * "Upcoming" (published, not started yet) exams this teacher can act on
     * — same ownership scoping as teacherIndex() — so a teacher can jump
     * straight to attempts/proctoring for an exam that's live right now, or
     * see what's coming up next, without wading through the full history
     * list and its drafts/completed/cancelled noise.
     */
    public function teacherLiveMonitor()
    {
        $this->authorize('viewAny', OnlineExam::class);

        $user = Auth::user();
        $authorizer = app(OnlineExamAuthorizer::class);
        $canEditAll = $authorizer->can($user, 'edit_all_online_exams');
        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);

        $ongoingQuery = OnlineExam::forSchool($this->school_id)
            ->with(['subject', 'classRoom'])
            ->withCount(['submissions as in_progress_count' => function ($q) {
                $q->where('status', OnlineExamSubmission::STATUS_IN_PROGRESS);
            }])
            ->active();
        $this->applyTeacherOwnershipScope($ongoingQuery, $user, $assignedClassIds, $canEditAll);
        $ongoingExams = $ongoingQuery->orderBy('start_datetime')->limit(50)->get();

        $upcomingQuery = OnlineExam::forSchool($this->school_id)
            ->with(['subject', 'classRoom'])
            ->upcoming();
        $this->applyTeacherOwnershipScope($upcomingQuery, $user, $assignedClassIds, $canEditAll);
        $upcomingExams = $upcomingQuery->orderBy('start_datetime')->limit(50)->get();

        return view('teacher.online_exam.live_monitor', [
            'ongoingExams' => $ongoingExams,
            'upcomingExams' => $upcomingExams,
            'canReviewProctoring' => app(OnlineExamPermissionService::class)->has($user, 'review_exam_proctoring'),
        ]);
    }

    /**
     * GET /teacher/online-exams/create
     *
     * ── WHY THE FIRST SELECTOR IS A COURSE OFFERING ──────────────────────────
     *
     * This page used to ask a lecturer to reconstruct, by hand, a relationship the
     * institution already records: which course they are teaching, which Course Unit
     * that course carries, and which year and semester it sits in. It asked for them
     * through `teacher_permissions` - the legacy teacher -> class graph - which is
     * empty for a lecturer appointed through a Course Offering. So for Daniel Okello,
     * the primary lecturer of BBIT1103, the page said "No subjects are assigned to
     * this teacher" while he was standing on the create form for the course he runs.
     *
     * The page is kept, and modernised, rather than redirected: a lecturer who does
     * hold legacy class assignments still has a genuine legacy workflow, and the two
     * have to coexist. What changed is the ORDER, and what is asked:
     *
     *   - a Course Offering is chosen FIRST, from the lecturer's own allocations only
     *     (`CourseOfferingExamAccess::teachableOfferings()`);
     *   - choosing one makes the Course Unit, academic year, period and lecturer a
     *     read-only DERIVED summary, and hides the legacy selectors entirely;
     *   - the legacy selectors remain reachable, below it, for a legacy exam - and
     *     their empty-state warning now appears only when the lecturer has neither a
     *     Course Offering nor a legacy subject, which is the case it was written for.
     *
     * The derivation is a SERVER round trip (`?course_offering_id=`), not client-side
     * guesswork. The summary is therefore derived from the same authoritative read
     * the store will perform, and there is no second copy of the academic context
     * living in JavaScript.
     */
    public function teacherCreate(Request $request)
    {
        $this->authorize('create', OnlineExam::class);

        $user = Auth::user();
        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);
        $subjects = $this->teacherAssignableSubjects((int) $user->id, $assignedClassIds);
        $classes = $this->teacherAssignableClasses($assignedClassIds);
        $programmes = $this->teacherAssignableProgrammes((int) $user->id);
        $sessions = $this->academicSessionsForSelection();

        $examAccess = app(CourseOfferingExamAccess::class);
        $offerings = $examAccess->teachableOfferings($user);

        // Membership in the lecturer's OWN list, not a lookup: a `course_offering_id`
        // that is not one of the lecturer's allocations simply does not resolve, so a
        // tampered value cannot reach the summary. The store re-checks through
        // `resolveOfferingForManager()` regardless, so nothing here is load-bearing.
        $selectedOfferingId = (int) $request->input('course_offering_id', 0);
        $offering = $selectedOfferingId > 0
            ? $offerings->first(fn ($row) => (int) $row->id === $selectedOfferingId)
            : null;

        return view('teacher.online_exam.create', [
            'exam' => null,
            'subjects' => $subjects,
            'classes' => $classes,
            'sessions' => $sessions,
            'programmes' => $programmes,
            'structureLocked' => false,
            'readinessErrors' => [],
            'offerings' => $offerings,
            'selectedOfferingId' => $offering ? (int) $offering->id : 0,
            'offering' => $offering,
            'lecturer' => $user,
        ]);
    }

    public function teacherStore(StoreOnlineExamRequest $request)
    {
        $this->authorize('create', OnlineExam::class);

        $user = Auth::user();
        $validated = $request->validated();

        $payload = [
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'exam_type' => $validated['exam_type'],
            'start_datetime' => $validated['start_datetime'],
            'end_datetime' => $validated['end_datetime'],
            'duration_mins' => (int) ($validated['duration_mins'] ?? 0),
            'total_marks' => (int) $validated['total_marks'],
            'pass_mark' => (int) $validated['pass_mark'],
            'max_attempts' => (int) ($validated['max_attempts'] ?? 1),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
            'allow_previous_navigation' => (bool) ($validated['allow_previous_navigation'] ?? true),
            'result_release_policy' => $validated['result_release_policy'] ?? 'immediate',
            'webcam_required' => (bool) ($validated['webcam_required'] ?? false),
            'fullscreen_required' => (bool) ($validated['fullscreen_required'] ?? false),
            'auto_submit' => (bool) ($validated['auto_submit'] ?? true),
            // Set per-mode below. The LEGACY value is left exactly as it always was.
            'is_published' => 0,
            'school_id' => $this->school_id,
            'created_by' => $user->id,
            'creator_id' => $user->id,
            'updater_id' => $user->id,
        ];

        // ── THE ONE PLACE THE TWO MODES DIVERGE ──────────────────────────────
        //
        // Course Offering: the academic context is READ from the Offering that
        // `StoreOnlineExamRequest` already resolved and authorised. Not one of
        // `subject_id`, `class_id`, `programme_id`, `session_id`, `school_id` or
        // `course_offering_id` comes from the request in this branch.
        //
        // Legacy: exactly the values that were always written, from the validated
        // body. Nothing about the legacy path changes, including its NULL arms.
        if ($request->isCourseOfferingMode()) {
            $offering = $request->offering();

            abort_if(
                $offering === null,
                404,
                'Course Offering not found, or you are not allocated to teach it.'
            );

            $payload = array_merge(
                $payload,
                app(CourseOfferingExamAccess::class)->authoritativeFieldsFor($offering)
            );

            // Same value the Offering-scoped controller writes, and for the same
            // reason: `StoreOnlineExamRequest::validated()` resolves it to
            // `pending_review` for anyone who cannot publish, so a lecturer's new
            // course assessment enters the ADMIN REVIEW queue rather than sitting
            // as an unpublished draft nobody is told about.
            $payload['workflow_state'] = $validated['workflow_state'] ?? 'draft';
            $payload['is_published'] = ($validated['workflow_state'] ?? 'draft') === 'published';

            AuditLog::record('create', 'Online Exams', "Teacher created Course Offering exam: {$payload['title']} (Offering #{$offering->id})");

            $exam = DB::transaction(fn() => OnlineExam::create($payload));

            // Straight to the ENGINE's question page, which is where a lecturer
            // authors questions in exactly one place in PIIE. Same destination as the
            // Offering-scoped controller, so both entrances lead to one workflow.
            return redirect()
                ->route('teacher.online_exams.questions.index', $exam->id)
                ->with('success', 'Exam created as a draft for ' . ($offering->subject?->name ?? 'your course') . '. Add its questions next — it cannot be published until it has them.');
        }

        $payload['subject_id'] = $validated['subject_id'];
        $payload['class_id'] = $validated['class_id'] ?? null;
        $payload['programme_id'] = $validated['programme_id'] ?? null;
        $payload['session_id'] = $validated['session_id'] ?? null;
        $payload['workflow_state'] = 'draft';

        if (!Schema::hasColumn('online_exams', 'programme_id')) unset($payload['programme_id']);
        if (!Schema::hasColumn('online_exams', 'session_id')) unset($payload['session_id']);

        $exam = DB::transaction(fn() => OnlineExam::create($payload));
        AuditLog::record('create', 'Online Exams', "Teacher created exam: {$exam->title}");

        return redirect()->route('teacher.online_exams.edit', $exam->id)->with('success', get_phrase('Exam created as draft.'));
    }

    public function teacherShow(OnlineExam $exam)
    {
        $this->authorize('view', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $exam->load([
            'subject',
            'classRoom',
            'questions',
            'submissions.student',
        ]);

        return view('teacher.online_exam.show', [
            'exam' => $exam,
            'structureLocked' => $exam->isStructurallyLocked(),
            'readinessErrors' => $exam->publicationReadinessErrors(),
        ]);
    }

    /**
     * GET /teacher/online-exams/{exam}/edit
     *
     * The form's MODE is the exam's, not the lecturer's. An exam that belongs to a
     * Course Offering is edited with its academic context read-only and its legacy
     * selectors hidden - not because they would be rejected, but because there is no
     * legitimate value to put in them: the course already fixed them.
     *
     * The Offering list is still supplied, because an exam created before the
     * allocation was recorded is not a reason to lose the ability to edit it.
     */
    public function teacherEdit(OnlineExam $exam)
    {
        $this->authorize('update', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $user = Auth::user();
        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);
        $subjects = $this->teacherAssignableSubjects((int) $user->id, $assignedClassIds);
        $classes = $this->teacherAssignableClasses($assignedClassIds);
        $programmes = $this->teacherAssignableProgrammes((int) $user->id);
        $sessions = $this->academicSessionsForSelection();

        // Tenant from the exam row, never from the request: the exam's own school was
        // already asserted above, so the context rendered here is the same one the
        // update will write.
        //
        // LAZY, not eager, and that is not an oversight. `CourseOffering::subject()`
        // applies `where('school_id', $this->school_id)` when the RELATION is
        // defined, so eager loading instantiates an attribute-less parent, gets
        // `school_id IS NULL`, and silently returns null for a perfectly valid
        // Course Unit. See the note on `CourseOffering::academicYear()` for the full
        // account. On this instance `$school_id` is present, so lazy loading is both
        // correct and one indexed query.
        $offering = $exam->course_offering_id !== null && Schema::hasTable('course_offerings')
            ? \App\Models\CourseOffering::query()
                ->where('school_id', (int) $exam->school_id)
                ->whereKey((int) $exam->course_offering_id)
                ->first()
            : null;

        return view('teacher.online_exam.edit', [
            'exam' => $exam,
            'subjects' => $subjects,
            'classes' => $classes,
            'sessions' => $sessions,
            'programmes' => $programmes,
            'structureLocked' => $exam->isStructurallyLocked(),
            'readinessErrors' => $exam->publicationReadinessErrors(),
            'offerings' => app(CourseOfferingExamAccess::class)->teachableOfferings($user),
            // An Offering exam whose Offering row has vanished still renders the form
            // - with `$offering` NULL it falls back to legacy selectors, and
            // `UpdateOnlineExamRequest` refuses the save rather than silently moving
            // the paper onto the school-wide legacy path.
            'offering' => $offering,
            'selectedOfferingId' => $offering ? (int) $offering->id : 0,
            'lecturer' => $user,
        ]);
    }

    public function teacherUpdate(UpdateOnlineExamRequest $request, OnlineExam $exam)
    {
        $this->authorize('update', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $validated = $request->validated();

        DB::transaction(function () use ($exam, $validated, $request) {
            $payload = [
                'title' => $validated['title'],
                'instructions' => $validated['instructions'] ?? null,
                'exam_type' => $validated['exam_type'],
                'start_datetime' => $validated['start_datetime'],
                'end_datetime' => $validated['end_datetime'],
                'duration_mins' => (int) ($validated['duration_mins'] ?? 0),
                'total_marks' => (int) $validated['total_marks'],
                'pass_mark' => (int) $validated['pass_mark'],
                'max_attempts' => (int) ($validated['max_attempts'] ?? $exam->max_attempts),
                'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
                'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
                'allow_previous_navigation' => (bool) ($validated['allow_previous_navigation'] ?? true),
                'result_release_policy' => $validated['result_release_policy'] ?? 'immediate',
                'webcam_required' => (bool) ($validated['webcam_required'] ?? false),
                'fullscreen_required' => (bool) ($validated['fullscreen_required'] ?? false),
                'auto_submit' => (bool) ($validated['auto_submit'] ?? true),
                'updater_id' => Auth::id(),
            ];

            // ── SAME ONE PLACE, AT UPDATE TIME ─────────────────────────────────
            //
            // `course_offering_id` is written from the exam ROW and never from the
            // request, so an edit cannot re-point the paper; `UpdateOnlineExamRequest`
            // rejects an attempt to, and this makes the write independent of that.
            if ($request->isCourseOfferingMode()) {
                $offering = $request->offering();
                abort_if($offering === null, 422, 'The Course Offering for this assessment could not be resolved.');

                $payload = array_merge(
                    $payload,
                    app(CourseOfferingExamAccess::class)->authoritativeFieldsFor($offering)
                );
            } else {
                $payload['subject_id'] = $validated['subject_id'];
                $payload['class_id'] = $validated['class_id'] ?? null;
                $payload['programme_id'] = $validated['programme_id'] ?? null;
                $payload['session_id'] = $validated['session_id'] ?? null;

                if (!Schema::hasColumn('online_exams', 'programme_id')) unset($payload['programme_id']);
                if (!Schema::hasColumn('online_exams', 'session_id')) unset($payload['session_id']);
            }

            $exam->update($payload);
        });

        AuditLog::record('update', 'Online Exams', "Teacher updated exam: {$exam->title}");
        return redirect()->route('teacher.online_exams.edit', $exam->id)->with('success', get_phrase('Exam updated.'));
    }

    public function teacherDestroy(OnlineExam $exam)
    {
        $this->authorize('delete', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        if (!in_array((string) $exam->workflow_state, ['draft', 'pending_review'], true)) {
            return redirect()->back()->withErrors(['exam' => get_phrase('Only draft or pending review exams can be deleted.')]);
        }

        if ($exam->submissions()->exists()) {
            return redirect()->back()->withErrors(['exam' => get_phrase('Cannot delete an exam that already has attempts.')]);
        }

        DB::transaction(function () use ($exam) {
            $exam->questions()->delete();
            $exam->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Teacher deleted exam: {$exam->title}");
        return redirect()->route('teacher.online_exams.index')->with('success', get_phrase('Exam deleted.'));
    }

    public function teacherPreview(OnlineExam $exam)
    {
        $this->authorize('view', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $questions = OnlineExamQuestion::forExam($exam->id)
            ->ordered()
            ->get()
            ->map(function (OnlineExamQuestion $question) {
                $question->correct_ans = null;
                return $question;
            });

        return view('teacher.online_exam.preview', compact('exam', 'questions'));
    }

    public function teacherSubmitForReview(OnlineExam $exam)
    {
        $this->authorize('update', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $errors = $exam->publicationReadinessErrors();
        if (!empty($errors)) {
            return redirect()->back()->withErrors(['readiness' => implode(' ', $errors)]);
        }

        DB::transaction(function () use ($exam) {
            $exam->update([
                'workflow_state' => 'pending_review',
                'is_published' => 0,
                'reviewed_at' => now(),
                'updater_id' => Auth::id(),
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Teacher submitted exam for review: {$exam->title}");
        OnlineExamPortalNotifier::admins('exam_submitted_for_review', 'Exam Awaiting Review', Auth::user()->name . ' submitted "' . $exam->title . '" for review.', $exam, Auth::id(), 'exam-review:' . $exam->id);
        return redirect()->back()->with('success', get_phrase('Exam submitted for review.'));
    }

    public function markPortalNotificationRead(OnlineExamUserNotification $notification)
    {
        $user = Auth::user();
        abort_unless($user && (int) $notification->school_id === (int) $user->school_id && (int) $notification->user_id === (int) $user->id, 404);

        if (!$notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        // Resolved against the CURRENT request rather than replayed as stored. A
        // row written while the app was served from one host, and clicked while it
        // is served from another, must land on the application the reader is
        // actually using. Absolute links to this application that were stored
        // before this was introduced are re-rooted here rather than 404ing on a
        // different port. See OnlineExamNotificationLink.
        return redirect()->to(
            \App\Support\OnlineExams\OnlineExamNotificationLink::toAbsolute($notification->action_url)
                ?: url()->previous()
        );
    }

    public function teacherPublish(Request $request, OnlineExam $exam)
    {
        abort(403, 'Only an administrator may publish an exam.');
    }

    public function teacherUnpublish(OnlineExam $exam)
    {
        abort(403, 'Only an administrator may change official publication.');
    }

    public function teacherCancel(Request $request, OnlineExam $exam)
    {
        $this->authorize('cancel', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        if ($exam->submissions()->where('status', OnlineExamSubmission::STATUS_IN_PROGRESS)->exists()) {
            return redirect()->back()->withErrors(['exam' => get_phrase('Cannot cancel an exam while candidates are active.')]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($exam, $validated) {
            $exam->update([
                'workflow_state' => 'cancelled',
                'is_published' => 0,
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['reason'],
                'updater_id' => Auth::id(),
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Teacher cancelled exam: {$exam->title}");
        return redirect()->back()->with('success', get_phrase('Exam cancelled.'));
    }

    public function teacherQuestions(OnlineExam $exam)
    {
        $this->authorize('manageQuestions', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $user = Auth::user();
        $questions = OnlineExamQuestion::forExam($exam->id)->ordered()->get();
        $bank = QuestionBank::visibleToTeacher((int) $user->id, $this->school_id)
            ->when(!empty($exam->subject_id), fn($q) => $q->where('subject_id', $exam->subject_id))
            ->latest('id')
            ->limit(150)
            ->get();

        return view('teacher.online_exam.questions', [
            'exam' => $exam,
            'questions' => $questions,
            'bank' => $bank,
            'questionMarksTotal' => (int) $questions->sum('marks'),
            'structureLocked' => $exam->isStructurallyLocked(),
        ]);
    }

    public function teacherStoreQuestion(StoreOnlineExamQuestionRequest $request, OnlineExam $exam)
    {
        $this->authorize('manageQuestions', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $validated = $request->validated();

        DB::transaction(function () use ($exam, $validated) {
            $nextSort = ((int) OnlineExamQuestion::forExam($exam->id)->max('sort_order')) + 1;
            OnlineExamQuestion::create([
                'online_exam_id' => $exam->id,
                'question' => $validated['question'],
                'type' => $validated['type'],
                'option_a' => $validated['option_a'] ?? null,
                'option_b' => $validated['option_b'] ?? null,
                'option_c' => $validated['option_c'] ?? null,
                'option_d' => $validated['option_d'] ?? null,
                'correct_ans' => $validated['correct_ans'] ?? null,
                'question_schema_version' => $validated['question_schema_version'] ?? null,
                'question_config' => $validated['question_config'] ?? null,
                'marking_config' => $validated['marking_config'] ?? null,
                'marks' => $validated['marks'],
                'sort_order' => $nextSort,
            ]);
        });

        AuditLog::record('create', 'Online Exams', "Teacher added question in exam #{$exam->id}");
        return redirect()->back()->with('success', get_phrase('Question added.'));
    }

    public function teacherUpdateQuestion(UpdateOnlineExamQuestionRequest $request, OnlineExamQuestion $question)
    {
        $question->loadMissing('exam');
        abort_unless($question->exam && (int) $question->exam->school_id === (int) $this->school_id, 404);
        $this->authorize('update', $question);

        $validated = $request->validated();

        DB::transaction(function () use ($question, $validated) {
            $question->update([
                'question' => $validated['question'],
                'type' => $validated['type'],
                'option_a' => $validated['option_a'] ?? null,
                'option_b' => $validated['option_b'] ?? null,
                'option_c' => $validated['option_c'] ?? null,
                'option_d' => $validated['option_d'] ?? null,
                'correct_ans' => $validated['correct_ans'] ?? null,
                'question_schema_version' => $validated['question_schema_version'] ?? null,
                'question_config' => $validated['question_config'] ?? null,
                'marking_config' => $validated['marking_config'] ?? null,
                'marks' => $validated['marks'],
            ]);
        });

        AuditLog::record('update', 'Online Exams', "Teacher updated question #{$question->id}");
        return redirect()->back()->with('success', get_phrase('Question updated.'));
    }

    public function teacherDeleteQuestion(OnlineExamQuestion $question)
    {
        $question->loadMissing('exam');
        abort_unless($question->exam && (int) $question->exam->school_id === (int) $this->school_id, 404);
        $this->authorize('delete', $question);

        DB::transaction(function () use ($question) {
            $question->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Teacher deleted question #{$question->id}");
        return redirect()->back()->with('success', get_phrase('Question deleted.'));
    }

    public function teacherReorderQuestions(Request $request, OnlineExam $exam)
    {
        $this->authorize('manageQuestions', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        if ($exam->isStructurallyLocked()) {
            return redirect()->back()->withErrors(['questions' => get_phrase('Questions are locked after attempts have started.')]);
        }

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['required', 'integer'],
        ]);

        $allowedIds = OnlineExamQuestion::forExam($exam->id)->pluck('id')->map(fn($id) => (int) $id)->all();
        foreach ($validated['question_ids'] as $questionId) {
            if (!in_array((int) $questionId, $allowedIds, true)) {
                return redirect()->back()->withErrors(['questions' => get_phrase('Invalid question order payload.')]);
            }
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated['question_ids'] as $index => $questionId) {
                OnlineExamQuestion::where('id', (int) $questionId)->update([
                    'sort_order' => $index + 1,
                ]);
            }
        });

        AuditLog::record('update', 'Online Exams', "Teacher reordered questions in exam #{$exam->id}");
        return redirect()->back()->with('success', get_phrase('Question order updated.'));
    }

    public function teacherQuestionBank(Request $request)
    {
        $user = Auth::user();
        $permissionService = app(OnlineExamPermissionService::class);
        abort_unless($permissionService->has($user, 'manage_exam_questions'), 403);

        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);
        $search = trim((string) $request->input('search', ''));
        $subjectId = (int) $request->input('subject_id', 0);
        $programmeId = (int) $request->input('programme_id', 0);
        $sessionId = (int) $request->input('session_id', 0);
        $status = (string) $request->input('status', '');
        $topicId = (int) $request->input('topic_id', 0);
        $subtopicId = (int) $request->input('subtopic_id', 0);
        $tagId = (int) $request->input('tag_id', 0);
        $type = (string) $request->input('type', '');
        $difficulty = (string) $request->input('difficulty', '');

        $query = $this->teacherQuestionBankQuery($user, $assignedClassIds)
            ->with('subject');

        $query->when($search !== '', function ($q) use ($search) {
            $q->where('question', 'like', '%' . $search . '%');
        });

        if ($subjectId > 0) {
            $query->where('subject_id', $subjectId);
        }
        $query->when($programmeId > 0, fn($q) => $q->where('programme_id', $programmeId))
            ->when($sessionId > 0, fn($q) => $q->where('session_id', $sessionId))
            ->when($topicId > 0, fn($q) => $q->where('topic_id', $topicId))
            ->when($subtopicId > 0, fn($q) => $q->where('subtopic_id', $subtopicId))
            ->when($tagId > 0, fn($q) => $q->whereHas('tags', fn($t) => $t->where('question_tags.id', $tagId)))
            ->when($type !== '', fn($q) => $q->where('type', $type))
            ->when($difficulty !== '', fn($q) => $q->where('difficulty', $difficulty))
            ->when($status !== '', fn($q) => $q->where('status', $status));

        $questions = $query->orderByDesc('id')->paginate(20)->appends($request->all());
        $subjects = $this->teacherAssignableSubjects((int) $user->id, $assignedClassIds);
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)
            ->whereIn('id', TeacherProgrammeAssignment::where('school_id', $this->school_id)->where('teacher_id', $user->id)->pluck('programme_id'))
            ->orderBy('name')->get();
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $topics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNull('parent_id')->orderBy('name')->get();
        $subtopics = QuestionTopic::where('school_id',$this->school_id)->where('is_active',1)->whereNotNull('parent_id')->orderBy('name')->get();
        $tags = QuestionTag::where('school_id',$this->school_id)->where('is_active',1)->orderBy('name')->get();

        return view('teacher.online_exam.question_bank', compact('questions', 'subjects', 'search', 'subjectId', 'programmes', 'sessions', 'programmeId', 'sessionId', 'topicId', 'subtopicId', 'tagId', 'type', 'difficulty', 'status', 'topics', 'subtopics', 'tags'))
            ->with('canCreateBankQuestion', $permissionService->has($user, 'manage_exam_questions'));
    }

    public function teacherStoreBankQuestion(Request $request)
    {
        $user = Auth::user();
        $permissionService = app(OnlineExamPermissionService::class);
        abort_unless($permissionService->has($user, 'manage_exam_questions'), 403);
        $this->normalizeBankStructuredOptions($request);

        $validated = $request->validate([
            'subject_id'  => ['required', 'integer', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
            'programme_id' => ['nullable', Rule::exists('programmes','id')->where(fn($q)=>$q->where('school_id',$this->school_id))], 'session_id' => ['nullable', Rule::exists('sessions','id')->where(fn($q)=>$q->where('school_id',$this->school_id))],
            'topic_id' => ['nullable','integer'], 'subtopic_id' => ['nullable','integer'], 'tag_ids' => ['nullable','array','max:20'], 'tag_ids.*' => ['integer'], 'status' => ['nullable','in:draft,active,retired,archived'],
            'question'    => ['required', 'string'],
            'type'        => ['required', 'in:mcq,true_false,short,essay,multiple_select,numeric,matching,ordering'],
            'option_a'    => ['nullable', 'string'],
            'option_b'    => ['nullable', 'string'],
            'option_c'    => ['nullable', 'string'],
            'option_d'    => ['nullable', 'string'],
            'correct_ans' => ['nullable', 'string', 'max:255'],
            'correct_answer_tf' => ['nullable', 'string', 'in:true,false'],
            'marks'       => ['required', 'integer', 'min:1', 'max:127'],
            'difficulty'  => ['required', 'in:easy,medium,hard'],
            'structured_options' => ['nullable', 'array', 'max:8'],
            'structured_options.*.id' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'],
            'structured_options.*.label' => ['required', 'string', 'max:1000'],
            'correct_option_ids' => ['nullable', 'array', 'max:8'],
            'correct_option_ids.*' => ['required', 'string', 'max:32'],
            'numeric_target' => ['nullable'], 'numeric_tolerance' => ['nullable'],
            'structured_blanks' => ['nullable', 'array', 'max:16'],
            'structured_blanks.*.id' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'],
            'structured_blanks.*.accepted_answers' => ['required', 'array', 'max:8'],
            'structured_blanks.*.accepted_answers.*' => ['required', 'string', 'max:255'],
            'case_sensitive' => ['nullable', 'boolean'], 'trim_whitespace' => ['nullable', 'boolean'],
            'structured_pairs' => ['nullable','array','max:16'], 'structured_pairs.*.left_id'=>['required','string','max:32'], 'structured_pairs.*.left_text'=>['required','string','max:1000'], 'structured_pairs.*.right_id'=>['required','string','max:32'], 'structured_pairs.*.right_text'=>['required','string','max:1000'],
            'structured_order_items' => ['nullable','array','max:16'], 'structured_order_items.*.id'=>['required','string','max:32'], 'structured_order_items.*.text'=>['required','string','max:1000'],
        ]);
        if (in_array($validated['type'], ['multiple_select', 'numeric','matching','ordering'], true) || ($validated['type'] === 'fill_blank' && !empty($validated['structured_blanks']))) {
            $validated = array_merge($validated, $this->structuredBankFields($validated));
        } else {
            $validated['correct_ans'] = $this->canonicalizeBankAnswer($validated);
        }
        unset($validated['correct_answer_tf']);
        $this->validateQuestionBankAcademicMetadata($validated, $user);

        if (!$permissionService->teacherCanUseSubject($user, (int) $validated['subject_id'])) {
            return redirect()->back()->withErrors(['subject_id' => get_phrase('You are not assigned to the selected subject/class.')])->withInput();
        }

        $bankQuestion = QuestionBank::create([
            'school_id' => $this->school_id,
            'subject_id' => $validated['subject_id'],
                ...$this->questionBankMetadataPayload($validated),
            'question' => $validated['question'],
            'type' => $validated['type'],
            'option_a' => $validated['option_a'] ?? null,
            'option_b' => $validated['option_b'] ?? null,
            'option_c' => $validated['option_c'] ?? null,
            'option_d' => $validated['option_d'] ?? null,
            'correct_ans' => $validated['correct_ans'] ?? null,
            'question_schema_version' => $validated['question_schema_version'] ?? null,
            'question_config' => $validated['question_config'] ?? null,
            'marking_config' => $validated['marking_config'] ?? null,
            'marks' => $validated['marks'],
            'difficulty' => $validated['difficulty'],
            'created_by' => $user->id,
        ]);
        if (Schema::hasTable('question_bank_tag') && !empty($validated['tag_ids'])) $bankQuestion->tags()->sync($validated['tag_ids']);

        AuditLog::record('create', 'Online Exams', "Teacher added question bank item");
        return redirect()->route('teacher.online_exams.question_bank')->with('success', get_phrase('Question added to bank.'));
    }

    public function teacherImportQuestion(Request $request, OnlineExam $exam)
    {
        $this->authorize('manageQuestions', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        if ($exam->isStructurallyLocked()) {
            return redirect()->back()->withErrors(['questions' => get_phrase('Questions are locked after attempts have started.')]);
        }

        $validated = $request->validate([
            'question_bank_ids' => ['required', 'array', 'min:1'],
            'question_bank_ids.*' => ['required', 'integer', 'exists:question_banks,id'],
        ]);

        $user = Auth::user();
        $bankQuestions = $this->teacherQuestionBankQuery($user, $this->teacherAssignedClassIds((int) $user->id))
            ->whereIn('id', $validated['question_bank_ids'])
            ->get();

        $imported = 0;
        DB::transaction(function () use ($exam, $bankQuestions, &$imported) {
            $nextSort = ((int) OnlineExamQuestion::forExam($exam->id)->max('sort_order')) + 1;
            foreach ($bankQuestions as $bankQuestion) {
                OnlineExamQuestion::create([
                    'online_exam_id' => $exam->id,
                    'question_bank_id' => $bankQuestion->id,
                    'question' => $bankQuestion->question,
                    'type' => $this->snapshotStorageType($bankQuestion),
                    'option_a' => $bankQuestion->option_a,
                    'option_b' => $bankQuestion->option_b,
                    'option_c' => $bankQuestion->option_c,
                    'option_d' => $bankQuestion->option_d,
                    'correct_ans' => \App\Support\OnlineExams\AnswerKey::normalize($bankQuestion->normalized_type, $bankQuestion->correct_ans, ['a' => $bankQuestion->option_a, 'b' => $bankQuestion->option_b, 'c' => $bankQuestion->option_c, 'd' => $bankQuestion->option_d]),
                    'question_schema_version' => $bankQuestion->question_schema_version,
                    'question_config' => $bankQuestion->question_config,
                    'marking_config' => $bankQuestion->marking_config,
                    'marks' => $bankQuestion->marks,
                    'sort_order' => $nextSort,
                ]);
                $nextSort++;
                $imported++;
            }
        });

        AuditLog::record('create', 'Online Exams', "Teacher imported {$imported} question(s) from bank into exam #{$exam->id}");
        return redirect()->back()->with('success', get_phrase('Questions imported from bank.'));
    }

    public function teacherBankModal()
    {
        $user = Auth::user();
        abort_unless(app(OnlineExamPermissionService::class)->has($user, 'manage_exam_questions'), 403);

        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);
        $subjects = $this->teacherAssignableSubjects((int) $user->id, $assignedClassIds);

        return view('teacher.online_exam.bank_modal', compact('subjects'));
    }

    public function teacherBankImportModal()
    {
        abort_unless(app(OnlineExamPermissionService::class)->has(Auth::user(), 'manage_exam_questions'), 403);

        return view('teacher.online_exam.bank_import_modal');
    }

    public function teacherImportBankQuestions(Request $request)
    {
        abort_unless(app(OnlineExamPermissionService::class)->has(Auth::user(), 'manage_exam_questions'), 403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = \App\Support\OnlineExams\QuestionBankImporter::import(
            $validated['file'],
            $this->school_id,
            Auth::id()
        );

        AuditLog::record('create', 'Online Exams', "Teacher imported {$result['imported']} question(s) into the bank from a file.");

        $summary = trans_choice(':count question imported.|:count questions imported.', $result['imported'], ['count' => $result['imported']]);

        if ($result['errors']) {
            $summary .= ' ' . count($result['errors']) . ' row(s) skipped.';
        }

        return redirect()->back()->with(
            $result['imported'] > 0 ? 'success' : 'error',
            $summary
        )->with('import_errors', $result['errors'])->with('import_warnings', $result['warnings']);
    }

    public function teacherDownloadBankImportTemplate()
    {
        abort_unless(app(OnlineExamPermissionService::class)->has(Auth::user(), 'manage_exam_questions'), 403);

        $headers = \App\Support\OnlineExams\QuestionBankImporter::TEMPLATE_HEADERS;
        $example = [
            'What is the capital of Uganda?', 'mcq', 'Kampala', 'Nairobi', 'Kigali', 'Lagos', 'a', '2', 'easy', '',
        ];

        $csv = implode(',', $headers) . "\n" . implode(',', array_map(function ($v) {
            return '"' . str_replace('"', '""', $v) . '"';
        }, $example)) . "\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="question_bank_template.csv"',
        ]);
    }

    public function teacherDestroyBankQuestion($id)
    {
        $user = Auth::user();
        abort_unless(app(OnlineExamPermissionService::class)->has($user, 'manage_exam_questions'), 403);

        $question = QuestionBank::where('school_id', $this->school_id)
            ->where('created_by', $user->id)
            ->findOrFail((int) $id);

        DB::transaction(function () use ($question) {
            $question->delete();
        });

        AuditLog::record('delete', 'Online Exams', "Teacher deleted question bank question #{$id}");
        return redirect()->back()->with('success', get_phrase('Question deleted'));
    }

    public function teacherAttempts(OnlineExam $exam)
    {
        $this->authorize('viewAttempts', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $submissions = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with(['student', 'exam.questions', 'answerRows'])
            ->withCount('proctoringEvents')
            ->orderByDesc('submitted_at')
            ->paginate(30);

        $canReviewProctoring = app(OnlineExamPermissionService::class)->has(Auth::user(), 'review_exam_proctoring');

        return view('teacher.online_exam.attempts', compact('exam', 'submissions', 'canReviewProctoring'));
    }

    public function teacherReviewProctoring(OnlineExam $exam, $submission_id)
    {
        $this->authorize('reviewProctoring', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $submission = OnlineExamSubmission::where('id', (int) $submission_id)
            ->where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with('student')
            ->firstOrFail();
        $this->authorize('view', $submission);

        $events = OnlineExamProctoringEvent::forSubmission($submission->id)
            ->chronological()
            ->paginate(100);

        return view('teacher.online_exam.proctoring', compact('exam', 'submission', 'events'));
    }

    public function teacherResults(Request $request, OnlineExam $exam)
    {
        $this->authorize('viewAttempts', $exam);
        abort_unless((int) $exam->school_id === (int) $this->school_id, 404);

        $submissions = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with(['student', 'exam.questions', 'answerRows'])
            ->orderByDesc('submitted_at')
            ->paginate(30);

        // ── THE SUBMISSION BEING READ ────────────────────────────────────────
        //
        // The summary above is a list of one row per student; marking is a job done
        // on ONE paper at a time, against the question and the student's own words.
        //
        // Those used to share one table, which is what made the page unreadable: a
        // twelve-column table with a 1250px floor cannot hold a written answer, and
        // the mark input was squeezed into the last column while the answer it
        // belonged to sat in a full-width row above it. So the selection is resolved
        // here and rendered full width, below the summary.
        //
        // Constrained to this exam and this school, so a crafted id from another paper
        // or another tenant resolves to nothing rather than leaking it. When nothing
        // is selected, the newest submission on this page is offered, so the marking
        // section is never an empty heading.
        $selectedSubmission = null;
        $requestedSubmissionId = (int) $request->query('submission', 0);

        if ($requestedSubmissionId > 0) {
            $selectedSubmission = OnlineExamSubmission::where('id', $requestedSubmissionId)
                ->where('online_exam_id', $exam->id)
                ->where('school_id', $this->school_id)
                ->with(['student', 'answerRows.question'])
                ->first();
        }

        if (! $selectedSubmission) {
            $selectedSubmission = $submissions->first();
        }

        $markingCards = [];
        $selectedUndecided = [];

        if ($selectedSubmission) {
            $answersByQuestion = $selectedSubmission->answerRows->keyBy('question_id');

            // ONE CARD PER QUESTION ON THE PAPER, not per answer row.
            //
            // A question the student never answered has no row, and those are exactly
            // the questions a marker still owes a decision on. Building the list from
            // the answer rows would render a paper with a blank question invisible,
            // which is how an unmarkable question ends up looking marked.
            $markingCards = $exam->questions
                ->sortBy(fn ($q) => [(int) $q->sort_order, (int) $q->id])
                ->map(function ($question) use ($answersByQuestion) {
                    $answer = $answersByQuestion->get($question->id);
                    $automatic = \App\Support\OnlineExams\OnlineExamMarking::isAutomatic($question);

                    return [
                        'question' => $question,
                        'answer' => $answer,
                        'automatic' => $automatic,

                        // A question with no answer row has had nothing recorded, so
                        // there is nothing to mark and nothing to report. That is
                        // different from a row that exists and is blank, which is
                        // evidence of an autosave failure and is shown as such.
                        'has_response' => \App\Support\OnlineExams\OnlineExamMarking::hasResponse($answer),
                        'decided' => ! $automatic
                            && $answer !== null
                            && \App\Support\OnlineExams\OnlineExamMarking::isManuallyMarked($answer),
                    ];
                })
                ->values()
                ->all();

            $selectedUndecided = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($selectedSubmission);
        }

        // Whether marking controls are offered at all. This MIRRORS the conditions
        // `recordQuestionDecision()` enforces, so the page never offers a control that
        // can only be refused. It is a display decision only: the endpoint remains
        // the authority, and nothing here grants or withholds permission.
        $markingOpen = (bool) $selectedSubmission
            && ! $selectedSubmission->isFinalized()
            && ! $selectedSubmission->isResultVisible()
            && in_array($selectedSubmission->status, [
                OnlineExamSubmission::STATUS_SUBMITTED,
                OnlineExamSubmission::STATUS_TIMED_OUT,
                OnlineExamSubmission::STATUS_PENDING_MANUAL,
            ], true);

        return view('teacher.online_exam.results', compact(
            'exam',
            'submissions',
            'selectedSubmission',
            'markingCards',
            'selectedUndecided',
            'markingOpen',
        ));
    }

    public function teacherMarking(Request $request)
    {
        $user = Auth::user();
        $permissionService = app(OnlineExamPermissionService::class);
        abort_unless($permissionService->has($user, 'mark_exam_answers'), 403);

        $authorizer = app(OnlineExamAuthorizer::class);
        $canEditAll = $authorizer->can($user, 'edit_all_online_exams');
        $assignedClassIds = $this->teacherAssignedClassIds((int) $user->id);

        $query = OnlineExamAnswer::query()
            ->with(['submission.student', 'submission.exam.questions', 'submission.answerRows', 'question'])
            // `courseOffering` only when the table exists. Most of this engine's
            // suites hand-roll a partial schema that predates the Course Offering
            // work, and eager-loading a relation onto a table that was never created
            // is an unconditional 500 on the queue every marker opens. Guarded, not
            // assumed - the same decision `teacherIndex()` already makes.
            ->when(
                Schema::hasTable('course_offerings'),
                fn ($q) => $q->with(['submission.exam.courseOffering'])
            )
            ->whereHas('submission', function ($submissionQ) use ($canEditAll, $user, $assignedClassIds) {
                $submissionQ->where('school_id', $this->school_id)
                    ->whereHas('exam', function ($examQ) use ($canEditAll, $assignedClassIds, $user) {
                        // ── THE SAME SCOPE AS THE EXAM LIST ────────────────────
                        //
                        // This used to restate ownership by hand - `creator_id`,
                        // `created_by`, `class_id` - and so inherited the exact defect
                        // the exam list had: a Course Offering exam has `class_id` NULL
                        // by design, so the only arm that could ever match it was
                        // authorship. A CO-LECTURER allocated to the course was told
                        // their queue was empty while somebody else's answers sat
                        // unmarked.
                        //
                        // Delegated to `applyTeacherOwnershipScope()` so the list a
                        // lecturer browses and the queue they mark can never disagree
                        // about which papers are theirs.
                        $this->applyTeacherOwnershipScope($examQ, $user, $assignedClassIds, $canEditAll);
                    });
            })
            ->whereHas('question', function ($questionQ) {
                \App\Support\OnlineExams\OnlineExamMarking::manualQuestions($questionQ);
            });

        \App\Support\OnlineExams\OnlineExamMarking::responses($query);
        $query->whereHas('submission', fn ($q) => $q->whereIn('status', ['submitted', 'timed_out', 'pending_manual_marking']));
        $status = trim((string) $request->input('status', 'pending'));
        if ($status === 'marked') {
            $query->whereNotNull('awarded_marks')->whereNotNull('marked_at')->whereNotNull('marked_by');
        } else {
            \App\Support\OnlineExams\OnlineExamMarking::unmarked($query);
        }

        $answers = $query->orderBy('id')->paginate(25)->appends($request->all());

        /**
         * SUBMISSIONS WITH NOTHING LEFT TO MARK, WAITING TO BE HANDED OVER.
         *
         * ── THE DEFECT THIS EXISTS TO FIX ──────────────────────────────────
         *
         * The handover control used to live only inside the summary row printed
         * beneath each ANSWER. So the moment a marker awarded the last mark, that
         * answer stopped being "pending", the queue's default filter stopped
         * matching it, and the control vanished from the screen. A lecturer who had
         * just finished the whole paper was left staring at an empty queue with no
         * way to submit it - which is precisely what was reported.
         *
         * Marking and handing over are different acts and now have different
         * surfaces: the queue below is for AWARDS, this is for SUBMISSION.
         *
         * The handover action itself is unchanged and still
         * `teacher.online_exams.results.finalize`, which does not publish - it sets
         * `result_review_state = pending_review` and notifies the academic office.
         */
        $readyForHandover = OnlineExamSubmission::query()
            ->where('school_id', $this->school_id)
            ->whereIn('status', [
                OnlineExamSubmission::STATUS_SUBMITTED,
                OnlineExamSubmission::STATUS_TIMED_OUT,
                OnlineExamSubmission::STATUS_PENDING_MANUAL,
            ])
            ->with(['student', 'exam'])
            ->when(
                Schema::hasTable('course_offerings'),
                fn ($q) => $q->with(['exam.courseOffering'])
            )
            ->whereHas('exam', function ($examQ) use ($canEditAll, $assignedClassIds, $user) {
                $this->applyTeacherOwnershipScope($examQ, $user, $assignedClassIds, $canEditAll);
            })
            ->get()
            ->filter(function (OnlineExamSubmission $submission) {
                return \App\Support\OnlineExams\OnlineExamMarking::summary($submission)['pending'] === 0;
            })
            ->sortByDesc('submitted_at')
            ->values();

        /**
         * SUBMISSIONS WHERE SOME MANUAL QUESTION IS STILL UNDECIDED - INCLUDING BLANK ONES.
         *
         * The queue above lists ANSWERS that have something to read, because
         * `OnlineExamMarking::responses()` excludes blanks and a paper with forty
         * blank questions must not become forty unreadable rows.
         *
         * That left nowhere for a marker to discharge a BLANK manual question, and an
         * undecidable question is an un-completable paper: submission 12 sat as
         * `finalized` with an empty Actions column precisely because the 10-mark short
         * answer had no answer row and therefore nowhere to go.
         *
         * This panel is that somewhere. Each blank is shown with its question, its
         * marks, and a control that records an EXPLICIT ZERO - attributed to the
         * marker, audited, and refused above zero. So the student still gets a
         * truthful 0 for a blank answer, and the record shows a person made that
         * decision rather than the arithmetic quietly omitting the question.
         */
        $awaitingDecision = OnlineExamSubmission::query()
            ->where('school_id', $this->school_id)
            ->whereIn('status', [
                OnlineExamSubmission::STATUS_SUBMITTED,
                OnlineExamSubmission::STATUS_TIMED_OUT,
                OnlineExamSubmission::STATUS_PENDING_MANUAL,
                // The anomalous state, so a marker can see WHY it needs repair.
                OnlineExamSubmission::STATUS_FINALIZED,
            ])
            ->with(['student', 'exam', 'answerRows'])
            ->when(
                Schema::hasTable('course_offerings'),
                fn ($q) => $q->with(['exam.courseOffering'])
            )
            ->whereHas('exam', function ($examQ) use ($canEditAll, $assignedClassIds, $user) {
                $this->applyTeacherOwnershipScope($examQ, $user, $assignedClassIds, $canEditAll);
            })
            ->get()
            ->filter(fn (OnlineExamSubmission $s) => \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($s))
            ->sortByDesc('submitted_at')
            ->values();

        /**
         * BACKLOG SEPARATED FROM NEW WORK — REQ 27.
         *
         * The outstanding-decisions panel is sorted newest-first, which sounds like an
         * answer and is not: a marker opening the queue during a normal week saw the
         * same list every day, with three papers from a previous term indistinguishable
         * from the paper handed in an hour ago. Nothing merged and nothing was deleted —
         * both are explicitly forbidden, and both are honoured here — but a list a
         * marker cannot triage is a list they will not start, and un-marked papers are
         * the reason students have no results.
         *
         * So the SAME rows are presented in two groups, split by age, with the boundary
         * chosen as a named constant rather than a magic number in a view:
         *
         *   recent  — submitted within the window: today's work
         *   backlog — older than that: carried over, and labelled as such
         *
         * Both are complete, both are actionable, and each row states its SUBMISSION ID
         * so a marker can name it to the academic office and find the exact attempt —
         * separate attempts are never combined, and the submission number is what makes
         * "which one is this?" answerable.
         */
        $backlogCutoff = now()->subDays(self::MARKING_BACKLOG_DAYS);

        $awaitingDecisionRecent = $awaitingDecision->filter(
            fn (OnlineExamSubmission $s) => optional($s->submitted_at)?->gte($backlogCutoff) !== false
        )->values();

        $awaitingDecisionBacklog = $awaitingDecision->filter(
            fn (OnlineExamSubmission $s) => optional($s->submitted_at)?->gte($backlogCutoff) === false
        )->values();

        return view('teacher.online_exam.marking', compact(
            'answers', 'status', 'readyForHandover',
            'awaitingDecision', 'awaitingDecisionRecent', 'awaitingDecisionBacklog',
        ));
    }

    /**
     * How old a submission must be before the queue calls it a backlog.
     *
     * Named rather than inlined so the view's heading and the query's split can never
     * disagree about what "recent" means.
     */
    public const MARKING_BACKLOG_DAYS = 7;

    /**
     * A LECTURER'S MARK IS REFUSED WITH AN EXPLANATION, NOT AN ERROR DOCUMENT.
     *
     * `markSubmissionAnswer()` rejects a mark outside the question's bounds, and a
     * mark above zero for a question with no recorded answer, by aborting. On its own
     * that surfaces as a bare 422 page — the lecturer is told nothing about which
     * question was wrong or why, and lands away from the work.
     *
     * The rules themselves are NOT loosened: they still throw, and nothing is written,
     * so the transaction rolls back and the submission keeps its existing state. Only
     * the presentation changes, at this entry point, so a lecturer is returned to
     * where they were with the reason. Scoped to the lecturer route deliberately: the
     * administrator marking screens keep their existing behaviour.
     */
    public function teacherMarkAnswer(ManualMarkAnswerRequest $request, OnlineExamAnswer $answer)
    {
        try {
            $this->markSubmissionAnswer($answer, $request->validated());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            return redirect()->back()->withErrors([
                'awarded_marks' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : get_phrase('That mark was not accepted. Your other work has been saved.'),
            ]);
        }

        return redirect()->back()->with('success', get_phrase('Answer marked.'));
    }

    /**
     * LECTURER HANDS COMPLETED MARKING TO AN ADMINISTRATOR.
     *
     * ── WHY THIS CATCHES 422 INSTEAD OF SHOWING ONE ─────────────────────────
     *
     * `finalizeSubmission()` refuses an incomplete paper with a 422 and a sentence
     * naming what is outstanding. That refusal is CORRECT and is unchanged.
     *
     * What was wrong was the delivery. A lecturer who had marked everything they
     * believed they had to mark pressed "Submit Marks for Admin Review" and received
     * a bare `422 Unprocessable Content` error page — no explanation, no way back to
     * the screen they came from, and no indication of WHICH question was still
     * undecided. That is the defect the brief records, and it recurred on both the
     * lecturer's handover and the administrator's own finalize action.
     *
     * So the refusal is caught and returned to the relevant screen WITH the message
     * the server already wrote. Nothing is weakened: the transaction still aborts, the
     * status is untouched, and the undecided questions remain undecided.
     *
     * `publishResult()` already does this for publication blockers — see the note on
     * `OnlineExamPublication::blockers()` — and this makes the two workflow steps
     * behave the same way.
     */
    public function teacherFinalizeResult(OnlineExamSubmission $submission)
    {
        try {
            $this->finalizeSubmission($submission, 'teacher');
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            return redirect()->back()->withErrors([
                'result' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : get_phrase('This result cannot be handed over yet.'),
            ]);
        }

        $submission->load('exam');
        OnlineExamPortalNotifier::admins('marking_submitted_for_review', 'Marking Awaiting Review', Auth::user()->name . ' submitted marking for "' . $submission->exam->title . '".', $submission->exam, Auth::id(), 'marking-review:' . $submission->id, $submission->id);

        return redirect()->back()->with('success', get_phrase('Marking submitted for Admin review.'));
    }

    // Student: take exam.
    public function studentExams(Request $request)
    {
        $this->authorize('viewAny', OnlineExam::class);

        $student_id = Auth::id();
        $school_id  = Auth::user()->school_id;

        // ONE eligibility rule, asked three times for three different questions:
        // what may I sit now, what have I already sat, and which of those may I
        // still open. It used to be written out three times in this one method and
        // a fourth time in findStudentExamOrFail() - five statements of one rule,
        // each answering a slightly different question about the same student, any
        // two of which could drift. The rule is now stated once, on
        // CourseOfferingExamAccess, and asked here.
        //
        // A student can have more than one current academic relationship, so the
        // service considers every enrolment rather than the first - that behaviour
        // is preserved exactly.
        $eligibility = app(CourseOfferingExamAccess::class);

        $eligibleExams = $eligibility
            ->applyStudentVisibility(
                OnlineExam::forSchool($school_id)->published()->with(['subject', 'questions']),
                (int) $student_id,
                (int) $school_id
            )
            ->get();

        // History is driven by the student's own submissions, not the exam's
        // current date window. Ended and released attempts remain visible,
        // while school and current class eligibility remain enforced.
        $submissions = OnlineExamSubmission::forSchool($school_id)
            ->forStudent($student_id)
            ->with(['exam.subject', 'exam.questions', 'answerRows'])
            ->orderByDesc('attempt_no')
            ->get()
            ->filter(fn ($submission) => $submission->exam
                && $eligibility->studentMaySeeExam(Auth::user(), $submission->exam));

        $latestByExam = $submissions->groupBy('online_exam_id')->map->first();
        $attemptCounts = $submissions->groupBy('online_exam_id')->map->count();
        $historyExamIds = $latestByExam->keys()->all();
        $historyExams = $historyExamIds
            ? $eligibility
                ->applyStudentVisibility(
                    OnlineExam::forSchool($school_id)->whereIn('id', $historyExamIds)->with(['subject', 'questions']),
                    (int) $student_id,
                    (int) $school_id
                )
                ->get()
            : collect();

        $attach = function ($exam) use ($latestByExam, $attemptCounts) {
            $exam->submission = $latestByExam->get($exam->id);
            $exam->attempts_used = (int) ($attemptCounts->get($exam->id) ?? 0);
            return $exam;
        };

        $historyExams = $historyExams->map($attach)->keyBy('id');
        $availableExams = $eligibleExams
            ->map($attach)
            ->reject(fn ($exam) => $exam->submission !== null)
            ->values();

        foreach ($eligibleExams as $exam) {
            if ($exam->submission) {
                $historyExams->put($exam->id, $exam);
            }
        }

        return view('student.online_exam.list', [
            'availableExams' => $availableExams,
            'attemptedExams' => $historyExams->values(),
            // Retain the legacy variable for extensions that still consume it.
            'exams' => $availableExams->concat($historyExams->values())->unique('id')->values(),
        ]);
    }
    /**
     * The pre-exam screen: rules, attempt count, and (when the exam
     * requires it) the fullscreen/webcam readiness steps that
     * StartOnlineExamRequest will insist on before start() succeeds.
     *
     * Used to be a bare JSON endpoint with no page consuming it — a student
     * clicking "Start Exam" from the list would end up here via a normal
     * browser redirect (see takeExam()) and land on raw JSON with no way
     * to actually proceed. Nothing else in the app called this endpoint
     * expecting JSON (grepped for it before changing), so switching it to
     * a real page is safe.
     */
    public function instructions($id)
    {
        $exam = $this->findStudentExamOrFail((int) $id);
        $this->authorize('sit', $exam);

        $activeSubmission = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('student_id', Auth::id())
            ->where('school_id', $this->school_id)
            ->where('status', OnlineExamSubmission::STATUS_IN_PROGRESS)
            ->first();

        // Already mid-attempt (e.g. came back after a disconnect) — no need
        // to see the rules again, resume straight into the exam.
        if ($activeSubmission) {
            return redirect()->route('student.online_exam.take', $exam->id);
        }

        $attemptsUsed = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('student_id', Auth::id())
            ->count();

        return view('student.online_exam.instructions', [
            'exam' => $exam,
            'attemptsUsed' => $attemptsUsed,
            'questionCount' => OnlineExamQuestion::forExam($exam->id)->count(),
            'withinWindow' => $this->withinExamWindow($exam),
        ]);
    }

    /**
     * Every student sits an exam within the same scheduled [start_datetime,
     * end_datetime] window — nobody can start early, and starting requires
     * enough of the window left to actually attempt it. Checked both here
     * (so the instructions page can explain *why* Start is unavailable) and
     * again in start() (the actual gate — instructions is just the message).
     */
    private function withinExamWindow(OnlineExam $exam): bool
    {
        return $exam->isWithinScheduledWindow();
    }

    public function readiness(CameraReadinessRequest $request, $submissionId)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('view', $submission);

        DB::transaction(function () use ($request, $submission) {
            $submission->update([
                'camera_consent_at' => $request->boolean('consent_accepted') ? now() : $submission->camera_consent_at,
                'camera_permission_granted' => $request->boolean('permission_granted'),
                'camera_ready_at' => $request->boolean('camera_ready') ? now() : $submission->camera_ready_at,
                'last_activity_at' => now(),
            ]);
        });

        return response()->json(['status' => 'success', 'server_time' => now()->toDateTimeString()]);
    }

    public function start(StartOnlineExamRequest $request, $id)
    {
        $exam = $this->findStudentExamOrFail((int) $id);
        $this->authorize('sit', $exam);

        $submission = DB::transaction(function () use ($request, $exam) {
            $lockedExam = OnlineExam::whereKey($exam->id)->lockForUpdate()->firstOrFail();

            if ($lockedExam->workflow_state !== 'published') {
                abort(422, 'Exam is not published.');
            }

            // The scheduled [start_datetime, end_datetime] window is already
            // enforced by StartOnlineExamRequest::withValidator() before this
            // method body ever runs — see withinExamWindow(), used here only
            // by instructions() to explain *why* Start is unavailable ahead
            // of the click.
            $studentId = Auth::id();
            $active = OnlineExamSubmission::where('online_exam_id', $lockedExam->id)
                ->where('student_id', $studentId)
                ->where('school_id', $this->school_id)
                ->where('status', OnlineExamSubmission::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->first();

            if ($active) {
                abort(422, 'An active attempt already exists.');
            }

            $attemptNo = ((int) OnlineExamSubmission::where('online_exam_id', $lockedExam->id)
                ->where('student_id', $studentId)
                ->lockForUpdate()
                ->max('attempt_no')) + 1;

            if ($attemptNo > (int) $lockedExam->max_attempts) {
                abort(422, 'Maximum attempts exceeded.');
            }

            $startedAt = now();

            /**
             * THE DEADLINE IS THE EARLIER OF THE TWO — COMPARED AS INSTANTS.
             *
             * This used to ask `$durationExpiry->gt($scheduledEnd)`, and that comparison
             * is not reliable here: `started_at` is in the application's zone while
             * `scheduledEndAt()` converts the exam's window from its own
             * `schedule_timezone`, so the two Carbon objects can disagree about
             * ordering even when their wall-clock times clearly do not.
             *
             * Measured on exam 19 with a 45-minute duration and a window closing at
             * 04:58: starting at 03:08 gives a duration deadline of 03:53, and the
             * comparison nonetheless returned true and handed the student until 04:58 —
             * 110 minutes on a 45-minute paper. That is the 223-minute bug arriving by
             * a second route, and it would still have reached every NEW attempt.
             *
             * Timestamps have no timezone ambiguity: each value is reduced to one
             * absolute instant, compared as an integer, and only the winner is turned
             * back into a Carbon.
             */
            $deadlineTimestamp = $startedAt->getTimestamp() + ((int) $lockedExam->duration_mins * 60);
            $scheduledEnd = $lockedExam->scheduledEndAt();

            if ($scheduledEnd && $scheduledEnd->getTimestamp() < $deadlineTimestamp) {
                $deadlineTimestamp = $scheduledEnd->getTimestamp();
            }

            $expiresAt = Carbon::createFromTimestamp($deadlineTimestamp, $startedAt->getTimezone());

            $submission = OnlineExamSubmission::create([
                'online_exam_id' => $lockedExam->id,
                'student_id' => $studentId,
                'school_id' => $this->school_id,
                'attempt_no' => $attemptNo,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'last_activity_at' => $startedAt,
                'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
                'total_marks_snapshot' => (int) $lockedExam->total_marks,
                'browser_session_token' => Str::uuid()->toString(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'camera_consent_at' => $request->boolean('camera_consent_accepted') ? now() : null,
                'camera_permission_granted' => $request->boolean('camera_ready'),
                'camera_ready_at' => $request->boolean('camera_ready') ? now() : null,
                'fullscreen_started_at' => $request->boolean('fullscreen_ready') ? now() : null,
            ]);

            return $submission;
        });

        AuditLog::record('create', 'Online Exams', "Started attempt #{$submission->id} for exam #{$exam->id}");

        return response()->json([
            'status' => 'success',
            'submission_id' => $submission->id,
            'attempt_no' => $submission->attempt_no,
            'expires_at' => optional($submission->effectiveExpiresAt())->toDateTimeString(),
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    public function resume($submissionId)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('view', $submission);

        if ($submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
            return response()->json(['status' => 'error', 'message' => 'Attempt is not active.'], 422);
        }

        if ($submission->isExpired()) {
            try {
                $this->submitBySubmission($submission, 'timeout');
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => get_phrase('We could not submit your exam because one saved answer could not be validated. Your saved answers have been preserved. Please contact the examiner or administrator if the problem continues.'),
                ], 422);
            }
            return response()->json(['status' => 'error', 'message' => 'Attempt expired and was finalized.'], 422);
        }

        AuditLog::record('update', 'Online Exams', "Resumed attempt #{$submission->id}");

        return response()->json([
            'status' => 'success',
            'submission_id' => $submission->id,
            'expires_at' => optional($submission->effectiveExpiresAt())->toDateTimeString(),
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    public function takeExam($id)
    {
        $exam = $this->findStudentExamOrFail((int) $id);
        $this->authorize('sit', $exam);

        $submission = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('student_id', Auth::id())
            ->where('school_id', $this->school_id)
            ->orderByDesc('attempt_no')
            ->first();

        if (!$submission || $submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
            return redirect()->route('student.online_exam.instructions', $exam->id);
        }

        if ($submission->isExpired()) {
            // timeout-submit is a POST-only route; redirect() always issues a
            // GET, so redirecting a browser at it (the previous behaviour)
            // produced a 405 instead of actually finalising the attempt.
            // Calling the same finalisation logic directly does what the
            // redirect was trying to and returns its result (a redirect to
            // the results page) straight through.
            return $this->timeoutSubmit($submission->id);
        }

        $questions = OnlineExamQuestion::forExam($exam->id)
            ->ordered()
            ->get()
            ->map(function (OnlineExamQuestion $q) {
                $q->correct_ans = null;
                return $q;
            });

        $questions = $this->orderQuestionsForAttempt($questions, $exam, $submission);

        // Sanitize the model before it reaches the student view. Structured
        // marking rules and legacy answer keys are server-only data.
        $questions->each(function (OnlineExamQuestion $question) use ($exam, $submission) {
            $public = \App\Support\OnlineExams\QuestionContract::publicProjection($question);
            if ($exam->shuffle_options && count($public['options'] ?? []) > 1) {
                usort($public['options'], fn ($a, $b) => crc32($submission->id . '-o-' . $question->id . '-' . $a['id']) <=> crc32($submission->id . '-o-' . $question->id . '-' . $b['id']));
            }
            // Matching choices are always deterministically reordered: leaving
            // them in authoring/pair order could reveal the answer even when
            // ordinary MCQ option shuffling is disabled.
            if (count($public['right_items'] ?? []) > 1) {
                usort($public['right_items'], fn ($a, $b) => crc32($submission->id . '-r-' . $question->id . '-' . $a['id']) <=> crc32($submission->id . '-r-' . $question->id . '-' . $b['id']));
            }
            if (count($public['items'] ?? []) > 1) {
                usort($public['items'], fn ($a, $b) => crc32($submission->id . '-i-' . $question->id . '-' . $a['id']) <=> crc32($submission->id . '-i-' . $question->id . '-' . $b['id']));
                $correctOrder = array_values((QuestionContract::normalize($question, true)['marking']['correct_order'] ?? []));
                $presentedOrder = array_column($public['items'], 'id');
                if ($correctOrder === $presentedOrder) {
                    [$public['items'][0], $public['items'][1]] = [$public['items'][1], $public['items'][0]];
                }
            }
            $question->setAttribute('public_question', $public);
            $question->makeHidden(['correct_ans', 'marking_config']);
            $question->setAttribute('correct_ans', null);
        });

        $existingAnswers = OnlineExamAnswer::where('submission_id', $submission->id)
            ->get()
            ->keyBy('question_id');

        $serverAnswers = $existingAnswers->mapWithKeys(function (OnlineExamAnswer $answer) {
            return [$answer->question_id => [
                'selected_option' => $answer->selected_option,
                'answer_text' => $answer->answer_text,
                'answer_payload' => $answer->answer_schema_version !== null
                    ? \App\Support\OnlineExams\QuestionContract::decode($answer->answer_payload, 'answer_payload') : null,
                'answer_revision' => $answer->answer_revision,
                'updated_at' => optional($answer->updated_at)->toIso8601String(),
            ]];
        })->all();

        $optionOrders = $questions->mapWithKeys(function (OnlineExamQuestion $q) use ($exam, $submission) {
            return [$q->id => $this->orderOptionsForAttempt($q, $exam, $submission)];
        });

        return view('student.online_exam.take', [
            'exam' => $exam,
            'submission' => $submission,
            'questions' => $questions,
            'existingAnswers' => $existingAnswers,
            'serverAnswers' => $serverAnswers,
            'optionOrders' => $optionOrders,
            'remainingSeconds' => $submission->remainingSeconds(),
            'expiresAt' => optional($submission->effectiveExpiresAt())->toIso8601String(),
            'serverTime' => now()->toIso8601String(),
            // Resolved once, on the server, from the deployment's integrity policy and
            // this exam's approved accommodation. The page and the restricted-mode
            // script read the SAME array, so a control cannot be shown as active while
            // the script believes it is exempt. See OnlineExam::integritySettings().
            'integritySettings' => $exam->integritySettings(),
        ]);
    }

    /**
     * Question order for one attempt. Deliberately not a random shuffle on
     * every page load (a student refreshing mid-attempt would see the
     * questions rearrange, losing their place) — ordering by a hash of
     * (submission, question) is pseudo-random per attempt but perfectly
     * stable across reloads and resumes of that same attempt.
     */
    private function orderQuestionsForAttempt($questions, OnlineExam $exam, OnlineExamSubmission $submission)
    {
        if (!$exam->shuffle_questions) {
            return $questions;
        }

        return $questions
            ->sortBy(fn (OnlineExamQuestion $q) => crc32($submission->id . '-q-' . $q->id))
            ->values();
    }

    /**
     * Display order for one question's options, keyed by the real option
     * letter (a/b/c/d) so the submitted value is always the true option
     * regardless of the order it was shown in — shuffling is purely a
     * display concern, never touches how an answer is stored or graded.
     * Same stability rationale as orderQuestionsForAttempt().
     */
    private function orderOptionsForAttempt(OnlineExamQuestion $question, OnlineExam $exam, OnlineExamSubmission $submission): array
    {
        $options = [];
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $text = $question->{'option_' . $key};
            if ($text !== null && $text !== '') {
                $options[$key] = $text;
            }
        }

        if (!$exam->shuffle_options || count($options) < 2) {
            return $options;
        }

        $keys = array_keys($options);
        usort($keys, fn ($a, $b) => crc32($submission->id . '-o-' . $question->id . '-' . $a) <=> crc32($submission->id . '-o-' . $question->id . '-' . $b));

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $options[$key];
        }

        return $ordered;
    }

    public function saveAnswer(SaveOnlineExamAnswerRequest $request, $submissionId)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('view', $submission);

        $validated = $request->validated();
        $responseData = DB::transaction(function () use ($submission, $validated) {
            $lockedSubmission = OnlineExamSubmission::whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless((int) $lockedSubmission->student_id === (int) Auth::id()
                && (int) $lockedSubmission->school_id === (int) $this->school_id
                && $lockedSubmission->exam
                && (int) $lockedSubmission->exam->school_id === (int) $this->school_id, 403);

            if ($lockedSubmission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
                abort(422, 'Submission is not active.');
            }

            if ($lockedSubmission->isExpired()) {
                abort(422, 'Submission has expired.');
            }

            $question = OnlineExamQuestion::where('id', (int) $validated['question_id'])
                ->where('online_exam_id', $lockedSubmission->online_exam_id)
                ->first();

            if (!$question) {
                abort(422, 'Question does not belong to this exam.');
            }

            // The parent lock serializes saves even when the answer does not yet exist.
            // Finalization takes the same lock; keep all checks and writes inside it.
            $answer = OnlineExamAnswer::where('submission_id', $lockedSubmission->id)
                ->where('question_id', $question->id)->lockForUpdate()->first();
            $revision = (int) $validated['answer_revision'];
            $payload = [
                'selected_option' => $validated['selected_option'] ?? null,
                'answer_text' => $validated['answer_text'] ?? null,
            ];
            if (array_key_exists('answer_payload', $validated) && $validated['answer_payload'] !== null && $validated['answer_payload'] !== '') {
                $canonicalAnswer = \App\Support\OnlineExams\AnswerContract::fromRequest($validated, $question);
                $payload['answer_schema_version'] = \App\Support\OnlineExams\AnswerContract::STRUCTURED_VERSION;
                $payload['answer_payload'] = \App\Support\OnlineExams\AnswerContract::encode($canonicalAnswer);
            } elseif (array_key_exists('answer_payload', $validated)) {
                $payload['answer_schema_version'] = null;
                $payload['answer_payload'] = null;
            }
            $status = 'success';
            if ($answer && $revision <= $answer->answer_revision) {
                $identical = $answer->selected_option === $payload['selected_option']
                    && $answer->answer_text === $payload['answer_text']
                    && ($answer->answer_payload ?? null) === ($payload['answer_payload'] ?? null);
                if ($revision < $answer->answer_revision || !$identical) {
                    return [
                        'status' => $revision < $answer->answer_revision ? 'stale' : 'conflict',
                        'submission_id' => $lockedSubmission->id,
                        'question_id' => $question->id,
                        'answer_revision' => $answer->answer_revision,
                        'selected_option' => $answer->selected_option,
                        'answer_text' => $answer->answer_text,
                    ];
                }
                $status = 'idempotent';
            } else {
                $answer = $answer ?: new OnlineExamAnswer([
                    'submission_id' => $lockedSubmission->id,
                    'question_id' => $question->id,
                ]);
                $answer->fill($payload + ['answer_revision' => $revision])->save();
            }

            $lockedSubmission->update([
                'last_activity_at' => now(),
            ]);

            return [
                'status' => $status,
                'submission_id' => $lockedSubmission->id,
                'question_id' => $question->id,
                'answer_revision' => $answer->answer_revision,
                'answer_updated_at' => optional($answer->fresh()->updated_at)->toIso8601String(),
                'expires_at' => optional($lockedSubmission->effectiveExpiresAt())->toDateTimeString(),
                'server_time' => now()->toDateTimeString(),
            ];
        });

        return response()->json($responseData, in_array($responseData['status'], ['stale', 'conflict'], true) ? 409 : 200);
    }

    /**
     * RECORD ONE RESTRICTED-MODE INCIDENT AGAINST THE STUDENT'S OWN ATTEMPT.
 *
 * ── WHY THIS DOES NOTHING ELSE ────────────────────────────────────────────
 *
 * It stores an event and returns. It does not submit the attempt, does not penalise,
 * and does not disqualify. An automated consequence for leaving the tab would punish
 * a dropped connection, a phone call or an operating-system notification, and the
 * institution has set no policy for that. The record is evidence for a human to act on
 * deliberately; treating it as an automatic verdict is exactly the failure mode a
 * proctoring log is supposed to avoid.
 *
 * ── WHY DEDUPLICATION IS THE SERVER'S JOB, NOT ONLY THE SCRIPT'S ──────────
 *
 * One Alt+Tab produces a `blur` and a `visibilitychange`. The page absorbs that pair,
     * but the server also refuses a repeated event of the same type inside a short
     * window, so a browser that reports the pair anyway — or a student who reloads
     * mid-interruption — cannot inflate the count on their own record.
 *
 * Ownership is enforced through the same helper every other student action uses, so a
     student can only ever write an incident against a submission of their own.
 */
public function recordExamIncident(Request $request, $submissionId)
{
    $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
    $this->authorize('view', $submission);

    $validated = $request->validate([
        'event_type' => ['required', 'string', Rule::in(OnlineExamProctoringEvent::EVENT_TYPES)],
        'event_key' => ['nullable', 'string', 'max:191'],
        'detail' => ['nullable'],
        'client_at' => ['nullable', 'date'],
    ]);

    $type = $validated['event_type'];

    // An identical report for the same attempt inside this window is the same
    // interruption reported twice, not two interruptions.
    $duplicateWindowSeconds = 3;

    $recentDuplicate = OnlineExamProctoringEvent::query()
        ->where('submission_id', $submission->id)
        ->where('event_type', $type)
        ->where('event_time', '>=', now()->subSeconds($duplicateWindowSeconds))
        ->exists();

    if ($recentDuplicate) {
        return response()->json([
            'recorded' => false,
            'reason' => 'duplicate',
            'submission_id' => $submission->id,
        ]);
    }

    $event = OnlineExamProctoringEvent::create([
        'submission_id' => $submission->id,
        'event_type' => $type,
        'event_time' => now(),
        'metadata' => [
            'event_key' => $validated['event_key'] ?? null,
            'client_at' => $validated['client_at'] ?? null,
            'detail' => $validated['detail'] ?? null,
            // Recorded so a reviewer can tell a page-reported incident from one
            // captured by any other means.
            'source' => 'restricted_mode',
        ],
    ]);

    return response()->json([
        'recorded' => true,
        'event_id' => $event->id,
        'submission_id' => $submission->id,
        'event_type' => $type,
    ]);
}

public function heartbeat($submissionId)
{
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('view', $submission);

        if ($submission->status === OnlineExamSubmission::STATUS_IN_PROGRESS && $submission->isExpired()) {
            try {
                $this->submitBySubmission($submission, 'timeout');
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => get_phrase('We could not submit your exam because one saved answer could not be validated. Your saved answers have been preserved. Please contact the examiner or administrator if the problem continues.'),
                ], 422);
            }
            $submission = $submission->fresh();
            return response()->json([
                'status' => 'expired',
                'server_time' => now()->toDateTimeString(),
                'expires_at' => optional($submission->effectiveExpiresAt())->toDateTimeString(),
                'expired' => true,
                'submission_status' => $submission->status,
            ]);
        }

        /**
         * PERSIST ANY SHORTENED DEADLINE BEFORE ANSWERING THE HEARTBEAT.
         *
         * `effectiveExpiresAt()` recomputes the deadline from the exam's CURRENT
         * duration and closing time, and `remainingSeconds()` already uses it — so the
         * server would enforce the right number. Persisting it here is what stops the
         * browser and the server disagreeing: without this, the page could show one
         * figure while the timeout that finally fires used another, and the student
         * would have been misled about their own remaining time.
         *
         * It only ever writes when the recomputed deadline is EARLIER, so no heartbeat
         * can hand a student extra time.
         */
        $submission->clampExpiresAtToEffectiveDeadline();

        $submission->update(['last_activity_at' => now()]);

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toDateTimeString(),
            'expires_at' => optional($submission->effectiveExpiresAt())->toDateTimeString(),
            'expired' => $submission->isExpired(),
        ]);
    }

    public function proctoringEvent(ProctoringEventRequest $request, $submissionId)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('view', $submission);

        $validated = $request->validated();
        $event = OnlineExamProctoringEvent::create([
            'submission_id' => $submission->id,
            'event_type' => $validated['event_type'],
            'event_time' => $validated['event_time'] ?? now(),
            'metadata' => $validated['metadata'] ?? null,
        ]);

        if (in_array($event->event_type, ['tab_hidden', 'fullscreen_exited', 'camera_stopped'], true)) {
            AuditLog::record('update', 'Online Exams', "Proctoring event {$event->event_type} on submission #{$submission->id}");
        }

        return response()->json([
            'status' => 'success',
            'event_id' => $event->id,
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    public function submitExam(SubmitOnlineExamRequest $request, $id)
    {
        $exam = $this->findStudentExamOrFail((int) $id);
        $submission = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('student_id', Auth::id())
            ->where('school_id', $this->school_id)
            ->whereKey((int) $request->input('submission_id'))
            ->firstOrFail();

        if ($submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
            return $submission->isResultVisible()
                ? redirect()->route('student.online_exam.result', $submission->id)
                : redirect()->back()->withErrors(['submission' => get_phrase('This submission has already been received and is awaiting marking or release.')]);
        }

        try {
            return $this->submitBySubmission($submission, 'manual');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withErrors([
                'submission' => get_phrase('We could not submit your exam because one saved answer could not be validated. Your saved answers have been preserved. Please contact the examiner or administrator if the problem continues.'),
            ]);
        }
    }

    public function timeoutSubmit($submissionId)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submissionId);
        $this->authorize('submit', $submission);

        if ($submission->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
            return $submission->isResultVisible()
                ? redirect()->route('student.online_exam.result', $submission->id)
                : redirect()->back()->withErrors(['submission' => get_phrase('This attempt has already been finalized.')]);
        }

        try {
            return $this->submitBySubmission($submission, 'timeout');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withErrors([
                'submission' => get_phrase('We could not submit your exam because one saved answer could not be validated. Your saved answers have been preserved. Please contact the examiner or administrator if the problem continues.'),
            ]);
        }
    }

    public function examResult($submission_id)
    {
        $submission = $this->findStudentSubmissionOrFail((int) $submission_id);
        // A student owns the submission even while its result is withheld.
        // Keep ownership protection, but render a state-aware status page
        // instead of treating the normal pending-release state as forbidden.
        $this->authorize('view', $submission);

        if ($submission->submitted_at && !$submission->isResultVisible()) {
            return view('student.online_exam.submitted', [
                'submission' => $submission->load('exam'),
                'statusMessage' => $this->studentResultStatusMessage($submission),
            ]);
        }

        $this->authorize('viewResult', $submission);

        $exam       = $submission->exam;
        $this->authorize('viewResult', $exam);

        $questions  = OnlineExamQuestion::forExam($exam->id)
            ->ordered()
            ->get()
            ->map(function (OnlineExamQuestion $q) {
                $q->correct_ans = null;
                return $q;
            });

        /**
         * THE MARKING BREAKDOWN, REACHABLE ONLY ONCE THE RESULT IS PUBLISHED.
         *
         * A published total with no breakdown behind it is not reviewable by the only
         * person who cannot appeal it. A student scoring 19 of 20 had no way to see
         * which question the marker took a mark off, what they had written, or what the
         * comment said — so the feedback a lecturer wrote was collected and never read.
         *
         * Loaded here, AFTER `authorize('viewResult')`, so it is not reachable on the
         * pending-release page at all: the earlier branch returns a status message and
         * this code never runs. That ordering is the privacy property, and it is why the
         * answers are not passed to the withheld view.
         */
        $answerRows = $submission->answerRows()->with('question')->get()->keyBy('question_id');

        return view('student.online_exam.result', compact('submission', 'exam', 'questions', 'answerRows'));
    }

    /**
     * WHAT A STUDENT IS TOLD ABOUT A RESULT THEY MAY NOT YET SEE.
     *
     * ── WHY THIS IS NOT LEFT TO THE VIEW ───────────────────────────────────
     *
     * The status page branched on `status === 'finalized'` and said "Your result is
     * finalized and awaiting publication." Exam 17 submission 12 showed exactly that
     * while its state was `finalized` + `not_ready` — a combination that means the
     * marking was never done and never handed to anybody. Telling a student their
     * result was finalized would be a false statement about work no human had
     * performed, and the old copy also implied a stage had been reached that had not.
     *
     * So the wording is derived from the real review state, here, where the
     * transitions are visible, rather than in a Blade template.
     *
     * ── WHAT A STUDENT IS NEVER TOLD ───────────────────────────────────────
     *
     * Nothing here reveals a score, a mark, a marker's feedback, or the fact that the
     * record needed administrative repair at all. The anomalous state maps to the same
     * neutral "being processed" wording as ordinary pending marking, because from the
     * student's side the truth is simply that there is no result yet — and the repair
     * is an internal administrative matter they have no action on and no need to know
     * about. Revealing it would leak the existence of an internal defect and invite
     * appeals about a result that has not been decided.
     *
     * @return array{key: string, message: string}
     */
    private function studentResultStatusMessage(OnlineExamSubmission $submission): array
    {
        $reviewState = $submission->result_review_state ?: null;

        // Legacy rows predate the governance metadata. Treated as "handed over" for a
        // finalized row, matching how the admin and lecturer screens already read it.
        if ($reviewState === null
            && $submission->status === OnlineExamSubmission::STATUS_FINALIZED) {
            $reviewState = 'pending_review';
        }

        $processing = [
            'key' => 'processing',
            'message' => get_phrase('Your exam has been submitted successfully. Your result is being processed.'),
        ];

        /**
         * RETURNED FOR CORRECTION IS "BEING PROCESSED", NOT "AWAITING REVIEW".
         *
         * `returned_for_correction` means an administrator sent the marking back. The
         * result is being worked on again, so the earlier "awaiting administrative
         * review" wording would be untrue — the review already happened and its
         * outcome was "not yet".
         */
        if ($reviewState === 'returned_for_correction') {
            return $processing;
        }

        /**
         * `finalized` + `not_ready` IS THE ANOMALY, AND MUST NOT READ AS APPROVED.
         *
         * This pair is only producible by the old student-submit path: marking
         * declared complete and never handed to anybody. Branching on `status` alone
         * put it in the "awaiting official publication" bucket, which is precisely the
         * false claim this method exists to stop — it told a student their result was
         * finalized and about to be released when no human had marked it. So the
         * anomaly is checked explicitly and falls back to the neutral wording.
         */
        if ($submission->status === OnlineExamSubmission::STATUS_FINALIZED
            && $reviewState === 'not_ready') {
            return $processing;
        }

        // Marking complete and handed to the academic office. Not yet approved.
        if ($submission->status === OnlineExamSubmission::STATUS_FINALIZED
            && $reviewState === 'pending_review') {
            return [
                'key' => 'awaiting_review',
                'message' => get_phrase('Your marking is complete and awaiting administrative review.'),
            ];
        }

        /**
         * APPROVED BUT NOT YET RELEASED.
         *
         * The marking is finished and the only thing left is the formal release, which
         * is the one case where telling a student to expect their result is both true
         * and useful.
         */
        if ($reviewState === 'published' || $submission->status === OnlineExamSubmission::STATUS_FINALIZED) {
            return [
                'key' => 'awaiting_publication',
                'message' => get_phrase('Your result is awaiting official publication.'),
            ];
        }

        // Submitted and still with the lecturer, or awaiting a decision.
        return $processing;
    }

    // ── Submissions (admin view) ───────────────────────────────────────────

    public function submissions($exam_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('viewAttempts', $exam);

        $submissions = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with(['student', 'exam.questions', 'answerRows'])
            ->withCount('proctoringEvents')
            ->orderByDesc('submitted_at')
            ->paginate(30);

        return view('admin.online_exam.submissions', compact('exam', 'submissions'));
    }

    /**
     * GET /admin/online-exams-results-review
     *
     * EVERY completed submission waiting on an administrator, across the institution.
     *
     * ── WHY THIS PAGE HAD TO BE ADDED ────────────────────────────────────────
     *
     * Result review existed only as a per-exam screen: `admin.online_exams.results`.
     * A notification deep-linked straight into the right row of it, so a single
     * outstanding result was reachable - and a lecturer who submitted marking was
     * notified at all, only once that notification existed.
     *
     * But a QUEUE is a different thing from a destination. An administrator with
     * forty papers in flight has no way to ask "what is waiting for me?" and had to
     * know which exam to open first, and would have opened the wrong one and seen
     * nothing, which is indistinguishable from there being no work.
     *
     * So this is the answer to "what is waiting for me", stated once, for all
     * exams. It is a READ of the same persisted state the per-exam screen acts on -
     * `status = finalized` together with `result_review_state = pending_review` -
     * so the two can never disagree about what is outstanding.
     *
     * It does not create a second publication path. Every row links to the existing
     * per-exam results screen, and the only actions remain `publishResult()` and
     * `returnResultForCorrection()`, both of which refuse a non-administrator.
     *
     * Tenant-scoped like every other query in this controller, and Course-Offering
     * aware: a submission names the course it belongs to, because an administrator
     * approving a Business Mathematics result and one approving a Business
     * Accounting result are different decisions.
     */
    public function resultReviewQueue(Request $request)
    {
        abort_unless(app(OnlineExamPermissionService::class)->has(Auth::user(), 'mark_exam_answers')
            || app(OnlineExamPermissionService::class)->has(Auth::user(), 'manage_exam_results'), 403);

        $status = trim((string) $request->input('status', 'pending_review'));

        $query = OnlineExamSubmission::query()
            ->where('school_id', $this->school_id)
            ->with(['student', 'exam.courseOffering'])
            ->whereNotNull('submitted_at');

        // A submission is OUTSTANDING when its marking is complete and it is
        // waiting on a decision. Both halves matter: `finalized` alone would also
        // match results an administrator already published, and
        // `pending_review` alone would match work a lecturer has not finished.
        if ($status === 'published') {
            $query->where('status', OnlineExamSubmission::STATUS_RESULT_PUBLISHED);
        } elseif ($status === 'returned') {
            $query->where('result_review_state', 'returned_for_correction');
        } else {
            $query->where('status', OnlineExamSubmission::STATUS_FINALIZED)
                ->where('result_review_state', 'pending_review');
        }

        $submissions = $query->orderBy('submitted_at')->paginate(30)->appends($request->all());

        $counts = [
            'pending_review' => OnlineExamSubmission::where('school_id', $this->school_id)
                ->where('status', OnlineExamSubmission::STATUS_FINALIZED)
                ->where('result_review_state', 'pending_review')
                ->count(),
            'returned_for_correction' => OnlineExamSubmission::where('school_id', $this->school_id)
                ->where('result_review_state', 'returned_for_correction')
                ->count(),
            'result_published' => OnlineExamSubmission::where('school_id', $this->school_id)
                ->where('status', OnlineExamSubmission::STATUS_RESULT_PUBLISHED)
                ->count(),
        ];

        return view('admin.online_exam.result_review', compact('submissions', 'status', 'counts'));
    }

    public function results(Request $request, $exam_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('markAnswers', $exam);

        $submissions = OnlineExamSubmission::where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with(['student', 'exam.questions', 'answerRows'])
            ->orderByDesc('submitted_at')
            ->paginate(30);

        $answersNeedingMarking = OnlineExamAnswer::query()
            ->with(['submission.student', 'question'])
            ->whereHas('submission', function ($q) use ($exam) {
                $q->where('online_exam_id', $exam->id)->where('school_id', $this->school_id);
            })
            ->whereHas('question', function ($q) {
                \App\Support\OnlineExams\OnlineExamMarking::manualQuestions($q);
            })
            ->whereHas('submission', fn ($q) => $q->whereIn('status', ['submitted', 'timed_out', 'pending_manual_marking']))
            ->where(fn ($q) => \App\Support\OnlineExams\OnlineExamMarking::responses($q))
            ->where(fn ($q) => \App\Support\OnlineExams\OnlineExamMarking::unmarked($q))
            ->get();

        $highlightSubmissionId = (int) $request->query('submission', 0);
        return view('admin.online_exam.results', compact('exam', 'submissions', 'answersNeedingMarking', 'highlightSubmissionId'));
    }

    public function reviewProctoring($exam_id, $submission_id)
    {
        $exam = $this->findExamOrFail((int) $exam_id);
        $this->authorize('reviewProctoring', $exam);

        $submission = OnlineExamSubmission::where('id', (int) $submission_id)
            ->where('online_exam_id', $exam->id)
            ->where('school_id', $this->school_id)
            ->with('student')
            ->firstOrFail();
        $this->authorize('view', $submission);

        $events = OnlineExamProctoringEvent::forSubmission($submission->id)
            ->chronological()
            ->paginate(100);

        return view('admin.online_exam.proctoring', compact('exam', 'submission', 'events'));
    }

    public function manualMarking(ManualMarkAnswerRequest $request, $answerId)
    {
        $answer = OnlineExamAnswer::findOrFail((int) $answerId);
        $this->markSubmissionAnswer($answer, $request->validated());
        return redirect()->back()->with('success', get_phrase('Answer marked'));
    }

    /**
     * RECORD A MARKING DECISION FOR ONE QUESTION OF ONE SUBMISSION.
     *
     * ── WHY THIS EXISTS AT ALL ────────────────────────────────────────────
     *
     * Exam 17 submission 12, question 39. The student's written answer was never
     * persisted, so that question has NO `online_exam_answers` row. Every marking
     * action in the app is keyed by ANSWER ID, so there was nothing for a lecturer to
     * act on: the queue rendered "Decide on the submissions page", the results page
     * offered "Submit Marks for Admin Review", and pressing it could only ever return
     *
     *     422 "Finalize is blocked until every question requiring manual marking has
     *          been decided - 1 still outstanding, worth 10 marks."
     *
     * That 422 is the system working correctly — the marking really was incomplete.
     * The bug was REACHABILITY: a lecturer could see that a decision was owed, had no
     * way to make it, and was offered a button that could only fail.
     *
     * ── WHY IT IS KEYED BY (SUBMISSION, QUESTION) AND NOT BY ANSWER ────────
     *
     * Because the whole problem is that the answer row may not exist. Keying on the
     * two things that always do exist — the submission and the question on the paper —
     * makes the decision possible whether or not a row was ever created. The row is
     * created here if needed, and it stays genuinely empty apart from the decision, so
     * "no student answer was recorded" is preserved as a fact about the record rather
     * than replaced by a mark.
     *
     * ── WHAT IT WILL NOT DO ───────────────────────────────────────────────
     *
     * It cannot award marks for an answer that does not exist: above zero is refused
     * when there is no response. It cannot touch an automatic question. It cannot
     * touch a submission that is finalized or published, and it cannot be reached by
     * anyone who cannot mark. Nothing is decided on the student's behalf — the lecturer
     * must press this, and the decision is stored against their name.
     */
    public function recordQuestionDecision(
        Request $request,
        OnlineExamSubmission $submission,
        OnlineExamQuestion $question
    ) {
        $this->authorize('grade', $submission);

        $validated = $request->validate([
            'awarded_marks' => ['required', 'numeric', 'min:0'],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $mark = (float) $validated['awarded_marks'];

        // Same tenant and same paper. Guards against a crafted id from another exam,
        // another school, or another tenant entirely.
        abort_unless((int) $question->online_exam_id === (int) $submission->online_exam_id, 404);
        $this->assertStaffSubmission($submission->loadMissing('exam'));

        // The submission must still be open for marking.
        abort_if(
            $submission->isFinalized() || $submission->isResultVisible(),
            422,
            'This result has already been finalized and can no longer be marked.'
        );
        abort_unless(
            in_array($submission->status, [
                OnlineExamSubmission::STATUS_SUBMITTED,
                OnlineExamSubmission::STATUS_TIMED_OUT,
                OnlineExamSubmission::STATUS_PENDING_MANUAL,
            ], true),
            422,
            'This submission is not open for marking.'
        );

        // Automatic marks are the engine's decision, not a marker's.
        abort_if(
            \App\Support\OnlineExams\OnlineExamMarking::isAutomatic($question),
            422,
            'This question is marked automatically and cannot be marked by hand.'
        );

        abort_if(
            $mark > (float) $question->marks,
            422,
            'The mark is higher than this question is worth.'
        );

        /**
         * REFUSED AS A VALIDATION MESSAGE, NOT A 422 ERROR PAGE.
         *
         * A lecturer who types a mark into a form deserves to be told why it was
         * refused and left where they were, not shown a bare error document. This is
         * checked up front, before anything is written, and returns to the page with
         * the explanation and the submission untouched.
         *
         * The rule itself is unchanged and still enforced in `markSubmissionAnswer()`:
         * a question with no recorded answer can only be marked zero, because a mark
         * above zero for an answer that does not exist would be fabricating a result.
         */
        $existingRow = OnlineExamAnswer::where('submission_id', $submission->id)
            ->where('question_id', $question->id)
            ->first();

        $hasResponse = \App\Support\OnlineExams\OnlineExamMarking::hasResponse(
            $existingRow ? OnlineExamAnswer::find($existingRow->id) : null
        );

        if (! $hasResponse && $mark > 0) {
            return redirect()
                ->route('teacher.online_exams.results', [
                    'exam' => $submission->online_exam_id,
                    'submission' => $submission->id,
                ])
                ->withErrors([
                    'awarded_marks' => get_phrase(
                        'No answer was submitted for this question, so it can only be marked 0. Recording any mark above zero would invent work that was never submitted.'
                    ),
                ]);
        }

        /**
         * THE ROW IS CREATED ONLY NOW, AND ONLY IF IT IS MISSING.
         *
         * It is written empty apart from the decision itself: no answer text, no
         * selected option. That is deliberate, because "the student submitted nothing
         * here" is a fact the record must keep — a decision about a blank question is
         * not an answer, and `responses()` must continue to exclude it from the queue
         * of work there is something to read.
         */
        $answer = DB::transaction(function () use ($submission, $question, $mark, $validated) {
            $row = OnlineExamAnswer::firstOrCreate(
                ['submission_id' => $submission->id, 'question_id' => $question->id],
                ['school_id' => $submission->school_id, 'answer_revision' => 0]
            );

            $this->markSubmissionAnswer($row, [
                // `markSubmissionAnswer()` re-checks that the answer belongs to this
                // submission's exam, and refuses an id that does not. Supplying it
                // keeps that second, independent check in force rather than bypassed —
                // the row was just created for this exact question, but the guard is
                // cheap and this is a write path.
                'answer_id' => $row->id,
                'awarded_marks' => $mark,
                'teacher_comment' => $validated['teacher_comment'] ?? null,
            ]);

            $locked = OnlineExamSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $this->recomputeSubmissionScore($locked);

            return $row;
        });

        AuditLog::record(
            'update',
            'Online Exams',
            "Recorded marking decision for submission #{$submission->id}, question #{$question->id}: {$mark} of {$question->marks}"
        );

        return redirect()
            ->route('teacher.online_exams.results', [
                'exam' => $submission->online_exam_id,
                'submission' => $submission->id,
            ])
            ->with('success', get_phrase('Marking decision recorded.'));
    }

    /** Administrator finalizes. Same refusal handling as the lecturer's handover. */
    public function finalizeResult($submissionId)
    {
        $submission = OnlineExamSubmission::findOrFail((int) $submissionId);

        try {
            $this->finalizeSubmission($submission, 'administrator');
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            return redirect()->back()->withErrors([
                'result' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : get_phrase('This result cannot be finalized yet.'),
            ]);
        }

        return redirect()->back()->with('success', get_phrase('Result finalized'));
    }

    /**
     * ADMINISTRATOR RELEASES AN OFFICIAL RESULT.
     *
     * ── WHY THIS NO LONGER ANSWERS AN ORDINARY REJECTION WITH A 422 ──────────
     *
     * Exam 17, submission 12. The submission was fully eligible: finalized, awaiting
     * review, every written question decided — question 39 by an explicit recorded zero.
     * The administrator pressed "Approve & Publish Result" and received a bare
     * `422 Unprocessable Content`.
     *
     * The cause was the exam's own release policy. `result_release_policy` is
     * `after_exam_end` and the paper closes the following day, so the window had not
     * opened. That rule is CORRECT and is enforced below exactly as before.
     *
     * What was wrong was that the refusal carried no explanation, so a legitimate
     * scheduling rule presented as a broken endpoint and the administrator had no way
     * to tell "wait" from "something is broken".
     *
     * So the decision now comes from ONE place — `OnlineExamPublication::blockers()` —
     * and when anything is outstanding the administrator is redirected back to the
     * review screen with a sentence naming each blocker and what would clear it. The
     * same definition is rendered on that screen before anyone clicks, so the screen
     * and the action can never disagree with each other.
     *
     * ── WHAT IS NOT RELAXED ────────────────────────────────────────────────
     *
     * Every condition enforced here was enforced before, in the same order and with the
     * same strictness: the result must be finalized, must be awaiting review, must
     * have no undecided written question, and the release window must be open.
     * Publication remains ADMINISTRATOR-ONLY, checked before anything else. The write
     * happens inside a transaction against a locked row and is re-read afterwards, so
     * two administrators pressing at once cannot both release, and a repeat press
     * cannot duplicate the notification or the mail.
     *
     * ── WHO RELEASED IT, AND WHEN ──────────────────────────────────────────
     *
     * `published_at` and `published_by` are now written. Publication was the one
     * governed transition that recorded no actor, which is exactly the step an
     * institution most often has to answer questions about.
     */
    public function publishResult(OnlineExamSubmission $submission)
    {
        abort_unless(
            Auth::user() && (int) Auth::user()->role_id === 2,
            403,
            'Only an administrator may publish official results.'
        );

        // Re-evaluated INSIDE the transaction against a locked row, so the guard and
        // the write cannot be separated by a concurrent change.
        //
        // The outcome is returned EXPLICITLY rather than inferred afterwards. An earlier
        // version decided "was this a first publish?" by checking whether
        // `published_at` was still null — but this same request sets it, so the test
        // was always false and the student was never notified. Reading the intent back
        // out of the row the row itself just changed is the wrong instrument.
        $outcome = DB::transaction(function () use ($submission) {
            $locked = OnlineExamSubmission::whereKey($submission->id)
                ->lockForUpdate()
                ->with('exam')
                ->firstOrFail();

            $this->assertStaffSubmission($locked);
            $this->authorize('grade', $locked);

            // Already released. The desired end state is true, so this is a no-op and
            // NOT an error.
            if ($locked->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED) {
                return ['state' => 'already_published', 'blockers' => []];
            }

            $found = \App\Support\OnlineExams\OnlineExamPublication::blockers($locked);

            if ($found !== []) {
                return ['state' => 'blocked', 'blockers' => $found];
            }

            $updated = $locked->update([
                'status' => OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
                'result_review_state' => 'published',
                'published_at' => now(),
                'published_by' => Auth::id(),
            ]);

            abort_unless(
                $updated && $locked->refresh()->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
                422,
                'The result could not be released. Please try again.'
            );

            return ['state' => 'published_now', 'blockers' => []];
        });

        if ($outcome['state'] === 'blocked') {
            return redirect()
                ->back()
                ->withErrors([
                    'result' => get_phrase('This result cannot be published yet.')
                        .' '.implode(' ', array_column($outcome['blockers'], 'message')),
                ]);
        }

        if ($outcome['state'] === 'already_published') {
            return redirect()
                ->back()
                ->with('success', get_phrase('This result was already published.'));
        }

        // A FIRST release: notify and mail exactly once.
        $fresh = $submission->fresh();

        \App\Support\OnlineExams\OnlineExamResultNotifier::resultAvailable($fresh);
        $fresh = $fresh->load('exam');

        OnlineExamPortalNotifier::create(
            $fresh->exam->school_id,
            $fresh->student_id,
            'result_published',
            'Result Available',
            'Your result for '.$fresh->exam->title.' is now available.',
            \App\Support\OnlineExams\OnlineExamNotificationLink::route('student.online_exam.result', $fresh->id),
            Auth::id(),
            $fresh->exam->id,
            $fresh->id,
            'result-published:'.$fresh->id
        );

        AuditLog::record(
            'update',
            'Online Exams',
            'Published result for submission #'.$submission->id.' by user #'.Auth::id()
        );

        return redirect()->back()->with('success', get_phrase('Result published'));
    }

    public function returnResultForCorrection(Request $request, OnlineExamSubmission $submission)
    {
        abort_unless(Auth::user() && (int) Auth::user()->role_id === 2, 403, 'Only an administrator may return results.');
        $reason = trim((string) $request->input('reason', ''));
        DB::transaction(function () use ($submission) {
            $locked = OnlineExamSubmission::whereKey($submission->id)->lockForUpdate()->with('exam')->firstOrFail();
            $this->assertStaffSubmission($locked);
            $this->authorize('grade', $locked);
            abort_unless($locked->status === OnlineExamSubmission::STATUS_FINALIZED && $locked->result_review_state === 'pending_review', 422, 'Only results awaiting review can be returned.');
            $locked->update(['status' => OnlineExamSubmission::STATUS_PENDING_MANUAL, 'result_review_state' => 'returned_for_correction']);
        });
        $submission->load('exam');
        OnlineExamPortalNotifier::teacher('marking_returned', 'Marking Returned', 'Marking for "' . $submission->exam->title . '" was returned for correction.' . ($reason ? ' Reason: ' . $reason : ''), $submission->exam, Auth::id(), 'marking-returned:' . $submission->id);
        return redirect()->back()->with('success', get_phrase('Marking returned for correction.'));
    }

    private function submitBySubmission(OnlineExamSubmission $submission, string $submittedVia)
    {
        $this->authorize('submit', $submission);

        $locked = DB::transaction(function () use ($submission, $submittedVia) {
            $locked = OnlineExamSubmission::whereKey($submission->id)
                ->lockForUpdate()
                ->with('exam')
                ->firstOrFail();

            if (!empty($locked->submitted_at) || $locked->status !== OnlineExamSubmission::STATUS_IN_PROGRESS) {
                return $locked;
            }

            $now = now();
            if ($locked->expires_at && $now->gte($locked->expires_at)) {
                $locked->timeout_at = $locked->timeout_at ?: $now;
                $submittedVia = 'timeout';
            }

            $answers = OnlineExamAnswer::where('submission_id', $locked->id)
                ->with('question')
                ->get();

            /**
             * GIVE EVERY MANUAL QUESTION A ROW TO BE MARKED ON, EVEN WHEN THE STUDENT
             * WROTE NOTHING.
             *
             * A blank manual question previously had no `online_exam_answers` row at
             * all, because a row is only created when a student saves something. That
             * made it undisplayable and unmarkable: `markSubmissionAnswer()` acts on
             * an answer id, so there was nothing for a marker to act on, and
             * `manualQuestionsAwaitingDecision()` was the first thing to surface it.
             *
             * So an empty row is created here, and ONLY for manual questions that
             * have no response. It is genuinely empty - no text, no option, no mark -
             * so `OnlineExamMarking::responses()` still excludes it from the read
             * queue (a forty-question paper must not become forty rows to click), and
             * `summary()` still contributes nothing for it. Its only effect is that a
             * marker now has something to record an explicit, attributed zero
             * against.
             *
             * Objective questions are deliberately NOT touched: they are scored on
             * submit from whatever row exists, and a blank one already scores zero by
             * having no row. Creating rows for them would change nothing and would
             * make the auto-mark loop iterate rows that carry no answer.
             */
            $locked->load('exam.questions');
            $answeredQuestionIds = $answers->pluck('question_id')->map(fn ($id) => (int) $id)->all();

            foreach ($locked->exam->questions as $paperQuestion) {
                if (\App\Support\OnlineExams\OnlineExamMarking::isAutomatic($paperQuestion)) {
                    continue;
                }

                if (in_array((int) $paperQuestion->id, $answeredQuestionIds, true)) {
                    continue;
                }

                $blank = OnlineExamAnswer::firstOrCreate(
                    ['submission_id' => $locked->id, 'question_id' => $paperQuestion->id],
                    ['school_id' => $locked->school_id, 'answer_revision' => 0]
                );

                $answers->push($blank);
                $answeredQuestionIds[] = (int) $paperQuestion->id;
            }

            foreach ($answers as $answer) {
                $question = $answer->question;
                abort_unless($question && (int) $question->online_exam_id === (int) $locked->online_exam_id, 422, 'Answer question does not belong to this exam.');
                if (\App\Support\OnlineExams\OnlineExamMarking::isAutomatic($question)) {
                    $correct = $this->isObjectiveAnswerCorrect($question, $answer);
                    $answer->update(['is_correct' => $correct, 'awarded_marks' => $correct ? $question->marks : 0]);
                }
            }
            $locked->load(['exam.questions', 'answerRows']);
            $summary = \App\Support\OnlineExams\OnlineExamMarking::summary($locked);
            $objectiveScore = $summary['objective_score'];
            $manualScore = $summary['manual_score'];
            $score = $summary['score'];

            /**
             * HAS A MARKER STILL GOT SOMETHING TO DECIDE?
             *
             * ── THE BUG THIS REPLACES ───────────────────────────────────────
             *
             * This used to ask `summary()['pending']`, which counts only a manual
             * question that HAS a response and is not yet marked. A manual question
             * with NO response was skipped, so a student who answered nothing that
             * needed judgement produced `pending = 0` and this method wrote:
             *
             *     status = $pending ? STATUS_PENDING_MANUAL : STATUS_FINALIZED
             *
             * Exam 17, submission 12: one MCQ (10 marks) and one short answer
             * (10 marks). The MCQ was answered and scored zero; the short answer was
             * never persisted. `pending` came back 0, the submission was written as
             * FINALIZED with `result_review_state = 'not_ready'`, ten marks were never
             * awarded by anybody, and the lecturer's Actions column rendered EMPTY -
             * because `finalized` + `not_ready` is not a state any screen has an
             * action for. It was a zombie: marked as complete, released to nobody,
             * and un-actionable by anyone.
             *
             * ── THE CORRECT QUESTION ────────────────────────────────────────
             *
             * Not "what is there to mark?" but "does this paper contain any question
             * requiring human judgement that no human has yet judged?" Blank manual
             * questions included, because a blank is a thing a marker must DECIDE -
             * the honest decision being an explicit recorded zero - rather than a
             * thing that may quietly vanish from the arithmetic.
             *
             * ── AND `finalized` IS NOW UNREACHABLE FROM HERE ────────────────
             *
             * Finalisation belongs to `finalizeSubmission()`, which a lecturer
             * reaches by handing marking over and an administrator reaches directly.
             * A student's own submit can no longer produce it, so the only way a
             * result becomes "finalized" is a deliberate act by staff.
             */
            $undecided = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($locked);
            $hasUndecided = $undecided !== [];

            // Null until marking is complete. Deciding pass/fail here would make a
            // half-marked paper look judged.
            $passed = $hasUndecided ? null : $score >= (float) $locked->exam->pass_mark;

            // A fully objective paper lands on SUBMITTED: marking needs no human, so
            // it goes straight to the handover/review gate with nothing outstanding.
            $nextStatus = $hasUndecided
                ? OnlineExamSubmission::STATUS_PENDING_MANUAL
                : OnlineExamSubmission::STATUS_SUBMITTED;

            $locked->update([
                'objective_score' => $objectiveScore,
                'manual_score' => $manualScore,
                'score' => $score,
                'passed' => $passed,
                'submitted_at' => $now,
                'submitted_via' => $submittedVia,
                'status' => $nextStatus,
                // Always 'not_ready' here, and now always CORRECT for it: this is
                // the "with the lecturer" state. The previous code could write
                // 'finalized' + 'not_ready' together, which is the combination that
                // produced an action-less result.
                'result_review_state' => 'not_ready',
                'last_activity_at' => $now,
                'timeout_at' => $submittedVia === 'timeout' ? ($locked->timeout_at ?: $now) : $locked->timeout_at,
            ]);

            return $locked;
        });

        $action = $submittedVia === 'timeout' ? 'timeout' : 'submit';
        AuditLog::record($action, 'Online Exams', "Submission #{$locked->id} completed via {$submittedVia}. Score: {$locked->score}");

        $this->notifyLecturersOfSubmission($locked->fresh(['exam']));

        return redirect()->route('student.online_exam.result', $locked->id);
    }

    /**
     * TELL THE MARKER THAT WORK HAS ARRIVED.
     *
     * ── WHY THIS HAD TO BE ADDED ────────────────────────────────────────────
     *
     * A student submitted an exam and nobody was told. `submitBySubmission()` wrote
     * the audit line and redirected. On the Course Offering paper that was
     * invisible to everyone: the lecturer's Marking Queue is a filtered list of
     * answers awaiting marking, so a submission whose answers were all
     * automatically marked never appears there, and the lecturer had no way to
     * learn a student had finished.
     *
     * Recipients are the ALLOCATED lecturers, resolved by
     * `OnlineExamPortalNotifier::lecturerRecipients()`, which reads
     * `exam.course_offering_id -> course_offering_lecturer_allocations`. That is
     * deliberately not `teacher_permissions`, and deliberately not "the author
     * only": on a Course Offering the appointment is the authority, so a
     * co-lecturer is included and a deallocated author is not. A legacy exam keeps
     * its original creator-only rule.
     *
     * The event key is derived from the SUBMISSION and the recipient, not from the
     * attempt time, so a retried or double-fired submit produces one row each - the
     * same idempotence every other lifecycle notification in this engine has.
     */
    private function notifyLecturersOfSubmission(OnlineExamSubmission $submission): void
    {
        $exam = $submission->exam;

        if (! $exam) {
            return;
        }

        $student = $submission->student;
        $studentName = $student ? (string) $student->name : (string) $submission->student_id;

        // "Kyeyune Amos submitted Final test — Business Mathematics."
        $course = $exam->courseOffering?->subject?->name;

        $message = $studentName.' submitted '.$exam->title.($course ? ' — '.$course : '.');

        OnlineExamPortalNotifier::teacher(
            'exam_submitted',
            'Exam Submitted by Student',
            $message,
            $exam,
            $submission->student_id,
            'submission:'.$submission->id,
            // So the link opens THIS attempt's row rather than the paper's front
            // page, and so the notification can be correlated with its submission.
            $submission->id
        );
    }

    /**
     * Reopen a submission that was marked FINALIZED WITHOUT BEING HANDED OVER.
     *
     * ── THE STATE THIS EXISTS TO REPAIR ─────────────────────────────────────
     *
     * `status = 'finalized'` together with `result_review_state = 'not_ready'` was
     * written by the old `submitBySubmission()`. Every legitimate path that finalises
     * a result sets the review state to `pending_review` at the same moment, so the
     * pair means one thing only: a student's own submit declared the marking
     * complete and it never went near staff.
     *
     * Submission 12 is exactly that. It renders a result of 0.00/20.00 with an empty
     * Actions column, because:
     *
     *   - `assertSubmissionMarkable()` refuses to mark anything not in
     *     `submitted`/`timed_out`/`pending_manual_marking`, so `finalized` is frozen;
     *   - `finalizeSubmission()` returns false immediately for a finalized row, so
     *     it cannot be re-handed-over;
     *   - the admin review queue keys on `finalized` + `pending_review`, so this row
     *     is in neither list.
     *
     * A result that cannot be marked, cannot be handed over, and appears in no queue
     * is a dead end. This is the authorised way out of it.
     *
     * ── WHAT IT DOES, AND WHAT IT DELIBERATELY DOES NOT DO ─────────────────
     *
     * It moves the row back to `pending_manual_marking` so a marker can decide every
     * outstanding question, and records who did it and why. It does NOT:
     *
     *   - award, infer or back-fill any mark;
     *   - invent an answer, or delete the attempt;
     *   - touch any row that is in a coherent state - it refuses unless the row is
     *     genuinely anomalous, so it cannot be used to reopen a published result.
     *
     * The student's work is untouched throughout. A missing answer stays missing, and
     * the marker records a zero against it on the record.
     */
    public function adminReopenMarkingForReview(OnlineExamSubmission $submission)
    {
        /**
         * ADMINISTRATOR ONLY, BY THE SAME TEST THE OTHER ADMIN ACTIONS USE.
         *
         * This began as a lecturer action, which was a mistake of scope. A lecturer
         * route that can undo a state transition is exactly the hazard that
         * `CourseOfferingAttendanceTest::test_lecturer_can_never_reopen_a_finalised_register`
         * exists to prevent - "No lecturer route exists to reopen, by any name" -
         * and rather than narrow that guard, the recovery belongs here.
         *
         * It also fits the governance model: the anomaly being repaired is one where
         * the system claimed a result was complete without any human involvement.
         * The remedy for a false completion claim is not delegated downward to the
         * person whose marking is being reopened, but arbitrated by the administrator
         * who owns the result lifecycle. The lecturer sees the anomaly explained and
         * escalates; the administrator resolves it.
         */
        abort_unless(Auth::user() && (int) Auth::user()->role_id === 2, 403, 'Only an administrator may reopen marking.');

        $reason = trim((string) request()->input('reason', ''));

        $changed = DB::transaction(function () use ($submission, $reason) {
            $locked = OnlineExamSubmission::whereKey($submission->id)->lockForUpdate()->with('exam')->firstOrFail();
            $this->assertStaffSubmission($locked);
            $this->authorize('grade', $locked);

            /**
             * GOVERNS ON THE PERSISTED PUBLICATION STATE, NOT ON `isResultVisible()`.
             *
             * `isResultVisible()` is the right question for a STUDENT looking at a
             * result page, but the wrong one here: under the `after_exam_end` release
             * policy it stays false until the exam's end datetime passes, even for a
             * result an administrator has already published. Using it as a
             * governance gate would mean a published result looked reopenable for
             * the whole window before release - so the persisted status and review
             * state are checked directly. `isResultVisible()` is kept alongside them
             * as belt-and-braces for any legacy row that published without setting
             * both fields.
             */
            abort_if(
                $locked->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED
                    || $locked->result_review_state === 'published'
                    || $locked->isResultVisible(),
                422,
                'This result has already been published and cannot be reopened.'
            );

            // The one condition. `not_ready` + finalized is unreachable through any
            // current path, so this cannot catch a coherent row.
            abort_unless(
                $locked->status === OnlineExamSubmission::STATUS_FINALIZED
                    && $locked->result_review_state !== 'pending_review',
                422,
                'This submission is not in the unreviewed-finalized state, so there is nothing to reopen.'
            );

            $locked->update([
                'status' => OnlineExamSubmission::STATUS_PENDING_MANUAL,
                'result_review_state' => 'not_ready',
                // `passed` is cleared because it was computed against an incomplete
                // marking. It is recomputed at handover.
                'passed' => null,
            ]);

            return true;
        });

        if ($changed) {
            AuditLog::record(
                'update',
                'Online Exams',
                "Reopened marking for submission #{$submission->id}"
                .($reason !== '' ? ': '.$reason : '')
            );
        }

        return redirect()->back()->with(
            'success',
            get_phrase('Marking reopened. Every question needing a decision is now in the marking queue.')
        );
    }

    private function recomputeSubmissionScore(OnlineExamSubmission $submission): void
    {
        if ($submission->isFinalized()) return;
        $submission->load(['exam.questions', 'answerRows']);
        $summary = \App\Support\OnlineExams\OnlineExamMarking::summary($submission);

        // The completion signal is "no manual question is undecided", which includes
        // BLANK ones - not `summary()['pending']`, which counts only answered work.
        // See manualQuestionsAwaitingDecision() for the submission-12 case.
        $hasUndecided = \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission);

        $submission->update([
            'objective_score' => $summary['objective_score'], 'manual_score' => $summary['manual_score'],
            'score' => $summary['score'], 'passed' => null,
            'status' => $hasUndecided ? OnlineExamSubmission::STATUS_PENDING_MANUAL : OnlineExamSubmission::STATUS_SUBMITTED,
            'result_review_state' => 'not_ready',
        ]);
    }

    private function isObjectiveAnswerCorrect(OnlineExamQuestion $question, OnlineExamAnswer $answer): bool
    {
        if ($question->question_schema_version !== null) {
            $contract = \App\Support\OnlineExams\QuestionContract::normalize($question, true);
            $marking = $contract['marking'];
            $payload = \App\Support\OnlineExams\AnswerContract::fromStored($answer, $question);
            if ($contract['type'] === 'multiple_select') {
                $expected = array_values(array_unique(array_map('strval', $marking['correct_option_ids'] ?? [])));
                $given = array_values(array_unique(array_map('strval', $payload['selected_option_ids'] ?? [])));
                sort($expected); sort($given);
                return $expected === $given;
            }
            if ($contract['type'] === 'numeric') {
                $value = (float) ($payload['value'] ?? 0);
                $target = (float) ($marking['target'] ?? 0);
                $tolerance = max(0.0, (float) ($marking['tolerance'] ?? 0));
                return abs($value - $target) <= $tolerance + 1e-12;
            }
            if ($contract['type'] === 'fill_blank') {
                return QuestionContract::fillBlankCorrect($contract, $payload);
            }
            if ($contract['type'] === 'matching') return QuestionContract::matchingCorrect($contract, $payload);
            if ($contract['type'] === 'ordering') return QuestionContract::orderingCorrect($contract, $payload);
        }
        $expected = \App\Support\OnlineExams\AnswerKey::forQuestion($question);
        $given = strtolower(trim((string) $answer->selected_option));
        return $expected !== '' && $expected === $given;
    }

    private function assertSubmissionMarkable(OnlineExamSubmission $submission): void
    {
        abort_if(!in_array($submission->status, [OnlineExamSubmission::STATUS_SUBMITTED, OnlineExamSubmission::STATUS_TIMED_OUT, OnlineExamSubmission::STATUS_PENDING_MANUAL], true), 422, 'Only submitted attempts may be marked.');
    }

    private function finalizeSubmission(OnlineExamSubmission $submission, string $via): void
    {
        $changed = DB::transaction(function () use ($submission) {
            $locked = OnlineExamSubmission::whereKey($submission->id)->lockForUpdate()->with('exam')->firstOrFail();
            $this->assertStaffSubmission($locked);
            $this->authorize('grade', $locked);
            if ($locked->isFinalized()) return false;
            $this->assertSubmissionMarkable($locked);
            $this->recomputeSubmissionScore($locked);

            /**
             * THE COMPLETION GATE, STATED ON THE QUESTIONS RATHER THAN THE STATUS.
             *
             * This used to check only `status === pending_manual_marking`, which is a
             * proxy. The real requirement is that no question requiring human
             * judgement is undecided - which includes a question the student left
             * BLANK, where the marker must record an explicit zero.
             *
             * `recomputeSubmissionScore()` above has just recomputed the status from
             * that same question, so the two cannot disagree; asserting on the
             * question set as well means a future status change cannot quietly reopen
             * the hole that made submission 12 look marked.
             */
            $undecided = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($locked);

            if ($undecided !== []) {
                abort_if(
                    $locked->status === OnlineExamSubmission::STATUS_PENDING_MANUAL,
                    422,
                    'Finalize is blocked until every question requiring manual marking has been decided - '
                    .count($undecided).' still outstanding, worth '
                    .\App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($locked).' marks.'
                );
            }
            $locked->update(['status' => OnlineExamSubmission::STATUS_FINALIZED,
                'result_review_state' => 'pending_review',
                'passed' => (float) $locked->score >= (float) $locked->exam->pass_mark]);
            return true;
        });
        if ($changed) {
            AuditLog::record('update', 'Online Exams', "Finalized result for submission #{$submission->id} via {$via}");
            \App\Support\OnlineExams\OnlineExamResultNotifier::resultAvailable($submission->fresh());
        }
    }

    private function assertStaffSubmission(OnlineExamSubmission $submission): void
    {
        abort_unless((int) $submission->school_id === (int) $this->school_id
            && $submission->exam && (int) $submission->exam->school_id === (int) $this->school_id, 404);
    }

    private function markSubmissionAnswer(OnlineExamAnswer $answer, array $validated): void
    {
        $changed = DB::transaction(function () use ($answer, $validated) {
            // All marking and finalization writers use the autosave parent lock first.
            $submission = OnlineExamSubmission::whereKey($answer->submission_id)->lockForUpdate()->with('exam')->firstOrFail();
            $this->assertStaffSubmission($submission);
            $this->assertSubmissionMarkable($submission);
            $locked = OnlineExamAnswer::whereKey($answer->id)->where('submission_id', $submission->id)->lockForUpdate()->with('question')->firstOrFail();
            $locked->setRelation('submission', $submission);
            $this->authorize('mark', $locked);
            abort_unless((int) $validated['answer_id'] === (int) $locked->id
                && $locked->question && (int) $locked->question->online_exam_id === (int) $submission->online_exam_id, 422, 'Answer must belong to this submission exam.');
            abort_if(\App\Support\OnlineExams\OnlineExamMarking::isAutomatic($locked->question), 422, 'Automatic marks cannot be overridden.');

            $mark = (float) $validated['awarded_marks'];
            abort_if($mark < 0 || $mark > (float) $locked->question->marks, 422, 'Mark is outside question bounds.');

            /**
             * A BLANK MANUAL ANSWER MAY BE MARKED ZERO, AND ONLY ZERO.
             *
             * This used to refuse any marking of an unanswered question, on the
             * reasonable grounds that there is nothing there to mark. The consequence
             * was worse than the problem it avoided: a blank manual question could
             * never acquire a marker's decision, so it stayed permanently
             * undecided, and the paper could never be honestly completed.
             *
             * Now a marker may record an EXPLICIT ZERO against it, which is the
             * truthful outcome of a blank answer and is stored with `marked_by`,
             * `marked_at` and an audit entry naming who decided. So the record says
             * "Daniel Okello awarded 0 because nothing was written", rather than
             * silently omitting the question from the arithmetic.
             *
             * A NON-ZERO mark against a blank answer is still refused, and that
             * refusal is the point: awarding marks for an answer that does not exist
             * would be fabricating a result. This is also the honest shape of the
             * recovery path for a historical attempt whose answer was lost to the
             * autosave defect - a marker records the loss and the zero, on the record,
             * rather than the database being edited.
             */
            if (! \App\Support\OnlineExams\OnlineExamMarking::hasResponse($locked)) {
                abort_if(
                    $mark > 0,
                    422,
                    'This question was not answered, so it can only be marked 0. A mark above zero cannot be awarded for an answer that does not exist.'
                );

                $comment = 'No answer was recorded for this question. Awarded 0 by the marker.';
            }
            $comment = array_key_exists('teacher_comment', $validated) ? $validated['teacher_comment'] : $locked->teacher_comment;
            $identical = \App\Support\OnlineExams\OnlineExamMarking::isManuallyMarked($locked)
                && (float) $locked->awarded_marks === $mark && $locked->teacher_comment === $comment
                && (int) $locked->marked_by === (int) Auth::id();
            if (!$identical) $locked->update(['awarded_marks' => $mark, 'marked_by' => Auth::id(), 'marked_at' => now(), 'teacher_comment' => $comment]);
            $this->recomputeSubmissionScore($submission);
            return !$identical;
        });
        if ($changed) AuditLog::record('update', 'Online Exams', "Marked answer #{$answer->id}");
    }

    private function publishExam(OnlineExam $exam): void
    {
        DB::transaction(function () use ($exam) {
            $locked = OnlineExam::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_published) {
                return;
            }
            $errors = $locked->publicationReadinessErrors();
            if (!empty($errors)) {
                abort(422, implode(' ', $errors));
            }
            $locked->update(['is_published' => 1, 'workflow_state' => 'published', 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'updater_id' => Auth::id()]);
        });
    }

    private function publicationReadinessFailure(Request $request, array $errors)
    {
        $messages = array_merge([get_phrase('Exam cannot be published yet.')], $errors);
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $messages[0],
                'errors' => ['readiness' => $messages],
            ], 422);
        }

        return redirect()->back()->withErrors(['readiness' => $errors])->withInput();
    }

    private function teacherAssignedClassIds(int $teacherId): array
    {
        return TeacherPermission::where('teacher_id', $teacherId)
            ->where('school_id', $this->school_id)
            ->pluck('class_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function teacherAssignableSubjects(int $teacherId, array $assignedClassIds)
    {
        $programmeIds = Schema::hasTable('teacher_programme_assignments')
            ? TeacherProgrammeAssignment::where('teacher_id', $teacherId)
                ->where('school_id', $this->school_id)
                ->pluck('programme_id')->filter()->map(fn($id) => (int) $id)->all()
            : [];

        if (!$assignedClassIds && !$programmeIds) {
            return collect();
        }

        return Subject::where('school_id', $this->school_id)
            ->where(function ($query) use ($assignedClassIds, $programmeIds) {
                if ($assignedClassIds) {
                    $query->whereIn('class_id', $assignedClassIds);
                }
                if ($programmeIds) {
                    $query->orWhereIn('programme_id', $programmeIds);
                }
            })
            ->orderBy('name')
            ->get();
    }

    private function teacherAssignableProgrammes(int $teacherId)
    {
        if (!Schema::hasTable('teacher_programme_assignments')) {
            return collect();
        }

        $programmeIds = TeacherProgrammeAssignment::where('teacher_id', $teacherId)
            ->where('school_id', $this->school_id)->pluck('programme_id');

        return Programme::where('school_id', $this->school_id)
            ->where('is_active', 1)
            ->whereIn('id', $programmeIds)
            ->orderBy('name')
            ->get();
    }

    private function academicSessionsForSelection()
    {
        $sessions = Session::where('school_id', $this->school_id)
            ->orderByDesc('status')->orderByDesc('id')->get();

        $human = $sessions->filter(function ($session) {
            $title = trim((string) $session->session_title);
            return $title !== '' && !preg_match('/^Session\s+[a-f0-9]{8,}$/i', $title);
        });

        return $human->isNotEmpty() ? $human : $sessions;
    }

    private function canonicalizeBankAnswer(array $data): ?string
    {
        $type = (string) ($data['type'] ?? '');
        $raw = $type === 'true_false' ? ($data['correct_answer_tf'] ?? $data['correct_ans'] ?? null) : ($data['correct_ans'] ?? null);
        $key = \App\Support\OnlineExams\AnswerKey::normalize($type, $raw, [
            'a' => $data['option_a'] ?? null,
            'b' => $data['option_b'] ?? null,
            'c' => $data['option_c'] ?? null,
            'd' => $data['option_d'] ?? null,
        ]);

        if (in_array($type, ['mcq', 'true_false'], true) && $key === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'correct_ans' => $type === 'mcq'
                    ? 'Select a valid correct option (A, B, C or D).'
                    : 'Select True or False as the correct answer.',
            ]);
        }

        return $key;
    }

    private function snapshotStorageType(QuestionBank $bankQuestion): string
    {
        if ($bankQuestion->question_schema_version === null) {
            return (string) $bankQuestion->type;
        }

        $type = QuestionContract::normalize($bankQuestion)['type'];
        return match ($type) {
            'multiple_select' => 'mcq',
            'numeric', 'matching', 'ordering' => 'short',
            default => $type,
        };
    }

    private function structuredBankFields(array $data): array
    {
        $structured = \App\Support\OnlineExams\QuestionContract::authoring(
            (string) $data['type'],
            (string) $data['question'],
            (array) (($data['type'] ?? null) === 'fill_blank' ? ($data['structured_blanks'] ?? []) : (($data['type'] ?? null) === 'matching' ? ($data['structured_pairs'] ?? []) : (($data['type'] ?? null) === 'ordering' ? ($data['structured_order_items'] ?? []) : ($data['structured_options'] ?? [])))),
            [
                'correct_option_ids' => (array) ($data['correct_option_ids'] ?? []),
                'target' => $data['numeric_target'] ?? null,
                'tolerance' => $data['numeric_tolerance'] ?? 0,
                'case_sensitive' => !empty($data['case_sensitive']),
                'trim_whitespace' => array_key_exists('trim_whitespace', $data) ? !empty($data['trim_whitespace']) : true,
            ],
            $data['marks']
        );

        return [
            'type' => $structured['storage_type'],
            'correct_ans' => null,
            'question_schema_version' => $structured['schema_version'],
            'question_config' => $structured['question_config'],
            'marking_config' => $structured['marking_config'],
        ];
    }

    private function questionBankMetadataPayload(array $data): array
    {
        if (!Schema::hasColumn('question_banks', 'programme_id')) return [];
        return ['programme_id'=>$data['programme_id']??null,'session_id'=>$data['session_id']??null,'topic_id'=>$data['topic_id']??null,'subtopic_id'=>$data['subtopic_id']??null,'status'=>$data['status']??'active'];
    }

    /** Remove inactive authoring groups and unused active slots before wildcard validation. */
    private function normalizeBankStructuredOptions(Request $request): void
    {
        $type = (string) $request->input('type', '');
        $active = match ($type) {
            'multiple_select' => 'structured_options',
            'fill_blank' => 'structured_blanks',
            'matching' => 'structured_pairs',
            'ordering' => 'structured_order_items',
            default => null,
        };
        foreach (['structured_options', 'structured_pairs', 'structured_order_items', 'structured_blanks'] as $group) {
            if ($group !== $active) $request->request->remove($group);
        }
        if (!$active) return;
        $inputRows = (array) $request->input($active, []);
        if ($active === 'structured_blanks') {
            $inputRows = array_values(array_filter(array_map(static function ($row) {
                if (!is_array($row)) return null;
                $answers = array_values(array_filter(array_map(static fn ($answer) => trim((string) $answer), (array) ($row['accepted_answers'] ?? [])), static fn ($answer) => $answer !== ''));
                if (!$answers) return null;
                $row['accepted_answers'] = $answers;
                return $row;
            }, $inputRows)));
        }
        $rows = array_values(array_filter($inputRows, static function ($row) use ($active) {
            if (!is_array($row)) return false;
            if ($active === 'structured_options') return trim((string) ($row['label'] ?? '')) !== '';
            if ($active === 'structured_blanks') return collect((array) ($row['accepted_answers'] ?? []))->contains(fn ($answer) => trim((string) $answer) !== '');
            foreach ($row as $value) {
                if (is_array($value)) {
                    if (collect($value)->contains(fn ($item) => trim((string) $item) !== '')) return true;
                } elseif (trim((string) $value) !== '') return true;
            }
            return false;
        }));
        if ($active === 'structured_options') {
            $correctIds = array_map('strval', (array) $request->input('correct_option_ids', []));
            foreach ($inputRows as $row) {
                if (is_array($row) && in_array((string) ($row['id'] ?? ''), $correctIds, true) && trim((string) ($row['label'] ?? '')) === '') $rows[] = $row;
            }
        }
        $request->request->set($active, $rows);
    }

    private function validateQuestionBankAcademicMetadata(array $data, $teacher = null): void
    {
        if (!empty($data['programme_id'])) {
            abort_unless(Programme::where('school_id', $this->school_id)->whereKey($data['programme_id'])->where('is_active', 1)->exists(), 422, 'The selected Programme is not available in this school.');
        }
        if (!empty($data['session_id'])) {
            abort_unless(Session::where('school_id', $this->school_id)->whereKey($data['session_id'])->exists(), 422, 'The selected Academic Period is not available in this school.');
        }
        if (!empty($data['subject_id'])) {
            abort_unless(Subject::where('school_id', $this->school_id)->whereKey($data['subject_id'])->exists(), 422, 'The selected Course is not available in this school.');
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], ['draft', 'active', 'retired', 'archived'], true)) {
            abort(422, 'The selected lifecycle state is invalid.');
        }
        if (!empty($data['subject_id']) && !empty($data['programme_id'])) {
            abort_unless(Subject::where('school_id', $this->school_id)->whereKey($data['subject_id'])->where('programme_id', $data['programme_id'])->exists(), 422, 'The selected Course is not part of the selected Programme.');
        }
        if ($teacher && !empty($data['programme_id'])) {
            abort_unless(TeacherProgrammeAssignment::where('school_id', $this->school_id)->where('teacher_id', $teacher->id)->where('programme_id', $data['programme_id'])->exists(), 403);
        }
        $topic = !empty($data['topic_id']) ? QuestionTopic::where('school_id',$this->school_id)->where('subject_id',$data['subject_id'])->whereNull('parent_id')->find($data['topic_id']) : null;
        abort_if(!empty($data['topic_id']) && !$topic, 422, 'The selected Topic is not available for this Course.');
        if (!empty($data['subtopic_id'])) {
            abort_unless($topic && QuestionTopic::where('id',$data['subtopic_id'])->where('school_id',$this->school_id)->where('subject_id',$data['subject_id'])->where('parent_id',$topic->id)->exists(), 422, 'The selected Subtopic does not belong to the selected Topic.');
        }
        $tagIds = array_values(array_unique(array_map('intval', (array)($data['tag_ids'] ?? []))));
        abort_if(count($tagIds) !== count((array)($data['tag_ids'] ?? [])), 422, 'Duplicate tags are not allowed.');
        if ($tagIds && QuestionTag::where('school_id',$this->school_id)->whereIn('id',$tagIds)->count() !== count($tagIds)) abort(422, 'One or more tags are not available in this school.');
    }

    private function teacherAssignableClasses(array $assignedClassIds)
    {
        if (empty($assignedClassIds)) {
            return collect();
        }

        return Classes::where('school_id', $this->school_id)
            ->whereIn('id', $assignedClassIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Restricts an OnlineExam query to exams this teacher is allowed to see
     * in their own list/monitor pages: their own exams, plus any exam for a
     * class they're assigned to teach — unless they hold edit_all_online_exams,
     * in which case the whole school is visible. Shared by teacherIndex(),
     * teacherLiveMonitor() and teacherExamLifecycleCounts() so the three
     * "what can this teacher see" views can never drift out of sync.
     */
    private function applyTeacherOwnershipScope($query, $user, array $assignedClassIds, bool $canEditAll): void
    {
        if ($canEditAll) {
            return;
        }

        // ── A COURSE OFFERING EXAM IS OWNED BY THE ALLOCATION, NOT BY A CLASS ──
        //
        // This scope had three arms: I made it, I made it via `created_by`, or it
        // targets a class I teach. An Offering exam has `class_id` NULL BY DESIGN -
        // filling it would enrol a whole class and bypass confirmed registration -
        // so the only arm that could ever match it was "I made it".
        //
        // The consequence was that a co-lecturer could not see an assessment for a
        // course they are allocated to teach, and a lecturer who was re-appointed to
        // an Offering lost sight of the papers already sitting in the review queue.
        // Both are the same defect the create page had: teaching authority read from
        // the legacy class graph instead of from the allocation.
        //
        // The arm is OR-ed in, so it can only ADD rows this lecturer is entitled to.
        // Legacy exams are untouched: their `course_offering_id` is NULL and this
        // branch keys on it.
        $offeringIds = $this->lecturerCourseOfferingIds((int) $user->id);

        $query->where(function ($q) use ($user, $assignedClassIds, $offeringIds) {
            $q->where('creator_id', $user->id)
                ->orWhere('created_by', $user->id);

            if (!empty($assignedClassIds)) {
                $q->orWhereIn('class_id', $assignedClassIds);
            }

            if (!empty($offeringIds)) {
                $q->orWhereIn('course_offering_id', $offeringIds);
            }
        });
    }

    /**
     * The Course Offerings this lecturer holds an allocation on, in this tenant.
     *
     * `ended` is included and `cancelled` excluded, matching
     * `LecturerCourseOfferingAccess::reachableOfferings()`: a lecturer keeps sight of
     * a course that has finished, because its papers and their marking queues still
     * exist, but never of one whose appointment was cancelled.
     *
     * Fails closed on a schema without the tables or the column, which is the same
     * guard `CourseOfferingExamAccess::teachableOfferings()` applies.
     *
     * @return list<int>
     */
    private function lecturerCourseOfferingIds(int $teacherId): array
    {
        if (! Schema::hasTable('course_offering_lecturer_allocations')
            || ! Schema::hasTable('course_offerings')
            || ! Schema::hasColumn('online_exams', 'course_offering_id')) {
            return [];
        }

        return DB::table('course_offering_lecturer_allocations')
            ->where('school_id', $this->school_id)
            ->where('user_id', $teacherId)
            ->whereIn('status', ['planned', 'active', 'ended'])
            ->pluck('course_offering_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string,int> counts per lifecycle bucket, for the summary cards on teacherIndex() */
    private function teacherExamLifecycleCounts($user, array $assignedClassIds, bool $canEditAll): array
    {
        $base = function () use ($user, $assignedClassIds, $canEditAll) {
            $query = OnlineExam::forSchool($this->school_id);
            $this->applyTeacherOwnershipScope($query, $user, $assignedClassIds, $canEditAll);

            return $query;
        };

        return [
            'draft' => $base()->where('workflow_state', 'draft')->count(),
            'pending_review' => $base()->where('workflow_state', 'pending_review')->count(),
            'published' => $base()->upcoming()->count(),
            'active' => $base()->active()->count(),
            'completed' => $base()->ended()->count(),
            'cancelled' => $base()->where('workflow_state', 'cancelled')->count(),
        ];
    }

    private function teacherQuestionBankQuery($user, array $assignedClassIds)
    {
        $assignedSubjectIds = empty($assignedClassIds)
            ? collect()
            : Subject::where('school_id', $this->school_id)
                ->whereIn('class_id', $assignedClassIds)
                ->pluck('id');

        return QuestionBank::forSchool($this->school_id)
            ->where(function ($query) use ($user, $assignedSubjectIds) {
                $query->where('created_by', $user->id)->orWhereNull('created_by');
                if ($assignedSubjectIds->isNotEmpty()) {
                    $query->orWhere(function ($assigned) use ($assignedSubjectIds) {
                        $assigned->whereIn('subject_id', $assignedSubjectIds)
                            ->whereExists(function ($creator) {
                                $creator->selectRaw('1')->from('users')
                                    ->whereColumn('users.id', 'question_banks.created_by')
                                    ->where('users.role_id', '!=', 3);
                            });
                    });
                }
            });
    }

    private function applyLifecycleFilter($query, string $lifecycleState): void
    {
        $now = now();
        if ($lifecycleState === 'active') {
            $query->where('workflow_state', 'published')
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_datetime')->orWhere('start_datetime', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_datetime')->orWhere('end_datetime', '>=', $now);
                });
            return;
        }

        if ($lifecycleState === 'completed') {
            $query->where('workflow_state', 'published')
                ->whereNotNull('end_datetime')
                ->where('end_datetime', '<', $now);
            return;
        }

        if ($lifecycleState === 'cancelled') {
            $query->where('workflow_state', 'cancelled');
        }
    }

    private function applyTabFilter($query, string $tab): void
    {
        $now = now();
        if ($tab === 'drafts') {
            $query->where('workflow_state', 'draft');
            return;
        }

        if ($tab === 'pending_review') {
            $query->where('workflow_state', 'pending_review');
            return;
        }

        if ($tab === 'published') {
            $query->where('workflow_state', 'published');
            return;
        }

        if ($tab === 'upcoming') {
            $query->upcoming();
            return;
        }

        if ($tab === 'active') {
            $query->where('workflow_state', 'published')
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_datetime')->orWhere('start_datetime', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_datetime')->orWhere('end_datetime', '>=', $now);
                });
            return;
        }

        if ($tab === 'completed') {
            $query->where('workflow_state', 'published')
                ->whereNotNull('end_datetime')
                ->where('end_datetime', '<', $now);
            return;
        }

        if ($tab === 'cancelled') {
            $query->where('workflow_state', 'cancelled');
        }
    }

    private function findExamOrFail(int $examId): OnlineExam
    {
        return OnlineExam::forSchool($this->school_id)->findOrFail($examId);
    }

    /**
     * The one exam this student is allowed to open.
     *
     * Same rule as the list, deliberately: a student who can see an exam on the
     * list must be able to open it, and one who cannot must get a 404 rather than
     * a page that then refuses to save.
     *
     * A Course Offering assessment is reachable ONLY by a student with a confirmed
     * Course Registration on that Offering. Its `class_id` is NULL by design - HEI
     * delivery is never faked through the legacy Class/Section graph - and the
     * original rule treats a NULL class as "every student in the school", so
     * extending that rule to Offering exams would have published this course's
     * assessment to the entire institution. See CourseOfferingExamAccess.
     */
    private function findStudentExamOrFail(int $examId): OnlineExam
    {
        $user = Auth::user();

        return app(CourseOfferingExamAccess::class)
            ->applyStudentVisibility(
                OnlineExam::forSchool($this->school_id)->published(),
                (int) $user->id,
                $this->school_id
            )
            ->findOrFail($examId);
    }

    private function findStudentSubmissionOrFail(int $submissionId): OnlineExamSubmission
    {
        return OnlineExamSubmission::where('id', $submissionId)
            ->where('school_id', $this->school_id)
            ->where('student_id', Auth::id())
            ->with('exam')
            ->firstOrFail();
    }
}
