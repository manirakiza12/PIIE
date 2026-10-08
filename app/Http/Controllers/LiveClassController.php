<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLiveClassRequest;
use App\Http\Requests\StoreOfferingLiveClassRequest;
use App\Http\Requests\UpdateLiveClassRequest;
use App\Models\AuditLog;
use App\Models\Classes;
use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\LiveClassMaterial;
use App\Models\LiveClassMeetGuest;
use RuntimeException;
use App\Models\Noticeboard;
use App\Models\Programme;
use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Support\Permissions\PermissionService;
use App\Support\LiveClasses\JitsiTokenService;
use App\Support\Google\GoogleAccountService;
use App\Support\Google\GoogleCalendarService;
use App\Support\Google\GoogleOAuthCredentials;
use App\Support\LiveClasses\GoogleConferenceStatus;
use App\Support\LiveClasses\MeetingResolution;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveClassController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', LiveClass::class);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        $search = trim((string) $request->input('search', ''));
        $subjectId = $request->input('subject_id');
        $platform = $request->input('platform');
        $status = $request->input('status');
        $date = $request->input('date');
        // Quick-filter tab: without one, the queue would otherwise grow to
        // every class ever scheduled, most of it long finished and no
        // longer actionable. "upcoming" (drafts/scheduled/live, i.e. not yet
        // ended) is the default; staff explicitly asks for "completed",
        // "cancelled" or "all" to see the rest.
        $view = $request->input('view', $status ? 'all' : 'upcoming');
        // HEI filters. A Course Offering and an academic period are the
        // instruments an HEI lecturer actually filters by; Class/Section/Session
        // mean nothing in an HEI timetable.
        $courseOfferingId = $request->input('course_offering_id');
        $academicPeriodId = $request->input('academic_period_id');

        $classes = LiveClass::query()
            ->where('school_id', $this->school_id)
            ->where(function ($scope) use ($access) {
                $scope->where(function ($legacy) {
                    $legacy->whereNull('course_offering_id')
                        ->when(!$this->canManageAll(Auth::user()), fn ($query) => $query->where(function ($owner) {
                            $owner->where('teacher_id', Auth::id())->orWhere('created_by', Auth::id());
                        }));
                });
                if ($access->canViewAllOfferingClasses(Auth::user(), (int) $this->school_id)) {
                    $scope->orWhereNotNull('course_offering_id');
                } else {
                    $scope->orWhereIn('id', $access->lecturerVisibleClassIdsQuery(Auth::user(), (int) $this->school_id));
                }
            })
            ->when($search !== '', fn($q) => $q->where('title', 'like', "%{$search}%"))
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->when($platform, fn($q) => $q->where('platform', $platform))
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($date, fn($q) => $q->whereDate('start_date', $date))
            // Offering-aware filters for the higher-education view. An Offering
            // and an academic period are what an HEI lecturer actually thinks
            // in; Class/Section/Session are meaningless in an HEI timetable.
            ->when($courseOfferingId, fn($q) => $q->where('course_offering_id', (int) $courseOfferingId))
            ->when($academicPeriodId, fn($q) => $q->whereIn('course_offering_id',
                \App\Models\CourseOffering::query()
                    ->where('school_id', $this->school_id)
                    ->where('academic_period_id', (int) $academicPeriodId)
                    ->select('id')
            ))

            // The explicit status dropdown is the more precise instrument;
            // the view tab's coarse date-window logic only applies when the
            // caller hasn't already pinned an exact status.
            ->when(!$status, fn($q) => $this->applyQuickView($q, $view))
            ->with(array_merge(
                ['subject', 'teacher', 'programme', 'academicSession'],
                // Only eager-load the Offering when the table is actually
                // present, so a K12 install (or a minimal fixture) that has
                // never adopted Course Offerings is not broken by a listing
                // feature it does not use.
                \Illuminate\Support\Facades\Schema::hasTable('course_offerings') ? ['courseOffering'] : []
            ))
            ->orderByDesc('start_date')
            ->orderByDesc('start_time')
            ->paginate(20);

        $subjects = $this->getAllowedSubjects();
        $classList = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();

        // The lecturer's own manageable Offerings, so the Course Offering filter
        // can never offer a class the lecturer may not see. Skipped entirely
        // when the institution has not adopted Course Offerings.
        $scheduling = app(\App\Support\LiveClasses\LiveClassSchedulingContext::class);
        $offeringAware = \Illuminate\Support\Facades\Schema::hasTable('course_offerings')
            && \Illuminate\Support\Facades\Schema::hasTable('academic_periods');
        $isHei = $offeringAware && $scheduling->isHigherEducation(Auth::user());
        $filterOfferings = $isHei
            ? $scheduling->manageableOfferings(Auth::user())
            : collect();
        $filterPeriods = $isHei
            ? \App\Models\AcademicPeriod::query()
                ->where('school_id', $this->school_id)
                ->whereIn('id', \App\Models\CourseOffering::query()
                    ->where('school_id', $this->school_id)
                    ->whereIn('id', $filterOfferings->pluck('id')->all())
                    ->distinct()
                    ->select('academic_period_id'))
                ->orderByDesc('id')
                ->get()
            : collect();
        $platformStatus = $this->platformConfigurationStatus();
        $defaultPlatform = $this->defaultPlatform();

        return view('admin.live_class.index', [
            'classes' => $classes,
            'subjects' => $subjects,
            'classList' => $classList,
            'programmes' => $programmes,
            'sessions' => $sessions,
            'search' => $search,
            'subjectId' => $subjectId,
            'platform' => $platform,
            'status' => $status,
            'date' => $date,
            'platformStatus' => $platformStatus,
            'defaultPlatform' => $defaultPlatform,
            // Google connection facts for the lecturer panel. `googleConnection` is
            // the signed-in user's OWN row and is null for everyone else, so this
            // cannot surface another lecturer's grant — and the partial that
            // renders it only shows anything at all to a lecturer.
            'googleConnection' => app(GoogleAccountService::class)->forUser(Auth::user()),
            'googleConfigured' => GoogleOAuthCredentials::isConfigured(),
            'view' => $view,
            'emptyState' => $this->emptyStateForView($view),
            // HEI Offering-aware filtering
            'isHei' => $isHei,
            'filterOfferings' => $filterOfferings,
            'filterPeriods' => $filterPeriods,
            'courseOfferingId' => $courseOfferingId,
            'academicPeriodId' => $academicPeriodId,
            'meetNowOfferings' => $filterOfferings,
        ]);
    }

    /**
     * The four quick-filter tabs shown above the table/cards. Kept as one
     * method so the admin/teacher queue and the student listing can never
     * define "upcoming" or "completed" differently.
     *
     * Every branch is expressed against the STORED status or against the stored
     * instants - never against a rendered time - so a filter can never disagree
     * with the viewer's own timezone.
     *
     * "Completed" means ONE thing here: a person ended the class. It used to
     * also match anything whose `ends_at` had passed, which let a class nobody
     * ever taught be filed as completed purely because the clock moved on. That
     * is the same false claim as the model's own derived status, and it is fixed
     * in both places for the same reason.
     */
    private function applyQuickView($query, string $view)
    {
        return match ($view) {
            'live' => $query->active(),
            'completed' => $query->where('status', LiveClass::STATUS_ENDED),
            'cancelled' => $query->where('status', LiveClass::STATUS_CANCELLED),
            'all' => $query,
            // Upcoming: everything that has not been concluded and has not
            // finished. A class whose scheduled end has passed but was never
            // closed is NOT upcoming - it is neither running nor completed -
            // so it is deliberately left out of this tab and still findable
            // under All, where it shows its real state instead of a false one.
            default => $query->where('status', '!=', LiveClass::STATUS_CANCELLED)
                ->where('status', '!=', LiveClass::STATUS_ENDED)
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                }),
        };
    }

    /**
     * Wording for an empty tab, so a filtered list never claims something
     * untrue. "No live classes scheduled." under a Cancelled filter used to
     * tell a user they had no classes at all when in fact they had cancelled
     * ones, which is the opposite of the reassurance the screen owes them.
     */
    private function emptyStateForView(string $view): array
    {
        return match ($view) {
            'cancelled' => [get_phrase('No cancelled live classes'), get_phrase('You have not cancelled any live classes. Cancelled classes stay here as a record.')],
            'live' => [get_phrase('No live classes are running now'), get_phrase('A class appears here while it is running. Joining opens 15 minutes before the start time.')],
            'completed' => [get_phrase('No completed live classes'), get_phrase('A class appears here once your lecturer marks it completed.')],
            default => [get_phrase('No upcoming live classes'), get_phrase('You have no live classes scheduled ahead.')],
        };
    }

    public function create(Request $request)
    {
        $this->authorize('create', LiveClass::class);

        $scheduling = app(\App\Support\LiveClasses\LiveClassSchedulingContext::class);

        // A higher-education lecturer schedules through a Course Offering, not
        // through Class/Section/Programme/Session. Rather than serve them the
        // legacy form and let them pick a "Class" that has no meaning in an
        // HEI timetable, this screen asks which Course Offering first and
        // carries that choice into the real form as route context.
        //
        // The legacy K12 form below is untouched and still served to K12
        // institutions and to tenant admins.
        if (request()->routeIs('teacher.*') && $scheduling->isHigherEducation($request->user())) {
            $offerings = $scheduling->manageableOfferings($request->user());

            return view('admin.live_class.hei_offerings', [
                'offerings' => $offerings,
                'scheduling' => $scheduling,
            ]);
        }

        $liveClass = new LiveClass([
            'platform' => $this->defaultPlatform(),
            'status' => LiveClass::STATUS_DRAFT,
            'timezone' => config('app.timezone', 'UTC'),
            'is_published' => false,
        ]);

        $subjects = $this->getAllowedSubjects();
        $classList = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $platformStatus = $this->platformConfigurationStatus();

        return view('admin.live_class.create', compact('liveClass', 'subjects', 'classList', 'programmes', 'sessions', 'platformStatus'));
    }

    public function openModal(Request $request)
    {
        $id = $request->id;
        if ($id) {
            $liveClass = LiveClass::where('school_id', $this->school_id)->findOrFail($id);
            $this->authorizeClassManage($liveClass);
        } else {
            $this->authorize('create', LiveClass::class);
            $liveClass = new LiveClass([
                'platform' => $this->defaultPlatform(),
                'status' => LiveClass::STATUS_DRAFT,
                'timezone' => config('app.timezone', 'UTC'),
                'is_published' => false,
            ]);
        }

        $subjects = $this->getAllowedSubjects();
        $classList = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $platformStatus = $this->platformConfigurationStatus();

        return view('admin.live_class.modal', compact('liveClass', 'subjects', 'classList', 'programmes', 'sessions', 'platformStatus'));
    }

    public function store(StoreLiveClassRequest $request)
    {
        $this->authorize('create', LiveClass::class);
        $this->rejectOfferingContextOnLegacyWorkflow($request);

        $validated = $request->validated();
        $payload = $this->buildPayload($validated, null);

        $liveClass = DB::transaction(function () use ($payload) {
            $record = LiveClass::create($payload);
            AuditLog::record('create', 'Live Classes', "Scheduled live class: {$record->title}");
            $this->createStudentLiveClassNotice($record, 'scheduled');
            return $record;
        });

        if ($request->expectsJson() || $request->ajax()) {
            $routePrefix = $this->getRoutePrefix($request);
            return response()->json([
                'status' => 'success',
                'message' => get_phrase('Live class scheduled'),
                'redirect' => route($routePrefix . '.live_classes.show', $liveClass->id),
            ]);
        }

        $routePrefix = $this->getRoutePrefix($request);
        return redirect()->route($routePrefix . '.live_classes.show', $liveClass->id)
            ->with('success', get_phrase('Live class scheduled'));
    }

    public function meetNow(Request $request)
    {
        $this->authorize('create', LiveClass::class);

        $scheduling = app(\App\Support\LiveClasses\LiveClassSchedulingContext::class);
        $requiresOffering = $scheduling->isHigherEducation(Auth::user());

        // A K12 institution still may not smuggle Offering context onto this
        // legacy endpoint. A higher-education one MUST supply it - that is what
        // stops an instant meeting from existing outside every academic record.
        if (! $requiresOffering) {
            $this->rejectOfferingContextOnLegacyWorkflow($request);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'exists:subjects,id'],
            'class_id' => ['nullable', 'exists:classes,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'academic_session_id' => ['nullable', 'exists:sessions,id'],
            'platform' => ['nullable', 'in:jitsi,google_meet,zoom'],
            // Required for an instant meeting in a higher-education institution.
            // A missing one is a field error the lecturer can act on, not a 404.
            'course_offering_id' => $requiresOffering
                ? ['required', 'integer', 'exists:course_offerings,id']
                : ['nullable', 'integer', 'exists:course_offerings,id'],
        ], [
            'course_offering_id.required' => get_phrase('Choose the Course Offering this meeting belongs to.'),
        ]);

        $platform = $validated['platform'] ?? $this->defaultPlatform();
        if (!in_array($platform, $this->getEnabledPlatforms(), true)) {
            throw ValidationException::withMessages([
                'platform' => get_phrase('Selected platform is disabled by administrator settings.'),
            ]);
        }

        $subject = null;
        if (!empty($validated['subject_id'])) {
            $subject = Subject::where('id', $validated['subject_id'])
                ->where('school_id', $this->school_id)
                ->first();

            if (!$subject) {
                throw ValidationException::withMessages([
                    'subject_id' => get_phrase('Selected course is invalid for this school.'),
                ]);
            }
        }

        $classId = $validated['class_id'] ?? null;
        if ($classId) {
            $classExists = Classes::where('id', $classId)
                ->where('school_id', $this->school_id)
                ->exists();

            if (!$classExists) {
                throw ValidationException::withMessages([
                    'class_id' => get_phrase('Selected class is invalid for this school.'),
                ]);
            }
        }

        if ($subject && $subject->class_id && $classId && (int) $subject->class_id !== (int) $classId) {
            throw ValidationException::withMessages([
                'subject_id' => get_phrase('Selected course does not belong to the selected class.'),
            ]);
        }

        if (!$classId && $subject && $subject->class_id) {
            $classId = (int) $subject->class_id;
        }

        $sessionId = $validated['academic_session_id'] ?? null;
        if ($sessionId) {
            $sessionExists = Session::where('id', $sessionId)
                ->where('school_id', $this->school_id)
                ->exists();

            if (!$sessionExists) {
                throw ValidationException::withMessages([
                    'academic_session_id' => get_phrase('Selected session is invalid for this school.'),
                ]);
            }
        }

        $programmeId = $validated['programme_id'] ?? null;
        if ($programmeId) {
            $programmeExists = Programme::where('id', $programmeId)
                ->where('school_id', $this->school_id)
                ->exists();

            if (!$programmeExists) {
                throw ValidationException::withMessages([
                    'programme_id' => get_phrase('Selected programme is invalid for this school.'),
                ]);
            }
        }

        $now = now();
        $endsAt = $now->copy()->addHour();
        $title = trim((string) ($validated['title'] ?? 'Instant Live Class ' . $now->format('H:i')));
        $meetNowResolution = $this->resolveMeeting(
            $platform,
            $title,
            $now,
            $endsAt,
            config('app.timezone', 'UTC')
        );
        $meetingUrl = $meetNowResolution->url;

        $payload = [
            'school_id' => $this->school_id,
            'title' => $title,
            'description' => 'Instant meeting created by ' . (Auth::user()->name ?? 'staff'),
            'subject_id' => $subject?->id,
            'class_id' => $classId,
            'programme_id' => $programmeId,
            'academic_session_id' => $sessionId,
            'teacher_id' => Auth::id(),
            'platform' => $platform,
            'meeting_url' => $meetingUrl,
            'meeting_id' => null,
            'meeting_password' => null,
            'start_date' => $now->format('Y-m-d'),
            'start_time' => $now->format('H:i'),
            'end_time' => $endsAt->format('H:i'),
            'timezone' => config('app.timezone', 'UTC'),
            'scheduled_at' => $now->timezone('UTC')->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->timezone('UTC')->format('Y-m-d H:i:s'),
            'status' => LiveClass::STATUS_LIVE,
            'is_published' => 1,
            'attendance_enabled' => 1,
            'recording_url' => null,
            // Same rule as buildPayload(): only name the Google columns when there
            // is a Google event to record. `meetNow` never has one.
            ...array_filter([
                'google_calendar_event_id' => $meetNowResolution?->eventId,
                'google_conference_status' => $meetNowResolution?->conferenceStatus,
            ], fn ($value) => $value !== null),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ];

        // An instant meeting in a higher-education institution must belong to a
        // Course Offering. Creating one with only Class/Section/Programme/Session
        // produced a class that belongs to no academic record at all: invisible
        // to the Offering workspace, to the lecturer's own Course Offerings,
        // and to every Attendance or notification rule that keys on the
        // Offering. For a K12 institution the legacy behaviour is unchanged.
        if ($requiresOffering) {
            $offeringId = (int) $request->input('course_offering_id');
            $offering = $this->tenantOfferingOrFail($offeringId);
            $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
            abort_unless(
                $access->canAdminCreateForOffering(Auth::user(), $offering)
                    || $access->canLecturerCreateForOffering(Auth::user(), $offering, $now),
                403
            );

            // Build it through the same governed service the scheduled path
            // uses, so the Offering context, the subject match and the
            // facilitator-allocation rules all apply identically.
            $liveClass = app(\App\Support\LiveClasses\LiveClassService::class)->createForOffering(
                Auth::user(),
                (int) $offering->id,
                array_merge($payload, [
                    'status' => LiveClass::STATUS_LIVE,
                    'is_published' => true,
                    'attendance_enabled' => false,
                ])
            );
            \App\Support\LiveClasses\LiveClassNotifier::announcePublished($liveClass);

            $routePrefix = $this->getRoutePrefix($request);

            return redirect()->route($routePrefix . '.live_classes.join', $liveClass->id);
        }

        $liveClass = DB::transaction(function () use ($payload) {
            $record = LiveClass::create($payload);
            AuditLog::record('create', 'Live Classes', "Started instant live class: {$record->title}");
            $this->createStudentLiveClassNotice($record, 'published');
            return $record;
        });

        $routePrefix = $this->getRoutePrefix($request);
        return redirect()->route($routePrefix . '.live_classes.join', $liveClass->id);
    }

    public function show(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassView($liveClass);

        // courseOffering (and its year/period) is what supplies the academic
        // context for an Offering-backed class. Without it the detail page could
        // only ever show "Programme: —", because programme_id is deliberately
        // null on such a class. Guarded on table existence so an installation
        // (or fixture) without an academic calendar still renders the class.
        $offeringRelations = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('course_offerings')) {
            $offeringRelations = ['courseOffering'];
            foreach (['academic_years', 'academic_periods'] as $calendarTable) {
                if (\Illuminate\Support\Facades\Schema::hasTable($calendarTable)) {
                    $offeringRelations[] = 'courseOffering.'.('academic_years' === $calendarTable ? 'academicYear' : 'academicPeriod');
                }
            }
        }

        $liveClass->load(array_merge(
            ['subject', 'teacher', 'programme', 'academicSession', 'creator'],
            $offeringRelations
        ));

        // One resolver feeds both this page and the student's, so "is this class
        // ready to start" can never be answered differently on the two screens.
        $lifecycle = app(\App\Support\LiveClasses\LiveClassLifecycle::class)->for($liveClass, Auth::user());

        return view('admin.live_class.show', [
            'liveClass' => $liveClass,
            'lifecycle' => $lifecycle,
            'primaryAction' => app(\App\Support\LiveClasses\LiveClassLifecycle::class)->lecturerPrimaryAction($lifecycle),
            'countdown' => app(\App\Support\LiveClasses\LiveClassLifecycle::class)->countdown($lifecycle),
            // Concluding a class is a DIFFERENT question from managing it, and a
            // wider one: a lecturer whose appointment has since moved on must still
            // be able to close out a session they actually ran, or the record stays
            // permanently unclaimed. The lifecycle table still refuses the move if
            // it is not legal.
            'canConclude' => app(\App\Support\LiveClasses\LiveClassAccessService::class)
                ->canLecturerConclude(Auth::user(), $liveClass),
            'canManageRecording' => $this->canManageRecording(Auth::user(), $liveClass),
            // What the provider can genuinely do - PIIE authority is not provider
            // authority, and the page must not blur the two.
            'platform' => app(\App\Support\LiveClasses\LiveClassPlatform::class)
                ->describe($liveClass, (bool) $lifecycle['canHost']),
            'display' => app(\App\Support\LiveClasses\LiveClassDisplay::class)->for($liveClass, Auth::user()),
            'startedByName' => $this->actorName($liveClass, 'started_by'),
            'endedByName' => $this->actorName($liveClass, 'ended_by'),
            'cancelledByName' => $this->actorName($liveClass, 'cancelled_by'),
        ]);
    }

    /**
     * Who performed a recorded lifecycle action, in this tenant only.
     *
     * Read through the class's own school_id rather than by a bare id, so a
     * column can never resolve to somebody from another institution.
     */
    private function actorName(LiveClass $liveClass, string $column): ?string
    {
        $id = $liveClass->{$column};

        if (empty($id)) {
            return null;
        }

        return \App\Models\User::query()
            ->where('school_id', $liveClass->school_id)
            ->whereKey($id)
            ->value('name');
    }

    /**
     * May this person attach or correct a recording?
     *
     * A completed class is read-only, but this stays available on purpose: a
     * recording is normally produced AFTER the class ends, so refusing it would
     * make the resource workflow impossible to use. What is refused is a
     * recording claiming to be "available" for a class that has not concluded -
     * the controller enforces that, because a published recording of a class
     * that never ran asserts teaching that did not happen.
     */
    private function canManageRecording(\App\Models\User $user, LiveClass $liveClass): bool
    {
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        return $access->canManagePostClassResources($user, $liveClass);
    }

    public function edit(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        $subjects = $this->getAllowedSubjects();
        $classList = Classes::where('school_id', $this->school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $sessions = Session::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $platformStatus = $this->platformConfigurationStatus();

        return view('admin.live_class.edit', compact('liveClass', 'subjects', 'classList', 'programmes', 'sessions', 'platformStatus'));
    }

    public function update(UpdateLiveClassRequest $request, LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        if ($liveClass->course_offering_id !== null) {
            $validated = $request->validated();
            $payload = $this->buildPayload($validated, $liveClass);
            if (! array_key_exists('teacher_id', $validated)) {
                $payload['teacher_id'] = $liveClass->teacher_id;
            }
            $meetingFields = array_intersect_key($payload, array_flip([
                'title', 'description', 'teacher_id', 'platform', 'meeting_url', 'meeting_id',
                'meeting_password', 'scheduled_at', 'ends_at', 'start_date', 'start_time',
                'end_time', 'timezone', 'status', 'is_published', 'attendance_enabled', 'recording_url',
            ]));
            try {
                app(\App\Support\LiveClasses\LiveClassService::class)
                    ->updateOfferingMeeting(Auth::user(), (int) $liveClass->id, $meetingFields);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['teacher_id' => get_phrase($exception->getMessage())]);
            }

            if ($request->expectsJson() || $request->ajax()) {
                $routePrefix = $this->getRoutePrefix($request);
                return response()->json([
                    'status' => 'success',
                    'message' => get_phrase('Live class updated'),
                    'redirect' => route($routePrefix . '.live_classes.show', $liveClass->id),
                ]);
            }

            $routePrefix = $this->getRoutePrefix($request);
            return redirect()->route($routePrefix . '.live_classes.show', $liveClass->id)
                ->with('success', get_phrase('Live class updated'));
        }

        $payload = $this->buildPayload($request->validated(), $liveClass);

        DB::transaction(function () use ($liveClass, $payload) {
            $liveClass->update($payload);
            AuditLog::record('update', 'Live Classes', "Updated live class: {$liveClass->title}");
        });

        if ($request->expectsJson() || $request->ajax()) {
            $routePrefix = $this->getRoutePrefix($request);
            return response()->json([
                'status' => 'success',
                'message' => get_phrase('Live class updated'),
                'redirect' => route($routePrefix . '.live_classes.show', $liveClass->id),
            ]);
        }

        $routePrefix = $this->getRoutePrefix($request);
        return redirect()->route($routePrefix . '.live_classes.show', $liveClass->id)
            ->with('success', get_phrase('Live class updated'));
    }

    public function destroy(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);
        abort_if($liveClass->course_offering_id !== null, 403, get_phrase('Offering-backed Live Classes are preserved; cancel the meeting instead.'));

        AuditLog::record('delete', 'Live Classes', "Deleted live class: {$liveClass->title}");
        $liveClass->delete();
        return redirect()->back()->with('success', get_phrase('Live class deleted'));
    }

    /**
     * End a class the lecturer has finished teaching.
     *
     * A governed lifecycle action, not a delete and not an unpublish: the row,
     * its history and its notifications all survive, and no attendance is
     * written. Ending a class that never ran is refused with a plain message,
     * because a class that was never live has no "end".
     */
    public function end(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        if ($liveClass->status === LiveClass::STATUS_CANCELLED) {
            return redirect()->back()->with('error', get_phrase('This Live Class was cancelled, so there is nothing to end.'));
        }

        if (! $liveClass->is_published) {
            return redirect()->back()->with('error', get_phrase('This Live Class was never published, so there is nothing to end.'));
        }

        // The lifecycle table is the authority on whether this move exists at
        // all. Without it a second End press would silently rewrite who ended
        // the class and when, and a concluded class could be reopened.
        abort_unless(
            $liveClass->canTransitionTo(LiveClass::STATUS_ENDED),
            403,
            get_phrase('This Live Class has already been concluded and cannot be ended again.')
        );

        app(\App\Support\LiveClasses\LiveClassService::class)
            ->endMeeting(Auth::user(), (int) $liveClass->id);

        return redirect()->back()->with('success', get_phrase('Live Class ended. It is kept in the record as completed.'));
    }

    public function cancel(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        $wasCancelled = $liveClass->status === LiveClass::STATUS_CANCELLED;
        $notified = 0;

        // COMPLETED IS TERMINAL.
        //
        // authorizeClassManage() already refuses an ended class, but the rule is
        // restated here as a named transition so it holds on EVERY authorization
        // path - including the legacy K12 route, which falls through to a generic
        // policy and would otherwise carry no lifecycle guard at all. Hiding the
        // Cancel button is a courtesy; this is the rule.
        abort_unless(
            $liveClass->canTransitionTo(LiveClass::STATUS_CANCELLED),
            403,
            get_phrase('This Live Class has already been completed and is kept as a record. It cannot be cancelled.')
        );

        // Decided BEFORE the write, because after it the answer is
        // unrecoverable - and because it decides the wording. Deliberately not
        // phrased as "the meeting was ended": PIIE closes its own side, not a
        // conference running on someone else's server.
        $wasOpenExternally = ! $wasCancelled
            && (in_array($liveClass->computed_status, [LiveClass::STATUS_LIVE, LiveClass::STATUS_NOT_CONCLUDED], true)
                || ($liveClass->scheduled_at !== null
                    && $liveClass->ends_at !== null
                    && now()->betweenIncluded($liveClass->scheduled_at, $liveClass->ends_at)));

        DB::transaction(function () use ($liveClass, &$notified, $wasCancelled): void {
            $liveClass->update([
                'status' => LiveClass::STATUS_CANCELLED,
                // Authoritative evidence of the decision and of who made it.
                // Written once, on the first transition only, and never invented
                // for a class that predates the columns.
                'cancelled_at' => $wasCancelled ? $liveClass->cancelled_at : now(),
                'cancelled_by' => $wasCancelled ? $liveClass->cancelled_by : Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Only the first transition announces. A second click on an
            // already-cancelled class is a no-op for students, and the
            // unique-index dedup in LiveClassNotifier enforces that even if
            // this guard were removed.
            if (! $wasCancelled && $liveClass->course_offering_id !== null) {
                $notified = \App\Support\LiveClasses\LiveClassNotifier::announceCancelled($liveClass);
            }

            AuditLog::record('update', 'Live Classes', "Cancelled live class: {$liveClass->title}");
        });

        $message = $this->announcedCount($liveClass, $notified) ?: get_phrase('Live class cancelled');

        if ($wasOpenExternally) {
            // Two separate facts, which the interface must not merge. PIIE has
            // closed ITS side: cancelled, no further joins, students told. PIIE
            // has NOT closed the provider's conference - nothing here can, and
            // nothing here tried. Anyone already inside may still be in that
            // room, and only a moderator of the provider's meeting can end it.
            // Claiming otherwise would leave a room of students believing the
            // session had been shut down when it had not.
            $message .= ' ' . get_phrase('No further joins are possible through PIIE. If the meeting was already open, PIIE has not ended it: end it from the meeting provider, which only a moderator of that meeting can do.');
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Attach or correct a recording, through the same protected resource
     * architecture as every other Live Class material.
     *
     * WHY ATTACHING IS THE NORMAL CASE
     *
     * Neither Jitsi nor Google Meet hands this app a finished recording file
     * over any API PIIE can call here, and PIIE has no way to know that a
     * recording was even made. So the honest workflow is that an authorised
     * lecturer or administrator attaches the recording once the provider has
     * produced it - the same way any other resource is attached - and the state
     * is declared truthfully rather than inferred from a link appearing.
     *
     * The four states are kept distinct because a student genuinely needs to
     * tell them apart: nobody recorded, still processing, available, or failed.
     *
     * A completed class is REQUIRED for "available" - a recording of a class
     * that is still running, or that was cancelled, would assert teaching that
     * either has not finished or did not happen.
     */
    public function attachRecording(Request $request, LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        // Deliberately NOT authorizeClassManage(): that refuses a concluded class,
        // and attaching a recording to a COMPLETED class is the normal case - the
        // provider needs time to produce the file after the class ends. Requiring
        // the class to still be manageable would make the resource workflow
        // impossible to use for the only classes it exists for.
        abort_unless($this->canManageRecording(Auth::user(), $liveClass), 403);

        $validated = $request->validate([
            'recording_status' => ['required', Rule::in([
                LiveClass::RECORDING_NONE,
                LiveClass::RECORDING_PROCESSING,
                LiveClass::RECORDING_AVAILABLE,
                LiveClass::RECORDING_UNAVAILABLE,
            ])],
            'recording_url' => ['nullable', 'url:https', 'max:500'],
        ], [
            'recording_url.url' => get_phrase('The recording link must be a valid https address.'),
        ]);

        $status = $validated['recording_status'];
        $url = trim((string) ($validated['recording_url'] ?? '')) ?: null;

        // "Usable" is decided by the SAME accessor the screens read, so a class
        // can never be announced as having a recording while showing none. A
        // non-empty string is not enough: it must be a real https URL.
        $usableUrl = $url !== null
            ? (new LiveClass())->forceFill(['recording_url' => $url])->safe_recording_url
            : null;

        if ($status === LiveClass::RECORDING_AVAILABLE && $usableUrl === null) {
            return redirect()->back()->with('error', get_phrase('A recording marked available must include a working https recording link. It has not been published, and no student has been told otherwise.'));
        }

        // A recording may only be PUBLISHED for a class that has actually
        // concluded. The test is the outcome, not whether the move happens to be
        // legal from the current status: a live class can still be ended, so
        // "can transition to ended" would have said yes and let a recording of a
        // session that has not finished be announced to students.
        if ($status === LiveClass::RECORDING_AVAILABLE && ! $liveClass->hasConclusiveOutcome()) {
            return redirect()->back()->with('error', get_phrase('A recording can only be published for a class that has been completed. End the class first.'));
        }

        $wasAvailable = $liveClass->isRecordingAvailable();
        $notified = 0;

        DB::transaction(function () use ($liveClass, $status, $url, &$notified, $wasAvailable): void {
            $liveClass->update([
                'recording_status' => $status,
                'recording_url' => $url,
                'updated_by' => Auth::id(),
            ]);

            // Announce only on the first transition INTO a genuinely available
            // recording. Re-read from the model rather than trusting the posted
            // state, so a link that turns out to be unusable never produces a
            // notification about a recording nobody can open.
            $nowAvailable = $liveClass->fresh()->isRecordingAvailable();
            if (! $wasAvailable && $nowAvailable && $liveClass->course_offering_id !== null) {
                $notified = \App\Support\LiveClasses\LiveClassNotifier::announceRecordingAvailable($liveClass);
            }

            AuditLog::record('update', 'Live Classes', "Recording {$status} for live class: {$liveClass->title}");
        });

        return redirect()->back()->with(
            'success',
            $this->announcedCount($liveClass, $notified)
                ?: get_phrase('Recording state saved. Students see exactly this, and nothing more.')
        );
    }

    public function publish(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        $isPublished = !$liveClass->is_published;
        $nextStatus = $isPublished
            ? $this->deriveStatus($liveClass->status, $liveClass->scheduled_at, $liveClass->ends_at, true)
            : LiveClass::STATUS_DRAFT;

        DB::transaction(function () use ($liveClass, $isPublished, $nextStatus): void {
            $liveClass->update([
                'is_published' => $isPublished,
                'status' => $nextStatus,
                'updated_by' => Auth::id(),
            ]);

            if ($isPublished) {
                $this->createStudentLiveClassNotice($liveClass, 'published');
            }

            AuditLog::record('update', 'Live Classes', ($isPublished ? 'Published' : 'Unpublished') . " live class: {$liveClass->title}");
        });

        return redirect()->back()->with('success', get_phrase('Live class publication updated'));
    }

    /**
     * How many registered students a lifecycle event actually reached.
     *
     * Returned to the lecturer as a count, never as identifiers, so the
     * success message can say "3 registered students were notified" without
     * exposing who they are. Any failure inside notification delivery is
     * already contained by LiveClassNotifier, so this is 0 rather than an
     * exception when a channel is unavailable.
     */
    private function announcedCount(LiveClass $liveClass, int $count): string
    {
        if ($count > 0) {
            return get_phrase('Live Class saved. ').$count.' '.($count === 1
                ? get_phrase('registered student was notified.')
                : get_phrase('registered students were notified.'));
        }

        return get_phrase('Live Class saved. ').get_phrase('No registered students were notified.');
    }

    public function join(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        if ($access->isOfferingBacked($liveClass)) {
            $user = Auth::user();
            $isStudent = (int) $user->role_id === 7;
            $canJoin = $isStudent
                ? $access->canStudentJoin($user, $liveClass)
                : ($access->canTenantAdminJoin($user, $liveClass)
                    || $access->canLecturerJoin($user, $liveClass));
            if (! $canJoin) {
                if ($isStudent && ! $access->confirmedStudentRegistration($user, $liveClass)) {
                    return redirect()->back()->with('error', get_phrase('A confirmed registration for this Course Offering is required.'));
                }
                return redirect()->back()->with('error', get_phrase('Joining is not available for this meeting right now.'));
            }
            if (! $liveClass->safe_meeting_url) {
                return redirect()->back()->with('error', get_phrase('The meeting provider link is unavailable.'));
            }
        } else {
            $this->authorize('join', $liveClass);

            if ((int) Auth::user()->role_id === 7 && !$this->canStudentAccessClass($liveClass)) {
                return redirect()->back()->with('error', get_phrase('You are not authorized for this class'));
            }
        }

        $offeringBacked = $access->isOfferingBacked($liveClass);
        if (($offeringBacked && $access->withinJoinWindow($liveClass)) || (!$offeringBacked && $liveClass->shouldAllowJoin())) {
            // Authoritative evidence that somebody actually opened the classroom.
            // Written once, on the first entry, and only by whoever entered - so
            // the record distinguishes "the meeting was opened" from "the
            // scheduled time passed", which is the whole point of the column.
            // It is participation telemetry only: it NEVER writes official Course
            // Offering Attendance, which stays lecturer-finalised.
            $this->recordClassroomOpened($liveClass);

            $attendance = $this->recordJoin($liveClass);

            if ($this->shouldRenderEmbeddedMeeting($liveClass)) {
                // PIIE authority and PROVIDER authority are different things, and
                // only the first is something this app controls.
                //
                // Being authorised in PIIE says who may open the room. It does
                // NOT make anybody a moderator in Jitsi: on a public meet.jit.si
                // room with no JWT secret configured, Jitsi authenticates nobody
                // and grants moderator rights to no one, no matter what PIIE
                // believes. Claiming otherwise would tell a lecturer they control
                // a room they cannot moderate, and would leave them blamed for a
                // room full of students they cannot actually silence.
                //
                // So the flag means exactly one thing: "PIIE can prove, to the
                // provider, that this person is a moderator." That requires an
                // authorised host AND a real signed token.
                $piiAuthorisedHost = $offeringBacked
                    ? ($access->canLecturerHost(Auth::user(), $liveClass) || $access->canTenantAdmin(Auth::user(), $liveClass, 'live_classes.manage_all'))
                    : Auth::user()->can('update', $liveClass);
                $jitsiConfigured = JitsiTokenService::isConfigured();
                $isModerator = $piiAuthorisedHost && $jitsiConfigured;
                $jitsiJwt = JitsiTokenService::generate($liveClass, Auth::user(), $isModerator);
                $meetingUrl = $liveClass->safe_meeting_url;

                return view('admin.live_class.meeting_room', [
                    'liveClass' => $liveClass,
                    'meetingUrl' => $meetingUrl,
                    'attendanceId' => $attendance?->id,
                    'isModerator' => $isModerator,
                    // Kept separately so the room can say the truthful thing:
                    // "you may host here" and "you are a Jitsi moderator" are
                    // not the same statement.
                    'piiAuthorisedHost' => $piiAuthorisedHost,
                    'jitsiJwt' => $jitsiJwt,
                    'jitsiConfigured' => $jitsiConfigured,
                    'displayName' => Auth::user()->name,
                    // Everything after the domain: just the room slug on
                    // plain meet.jit.si, "{appId}/{room}" on 8x8 JaaS — the
                    // IFrame API's `roomName` option needs the full path,
                    // unlike the JWT's `room` claim (see JitsiTokenService).
                    'jitsiDomain' => parse_url($meetingUrl, PHP_URL_HOST),
                    'jitsiRoomPath' => trim((string) parse_url($meetingUrl, PHP_URL_PATH), '/'),
                ]);
            }

            if ($liveClass->platform === 'google_meet') {
                // Google Meet events are owned by WHATEVER Google account created
                // them, and there are genuinely two possibilities:
                //
                //  - the scheduling lecturer's OWN connected account, now the
                //    required path for a lecturer scheduling against a Course
                //    Offering. A Calendar event id is recorded for it.
                //  - the installation-wide credential
                //    (services.google_meet.refresh_token — see
                //    createGoogleMeetUrl()), which returns a bare hangoutLink
                //    and records no event id, and which still serves an
                //    administrator scheduling on someone's behalf.
                //
                // The view tells them apart using that same discriminator.
                // Whoever opens the link while signed into a *different* Google
                // account than the owner is not recognised as host and lands on
                // Meet's "Ask to join" knock screen instead of being let
                // straight in — surface that before sending them away, since
                // Meet's own UI gives no hint why.
                return view('admin.live_class.google_meet_join', [
                    'liveClass' => $liveClass,
                    'meetingUrl' => $liveClass->safe_meeting_url,
                ]);
            }

            return redirect()->away($liveClass->safe_meeting_url);
        }

        if (!$offeringBacked && $liveClass->computed_status === LiveClass::STATUS_ENDED && $liveClass->safe_recording_url) {
            return redirect()->away($liveClass->safe_recording_url);
        }

        return redirect()->back()->with('error', get_phrase('Joining is not available for this class right now'));
    }

    /**
     * Note, once, that somebody opened this classroom.
     *
     * Failures here are swallowed on purpose. This is supporting evidence, not
     * the action the user asked for: they asked to join a meeting, and a
     * bookkeeping hiccup must not deny them the room. The join itself has
     * already been authorised and must proceed either way.
     */
    private function recordClassroomOpened(LiveClass $liveClass): void
    {
        try {
            if ($liveClass->hasStartEvidence()) {
                return;
            }

            $liveClass->forceFill([
                'started_at' => now(),
                'started_by' => Auth::id(),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * One attendance row per click of Join, for students only — a lecturer
     * opening their own class isn't "attending" it. Only recorded when the
     * class has attendance tracking switched on (attendance_enabled), so
     * classes nobody asked to track don't accumulate rows regardless.
     *
     * This table is PARTICIPATION TELEMETRY, not academic attendance. It is
     * offered to a lecturer as evidence beside a Course Offering Attendance
     * session and never written to one: official attendance remains a separate,
     * lecturer-finalised record.
     */
    private function recordJoin(LiveClass $liveClass): ?LiveClassAttendance
    {
        if (!$liveClass->attendance_enabled || (int) Auth::user()->role_id !== 7) {
            return null;
        }

        return LiveClassAttendance::create([
            'school_id' => $liveClass->school_id,
            'live_class_id' => $liveClass->id,
            'user_id' => Auth::id(),
            'role_id' => Auth::user()->role_id,
            'joined_at' => now(),
        ]);
    }

    /**
     * Beacon fired by the embedded Jitsi room on page unload/close (see
     * meeting_room.blade.php). This is the only platform where "left" is
     * knowable at all — Zoom/Google Meet/BigBlueButton open away from this
     * app, so there is nothing here to hear a departure from.
     */
    public function attendanceLeave(Request $request, LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);

        $attendanceId = $request->input('attendance_id');

        $attendance = LiveClassAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', Auth::id())
            ->when($attendanceId, fn ($q) => $q->where('id', $attendanceId))
            ->whereNull('left_at')
            ->latest('id')
            ->first();

        if ($attendance) {
            $leftAt = now();
            $attendance->update([
                'left_at' => $leftAt,
                'duration_seconds' => max(0, \App\Support\Compatibility\WholeDateIntervals::seconds($attendance->joined_at, $leftAt)),
            ]);
        }

        // sendBeacon expects a fast, body-less response; nothing downstream
        // reads this.
        return response()->noContent();
    }

    /**
     * Who joined, when, and — where knowable — for how long. Reachable only
     * by staff who could otherwise manage the class (reuses the 'update'
     * ability rather than adding a new one purely for viewing this report).
     */
    public function attendance(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        $records = $liveClass->attendances()->with('user')->orderBy('joined_at')->get();

        return view('admin.live_class.attendance', [
            'liveClass' => $liveClass,
            'records' => $records,
        ]);
    }

    public function attendanceExport(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeClassManage($liveClass);

        $records = $liveClass->attendances()->with('user')->orderBy('joined_at')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="attendance_' . $liveClass->id . '_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Name', 'Email', 'Joined At', 'Left At', 'Duration (minutes)']);
            $records->each(function ($record, $i) use ($out) {
                fputcsv($out, [
                    $i + 1,
                    optional($record->user)->name,
                    optional($record->user)->email,
                    $record->joined_at?->format('Y-m-d H:i:s'),
                    $record->left_at?->format('Y-m-d H:i:s') ?: 'Unknown',
                    $record->duration_seconds !== null ? round($record->duration_seconds / 60, 1) : 'Unknown',
                ]);
            });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Class materials ──────────────────────────────────────────────────

    /**
     * Materials list, rendered as a modal partial — reachable by staff who
     * can manage the class (upload form included) and by anyone who can
     * view it (see LiveClassPolicy::view, which already lets a student see
     * a published class and blocks an unpublished one). Deliberately not
     * time-gated to the class window: reviewing slides after the session is
     * exactly when students want them.
     */
    public function materials(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $this->authorizeClassView($liveClass);

        $allMaterials = $liveClass->materials()->orderByDesc('id')->get();
        $resources = $allMaterials->where('category', LiveClassMaterial::CATEGORY_RESOURCE)->values();
        $recordings = $allMaterials->where('category', LiveClassMaterial::CATEGORY_RECORDING)->values();

        // The authoritative recording state, and the primary recording when it is
        // genuinely available.
        //
        // This is why a PROCESSING class must not be listed as a recording: the
        // drawer is the place a student looks for something they can watch, and
        // an entry there with no playable link behind it reads as a broken
        // release rather than as "not ready yet". A primary recording only joins
        // the list when the state says available AND there is a usable https URL.
        $recordingState = $liveClass->recordingState();
        $primaryRecordingAvailable = $recordingState === LiveClass::RECORDING_AVAILABLE
            && $liveClass->course_offering_id !== null
            && $liveClass->safe_recording_url !== null;
        $canManage = $access->isOfferingBacked($liveClass)
            ? ($access->canLecturerManageMaterials(Auth::user(), $liveClass) || $access->canTenantAdminManage(Auth::user(), $liveClass))
            : Auth::user()->can('update', $liveClass);

        return view('admin.live_class.materials', compact(
            'liveClass',
            'resources',
            'recordings',
            'canManage',
            'recordingState',
            'primaryRecordingAvailable'
        ));
    }

    /** Render the small, Offering-contextual creation entry point. */
    public function createForOffering(Request $request, int $courseOffering)
    {
        $offering = $this->tenantOfferingOrFail($courseOffering);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $actor = $request->user();
        $isAdmin = $access->canAdminCreateForOffering($actor, $offering);
        abort_unless($isAdmin || $access->canLecturerCreateForOffering($actor, $offering), 403);

        $allocations = $access->activeManagerAllocationsForOffering($offering);
        abort_if($allocations->isEmpty(), 403, get_phrase('This Offering has no current Primary or Co Lecturer facilitator allocation.'));
        $facilitators = $allocations->pluck('lecturer')->unique('id')->values();
        if (! $isAdmin) {
            $facilitators = $facilitators->where('id', $actor->id)->values();
            abort_if($facilitators->isEmpty(), 403);
        }

        $scheduling = app(\App\Support\LiveClasses\LiveClassSchedulingContext::class);

        $liveClass = new LiveClass([
            'platform' => $scheduling->defaultPlatform($actor),
            'status' => LiveClass::STATUS_DRAFT,
            // The institution's own configured timezone, never a hardcoded one.
            'timezone' => $scheduling->timezone($actor),
            'is_published' => false,
            'course_offering_id' => $offering->id,
        ]);

        // Only offer "Change Course Offering" when there is somewhere to change
        // to. With a single manageable Offering the link is just noise.
        $otherManageableOfferings = $scheduling->isHigherEducation($actor)
            ? $scheduling->manageableOfferings($actor)->where('id', '!=', $offering->id)->count()
            : 0;

        // The academic context shown on the form is derived by the same code
        // that decorates "My Course Offerings", so the two can never disagree.
        $contextOffering = $scheduling->offeringContext($actor, $offering);

        // Prefer the derived count; fall back to a direct read only when the
        // registrations table is actually present.
        $registeredCount = $scheduling->confirmedCount($contextOffering);
        if ($registeredCount === 0 && \Illuminate\Support\Facades\Schema::hasTable('course_registrations')) {
            $registeredCount = \App\Models\CourseRegistration::query()
                ->where('school_id', $offering->school_id)
                ->where('course_offering_id', $offering->id)
                ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
                ->count();
        }

        return view('admin.live_class.offering_create', [
            'offering' => $contextOffering,
            'facilitators' => $facilitators,
            'isAdmin' => $isAdmin,
            'liveClass' => $liveClass,
            'platformStatus' => $this->platformConfigurationStatus(),
            'scheduling' => $scheduling,
            'platformOptions' => $scheduling->platformOptions($actor),
            'institutionTimezone' => $scheduling->timezone($actor),
            'institutionTimezoneLabel' => $scheduling->timezoneLabel($actor),
            'hasConfiguredTimezone' => $scheduling->hasConfiguredTimezone($actor),
            // Which clock this lecturer reads and types in, and what the
            // institution's clock reads for the same instant.
            'timezones' => $scheduling->timezoneDescription($actor),
            'inputTimezone' => $scheduling->effectiveTimezone($actor),
            'otherManageableOfferings' => $otherManageableOfferings,
            'registeredCount' => $registeredCount,
            // The same Google connection facts the Live Classes index passes, so
            // the ONE existing partial can be reused here unchanged instead of a
            // second, divergent copy of the connect control. `googleConnection` is
            // always the signed-in user's OWN row and is null for everyone else,
            // so this cannot surface another lecturer's grant.
            'googleConnection' => app(GoogleAccountService::class)->forUser($actor),
            'googleConfigured' => GoogleOAuthCredentials::isConfigured(),
            // Offering context survives the OAuth round trip: a lecturer who
            // connects from this form is returned to THIS form for THIS Offering
            // rather than being dropped onto the generic Live Classes list.
            //
            // `route()` yields an ABSOLUTE url (scheme + host), and the return path
            // is deliberately restricted to a same-site PATH — that restriction is
            // the open-redirect defence and must not be weakened to accommodate
            // this convenience. So the path is taken out of the generated route
            // rather than passing the whole url through.
            'googleReturnPath' => (string) parse_url(
                route($this->getRoutePrefix($request).'.course_offerings.live_classes.create', $contextOffering->id),
                PHP_URL_PATH
            ),
            'programmeNames' => $scheduling->programmes($contextOffering),
            'stageNames' => $scheduling->stages($contextOffering),
            'studyPlanVersions' => $scheduling->studyPlans($contextOffering),
            'roleLabel' => $scheduling->roleLabel($contextOffering),
        ]);
    }

    /** The route supplies the Offering; no academic/tenant identity comes from the form. */
    public function storeForOffering(StoreOfferingLiveClassRequest $request, int $courseOffering)
    {
        foreach ([
            'school_id', 'course_offering_id', 'subject_id', 'programme_id', 'academic_session_id',
            'academic_year_id', 'academic_period_id', 'created_by', 'updated_by',
        ] as $reserved) {
            if ($request->exists($reserved)) {
                throw ValidationException::withMessages([
                    $reserved => get_phrase('Offering context is derived by PIIE and cannot be submitted.'),
                ]);
            }
        }

        $offering = $this->tenantOfferingOrFail($courseOffering);
        $actor = $request->user();
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $isAdmin = $access->canAdminCreateForOffering($actor, $offering);
        $lecturerAllowed = $access->canLecturerCreateForOffering($actor, $offering);
        abort_unless($isAdmin || $lecturerAllowed, 403);

        $validated = $request->validated();

        // The typed date/time is interpreted in the SCHEDULER'S own clock - a
        // lecturer in London typing 08:00 means 08:00 London - and normalised
        // to UTC for storage, exactly as before. The submitted `timezone` is
        // deliberately NOT trusted: it is a presentation field, and letting a
        // client choose the zone its own input is read in would let a typo
        // silently move a class by whole hours. The institution's own official
        // timezone is used when a tenant administrator schedules on a
        // lecturer's behalf, because that is an institutional act.
        $scheduling = app(\App\Support\LiveClasses\LiveClassSchedulingContext::class);
        $timezone = $isAdmin
            ? $scheduling->timezone($actor)
            : $scheduling->effectiveTimezone($actor);
        $scheduledAt = Carbon::parse($validated['start_date'].' '.$validated['start_time'], $timezone);
        $endsAt = Carbon::parse($validated['start_date'].' '.$validated['end_time'], $timezone);
        $allocationsForMeeting = $access->activeManagerAllocationsForOffering($offering, $scheduledAt);
        $facilitatorIds = $allocationsForMeeting->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        if (! $isAdmin && array_key_exists('teacher_id', $validated)
            && (int) $validated['teacher_id'] !== (int) $actor->id) {
            throw ValidationException::withMessages(['teacher_id' => get_phrase('Lecturers may only facilitate their own Offering-backed class.')]);
        }
        $facilitatorId = $isAdmin
            ? (int) ($validated['teacher_id'] ?? (count($facilitatorIds) === 1 ? $facilitatorIds[0] : 0))
            : (int) $actor->id;
        if (! in_array($facilitatorId, $facilitatorIds, true)) {
            throw ValidationException::withMessages(['teacher_id' => get_phrase('Choose a current Primary or Co Lecturer allocated to this exact Offering and meeting date.')]);
        }

        $platform = $validated['platform'];
        if (! in_array($platform, $this->getEnabledPlatforms(), true)) {
            throw ValidationException::withMessages(['platform' => get_phrase('Selected platform is disabled by administrator settings.')]);
        }

        $meetingUrl = $validated['meeting_url'] ?? null;
        $googleEventId = null;
        $googleConferenceStatus = null;

        if (empty($meetingUrl) && in_array($platform, ['jitsi', 'zoom', 'google_meet'], true)) {
            // The FULL resolution, not just the URL — same call the non-Offering
            // path makes. This route previously used resolveMeetingUrl(), which
            // returns only a string, so a Google event created here recorded
            // neither its Calendar event id nor its conference state. The result
            // was an orphaned conference: PIIE held a join link it could no
            // longer reach the calendar entry behind, so cancelling the class
            // left the Meet link live on the lecturer's own calendar while PIIE
            // reported the class as cancelled.
            $resolution = $this->resolveMeeting(
                $platform,
                $validated['title'],
                $scheduledAt,
                $endsAt,
                $timezone,
                null,
                $validated['description'] ?? null,
                // A lecturer scheduling against a Course Offering gets the
                // conference on their OWN calendar or not at all. The
                // installation-wide credential is never substituted for a missing
                // personal connection here.
                true
            );

            $meetingUrl = $resolution->url !== '' ? $resolution->url : null;
            $googleEventId = $resolution->eventId;
            $googleConferenceStatus = $resolution->conferenceStatus;
        }

        // A Google conference that has not materialised yet is a real, scheduled
        // class, not a failure. The event exists and carries the id needed to fetch
        // the link later, so the class is saved and the card reads "Link not ready
        // yet". Rejecting it here — as the empty-URL check below did — would tell
        // a lecturer that Google had failed when it had in fact succeeded.
        $isGooglePending = $googleConferenceStatus === GoogleConferenceStatus::PENDING;

        if (empty($meetingUrl) && ! $isGooglePending) {
            throw ValidationException::withMessages(['meeting_url' => get_phrase('Enter a secure HTTPS provider URL for this platform.')]);
        }

        // A lecturer chooses an ACTION, not a lifecycle state. "Save as Draft"
        // and "Schedule & Notify Students" are the two real intentions, and the
        // system derives is_published/status from them. The previous form asked
        // the lecturer to pick an internal state (and pre-filled a dropdown
        // that could read "Live" the moment the form opened), which is how a
        // future class came to look live before it was ever scheduled.
        $action = $validated['action'] ?? null;
        if ($action === null) {
            // Existing admin API / integration callers still post is_published.
            // Kept so the tightened form contract does not break them, and only
            // when no action was supplied - the lecturer form always sends one.
            $action = ! empty($validated['is_published']) ? 'publish' : 'draft';
        }
        $publish = $scheduling->isPublishAction($action);
        $published = $publish;
        $status = $this->deriveStatus($scheduling->statusForAction($action), $scheduledAt, $endsAt, $publish);

        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'teacher_id' => $facilitatorId,
            'platform' => $platform,
            'meeting_url' => $meetingUrl,
            // Provider-internal identifiers are generated by the provider or
            // unused for the auto-created platforms. The lecturer form no
            // longer offers them, so anything posted here is ignored.
            'meeting_id' => null,
            'meeting_password' => null,
            'scheduled_at' => $scheduledAt->timezone('UTC')->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->timezone('UTC')->format('Y-m-d H:i:s'),
            'start_date' => $validated['start_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'timezone' => $timezone,
            'status' => $status,
            'is_published' => $published,
            // Participation evidence is a K12 habit. For an Offering-backed
            // class it stays off, because official Attendance is the certified
            // Course Offering Attendance workflow and is always
            // lecturer-controlled - joining must never mark anyone present.
            'attendance_enabled' => false,
            // A recording is an AFTER-class action, not something known at
            // scheduling time. Publishing one notifies registered students
            // through the recording event.
            'recording_url' => null,
        ];

        /**
         * Google Calendar bookkeeping, added ONLY when there is something to record.
         *
         * Identical reasoning, and identical conditional shape, to the non-Offering
         * path in buildPayload(): writing an explicit NULL names a column that some
         * of this application's test fixtures do not define, and a payload naming an
         * absent column is a hard SQL error rather than a skipped write. Omitting the
         * key lets those fixtures keep working, and in production the column takes
         * its own default of NULL — which is the value we wanted anyway.
         *
         * Both keys travel together. An event id with no conference status, or a
         * status with no event, each describe half a Google class.
         */
        if ($googleEventId !== null || $googleConferenceStatus !== null) {
            $attributes['google_calendar_event_id'] = $googleEventId;
            $attributes['google_conference_status'] = $googleConferenceStatus;
        }

        try {
            $liveClass = app(\App\Support\LiveClasses\LiveClassService::class)
                ->createForOffering($actor, (int) $offering->id, $attributes);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['live_class' => get_phrase($exception->getMessage())]);
        }

        $notified = 0;
        if ($published || in_array($status, [LiveClass::STATUS_SCHEDULED, LiveClass::STATUS_LIVE], true)) {
            // Returns how many confirmed students were actually told, so the
            // lecturer gets a useful confirmation rather than a bare "saved".
            $notified = $this->createStudentLiveClassNotice($liveClass, $published ? 'published' : 'scheduled');
        }
        $successMessage = $published
            ? $this->announcedCount($liveClass, $notified)
            : get_phrase('Live Class saved as a draft. Publish it when you are ready for students to see it.');

        $routePrefix = $this->getRoutePrefix($request);
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'success',
                'message' => $successMessage,
                'redirect' => route($routePrefix.'.live_classes.show', $liveClass->id),
            ]);
        }

        return redirect()->route($routePrefix.'.live_classes.show', $liveClass->id)
            ->with('success', $successMessage);
    }

    private function tenantOfferingOrFail(int $offeringId): CourseOffering
    {
        return CourseOffering::query()->where('school_id', (int) Auth::user()->school_id)->findOrFail($offeringId);
    }

    /** Authorized delivery boundary for file and external-link materials. */
    public function accessMaterial(LiveClass $liveClass, int $material)
    {
        $class = LiveClass::query()->where('school_id', $this->school_id)->whereKey($liveClass->id)->firstOrFail();
        $this->authorizeMaterialAccess($class, false);
        $item = $class->materials()->whereKey($material)->firstOrFail();
        if ($item->isRecording()) {
            $this->authorizeMaterialAccess($class, false, true);
        }

        if (! $item->isFile()) {
            $url = filter_var($item->link_url, FILTER_VALIDATE_URL) ? $item->link_url : null;
            abort_unless($url && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https', 404);
            return redirect()->away($url);
        }

        if ($class->course_offering_id !== null) {
            $path = app(\App\Support\LiveClasses\LiveClassAssetStorage::class)
                ->resolvePrivatePath((string) $item->stored_name, $class, (string) $item->category);
            abort_unless($path, 404, get_phrase('This protected material is unavailable.'));
            $name = basename((string) ($item->original_name ?: $item->title));
            return response()->download($path, $name, [
                'Content-Type' => $item->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        abort_unless($item->absolute_path && is_file($item->absolute_path), 404, get_phrase('This material is unavailable.'));
        return response()->download($item->absolute_path, basename((string) ($item->original_name ?: $item->title)));
    }

    /** Authorize external HEI recording discovery before redirecting. */
    public function accessRecording(LiveClass $liveClass)
    {
        $class = LiveClass::query()->where('school_id', $this->school_id)->whereKey($liveClass->id)->firstOrFail();
        abort_unless($class->course_offering_id !== null, 404);
        $this->authorizeMaterialAccess($class, false, true);
        $url = trim((string) $class->recording_url);
        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https', 404);
        return redirect()->away($url);
    }

    private function authorizeMaterialAccess(LiveClass $liveClass, bool $manage, bool $recording = false): void
    {
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        if ($liveClass->course_offering_id === null) {
            $this->authorize($manage ? 'update' : 'view', $liveClass);
            return;
        }

        $user = Auth::user();
        $allowed = match (true) {
            (int) $user->role_id === 7 => ! $manage && ($recording
                ? $access->canStudentViewRecording($user, $liveClass)
                : $access->canStudentViewMaterials($user, $liveClass)),
            in_array((int) $user->role_id, [1, 2], true) => $manage
                ? $access->canTenantAdminManage($user, $liveClass)
                : $access->canTenantAdmin($user, $liveClass, 'live_classes.view'),
            $manage => $access->canLecturerManageMaterials($user, $liveClass),
            default => $access->canLecturerView($user, $liveClass),
        };
        abort_unless($allowed, 403, get_phrase('You are not authorized to access this Live Class asset.'));
    }

    private function authorizeClassView(LiveClass $liveClass): void
    {
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        if (! $access->isOfferingBacked($liveClass)) {
            $this->authorize('view', $liveClass);
            return;
        }

        $user = Auth::user();
        $allowed = (int) $user->role_id === 7
            ? $access->canStudentViewClass($user, $liveClass)
            : ($access->canTenantAdmin($user, $liveClass, 'live_classes.view')
                || $access->canLecturerView($user, $liveClass));
        abort_unless($allowed, 403, get_phrase('You are not authorized to view this Live Class.'));
    }

    private function authorizeClassManage(LiveClass $liveClass): void
    {
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        if (! $access->isOfferingBacked($liveClass)) {
            $this->authorize('update', $liveClass);
            return;
        }

        $user = Auth::user();
        $allowed = $access->canTenantAdminManage($user, $liveClass)
            || $access->canLecturerManage($user, $liveClass);
        abort_unless($allowed, 403, get_phrase('You are not authorized to manage this Live Class.'));
    }

    public function storeMaterial(Request $request, LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $this->authorizeMaterialAccess($liveClass, true);

        $category = $request->input('category', LiveClassMaterial::CATEGORY_RESOURCE);
        if (!in_array($category, [LiveClassMaterial::CATEGORY_RESOURCE, LiveClassMaterial::CATEGORY_RECORDING], true)) {
            $category = LiveClassMaterial::CATEGORY_RESOURCE;
        }

        $isRecording = $category === LiveClassMaterial::CATEGORY_RECORDING;
        $allowedExtensions = $isRecording ? LiveClassMaterial::ALLOWED_RECORDING_EXTENSIONS : LiveClassMaterial::ALLOWED_EXTENSIONS;
        $maxKb = ($isRecording ? LiveClassMaterial::MAX_RECORDING_MB : LiveClassMaterial::MAX_FILE_MB) * 1024;

        $validated = $request->validate([
            'type' => ['required', 'in:file,link'],
            'title' => ['required', 'string', 'max:200'],
            'file' => [
                'required_if:type,file', 'nullable', 'file',
                'mimes:' . implode(',', $allowedExtensions),
                'max:' . $maxKb,
            ],
            'link_url' => ['required_if:type,link', 'nullable', 'url', 'starts_with:https://', 'max:500'],
        ]);

        $payload = [
            'school_id' => $this->school_id,
            'live_class_id' => $liveClass->id,
            'type' => $validated['type'],
            'category' => $category,
            'title' => $validated['title'],
            'uploaded_by' => Auth::id(),
        ];

        if ($validated['type'] === 'file') {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            abort_unless(in_array($extension, $allowedExtensions, true), 422, 'This file type is not allowed.');
            $payload += [
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
            ];

            if ($liveClass->course_offering_id !== null) {
                $storage = app(\App\Support\LiveClasses\LiveClassAssetStorage::class);
                $detectedMime = $storage->detectedMime($file, $extension);
                if (! $detectedMime) {
                    throw ValidationException::withMessages(['file' => 'The uploaded file content does not match an approved file type.']);
                }
                $payload['mime_type'] = $detectedMime;
                $key = $storage->store($file, $liveClass, $category, $extension);
                $payload['stored_name'] = $key;
                try {
                    DB::transaction(fn () => LiveClassMaterial::create($payload));
                } catch (\Throwable $exception) {
                    $storage->deletePrivate($key, $liveClass, $category);
                    throw $exception;
                }
            } else {
                $destination = public_path($isRecording ? LiveClassMaterial::RECORDING_UPLOAD_DIR : LiveClassMaterial::UPLOAD_DIR);
                if (!is_dir($destination)) mkdir($destination, 0755, true);
                $storedAs = ($isRecording ? 'lcr' : 'lcm') . $liveClass->id . '_' . uniqid() . '.' . $extension;
                $payload['stored_name'] = $storedAs;
                $file->move($destination, $storedAs);
                try {
                    DB::transaction(fn () => LiveClassMaterial::create($payload));
                } catch (\Throwable $exception) {
                    $path = $destination.DIRECTORY_SEPARATOR.$storedAs;
                    if (is_file($path)) @unlink($path);
                    throw $exception;
                }
            }
        } else {
            $payload['link_url'] = $validated['link_url'];
            LiveClassMaterial::create($payload);
        }

        AuditLog::record('create', 'Live Classes', ($isRecording ? 'Recording' : 'Material') . " added to live class: {$liveClass->title}");

        return redirect()->back()->with('success', get_phrase($isRecording ? 'Recording added' : 'Material added'));
    }

    public function destroyMaterial(LiveClassMaterial $material)
    {
        $liveClass = LiveClass::query()->where('school_id', $this->school_id)->whereKey($material->live_class_id)->firstOrFail();
        $material = $liveClass->materials()->whereKey($material->id)->firstOrFail();
        $this->authorizeMaterialAccess($liveClass, true);

        if ($material->isFile()) {
            if ($liveClass->course_offering_id !== null) {
                $deleted = app(\App\Support\LiveClasses\LiveClassAssetStorage::class)
                    ->deletePrivate((string) $material->stored_name, $liveClass, (string) $material->category);
                abort_unless($deleted, 404, get_phrase('This protected material is unavailable.'));
            } elseif ($material->absolute_path && is_file($material->absolute_path)) {
                @unlink($material->absolute_path);
            }
        }

        $material->delete();

        AuditLog::record('delete', 'Live Classes', "Material removed from live class: {$liveClass->title}");

        return redirect()->back()->with('success', get_phrase('Material removed'));
    }

    private function shouldRenderEmbeddedMeeting(LiveClass $liveClass): bool
    {
        if ($liveClass->platform !== 'jitsi') {
            return false;
        }

        $meetingUrl = $liveClass->safe_meeting_url;
        if (empty($meetingUrl)) {
            return false;
        }

        $meetingHost = parse_url($meetingUrl, PHP_URL_HOST);
        if (empty($meetingHost)) {
            return false;
        }

        $base = rtrim((string) get_settings('live_class_jitsi_base_url'), '/');
        if ($base === '') {
            $base = 'https://meet.jit.si';
        }

        $configuredHost = parse_url($base, PHP_URL_HOST);
        if (empty($configuredHost)) {
            $configuredHost = 'meet.jit.si';
        }

        if (strcasecmp($meetingHost, $configuredHost) !== 0) {
            return false;
        }

        // meet.jit.si (Jitsi's free public server) refuses to stay embedded
        // past 5 minutes ("only meant for demo purposes") and never
        // validates our JWT, so embedding it gains nothing and actively
        // breaks longer classes — send it to its own tab instead, same as
        // Zoom/Google Meet. Only a real configured domain (self-hosted or
        // 8x8 JaaS) gets the in-app embed.
        if (strcasecmp($meetingHost, 'meet.jit.si') === 0) {
            return false;
        }

        return true;
    }

    /**
     * The student's read-only Live Class page.
     *
     * Separates two different intentions that were previously conflated:
     *
     *  - VIEW  - "tell me about this class". Governed by publication and, for an
     *            Offering-backed class, a confirmed registration. A student can
     *            read a scheduled class long before it starts.
     *  - JOIN  - "put me in the meeting". Governed additionally by the join
     *            window, and it is where the provider link is disclosed.
     *
     * A notification's "View Live Class" action lands here, never on /join.
     * Nothing provider-internal is rendered: the platform is named, not linked,
     * and the meeting link is reached only through the authorised join action.
     */
    public function studentShow(LiveClass $liveClass)
    {
        abort_unless((int) $liveClass->school_id === (int) $this->school_id, 404);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $user = Auth::user();

        $isStudent = (int) $user->role_id === 7;
        $canView = $access->isOfferingBacked($liveClass)
            ? ($isStudent
                ? $access->canStudentViewClass($user, $liveClass)
                : ($access->canLecturerView($user, $liveClass) || $access->canTenantAdmin($user, $liveClass)))
            : Auth::user()->can('view', $liveClass);

        // An unpublished class must not be confirmable by guessing an id.
        abort_unless($canView, 404);

        $liveClass->loadMissing(['subject', 'teacher', 'courseOffering.academicYear', 'courseOffering.academicPeriod']);

        $resolver = app(\App\Support\LiveClasses\LiveClassLifecycle::class);
        $state = $resolver->for($liveClass, $user);

        return view('admin.live_class.student_show', [
            'liveClass' => $liveClass,
            'isOfferingBacked' => $access->isOfferingBacked($liveClass),
            'lifecycle' => $state,
            'canJoin' => $state['canJoin'],
            'joinMessage' => $resolver->studentMessage($state, $liveClass),
            'countdown' => $resolver->countdown($state),
              // Who cancelled it, and what the provider can genuinely do, so a
              // retained cancelled page states the facts instead of showing a
              // status badge with nothing to read.
              'cancelledByName' => $liveClass->cancelled_by
                  ? \App\Models\User::query()->where('school_id', $liveClass->school_id)->whereKey($liveClass->cancelled_by)->value('name')
                  : null,
              'platform' => app(\App\Support\LiveClasses\LiveClassPlatform::class)
                  ->describe($liveClass, (bool) $state['canHost']),            'registeredCount' => $liveClass->course_offering_id && \Illuminate\Support\Facades\Schema::hasTable('course_registrations')
                ? \App\Models\CourseRegistration::query()
                    ->where('school_id', $liveClass->school_id)
                    ->where('course_offering_id', $liveClass->course_offering_id)
                    ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
                    ->count()
                : null,
        ]);
    }

    public function studentIndex(Request $request)
    {
        $this->authorize('viewAny', LiveClass::class);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        $school_id = Auth::user()->school_id;
        $enroll = \App\Models\Enrollment::where('user_id', Auth::id())
            ->where('school_id', $school_id)
            ->first();
        $class_id = $enroll?->class_id;

        $search = trim((string) $request->input('search', ''));
        $subjectId = $request->input('subject_id');
        $platform = $request->input('platform');
        $status = $request->input('status');
        $date = $request->input('date');
        $view = $request->input('view', $status ? 'all' : 'upcoming');

        $classes = LiveClass::where('school_id', $school_id)
            ->published()
            ->where('status', '!=', LiveClass::STATUS_CANCELLED)
            ->where(function ($scope) use ($class_id, $enroll, $school_id, $access) {
                $scope->where(function ($legacy) use ($class_id, $enroll, $school_id) {
                    $legacy->whereNull('course_offering_id')
                        ->where(function ($q) use ($class_id) { $q->whereNull('class_id')->orWhere('class_id', $class_id); })
                        ->where(function ($q) use ($enroll) { $q->whereNull('academic_session_id'); if (!empty($enroll?->session_id)) $q->orWhere('academic_session_id', $enroll->session_id); })
                        ->where(function ($q) use ($class_id, $school_id) { $q->whereNull('subject_id')->orWhereHas('subject', function ($sub) use ($class_id, $school_id) { $sub->where('school_id', $school_id)->where(function ($c) use ($class_id) { $c->whereNull('class_id'); if ($class_id) $c->orWhere('class_id', $class_id); }); }); });
                })->orWhere(function ($offering) use ($access, $school_id) {
                    $offering->whereIn('course_offering_id', $access->confirmedOfferingIdsQuery(Auth::user(), (int) $school_id))
                        ->where(function ($lifecycle) {
                            $lifecycle->whereHas('courseOffering', fn ($query) => $query->whereIn('status', [
                                \App\Models\CourseOffering::STATUS_OPEN,
                                \App\Models\CourseOffering::STATUS_IN_PROGRESS,
                            ]))->orWhere(function ($historical) {
                                $historical->where(function ($classState) {
                                    $classState->where('status', LiveClass::STATUS_ENDED)
                                        ->orWhere(function ($elapsed) {
                                            $elapsed->whereNotNull('ends_at')->where('ends_at', '<=', now());
                                        });
                                })->whereHas('courseOffering', fn ($query) => $query->where('status', \App\Models\CourseOffering::STATUS_COMPLETED));
                            });
                        });
                });
            })
            ->when($search !== '', fn($q) => $q->where('title', 'like', "%{$search}%"))
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->when($platform, fn($q) => $q->where('platform', $platform))
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($date, fn($q) => $q->whereDate('start_date', $date))
            ->when(!$status, fn($q) => $this->applyQuickView($q, $view))
            ->with(['subject', 'teacher'])
            ->orderByDesc('start_date')
            ->orderByDesc('start_time')
            ->paginate(18);

        $subjects = Subject::where('school_id', $school_id)->orderBy('name')->get();

        return view('student.live_class.index', compact('classes', 'subjects', 'search', 'subjectId', 'platform', 'status', 'date', 'view'));
    }

    private function buildPayload(array $validated, ?LiveClass $existing): array
    {
        $enabledPlatforms = $this->getEnabledPlatforms();
        if (!in_array($validated['platform'], $enabledPlatforms, true)) {
            throw ValidationException::withMessages([
                'platform' => get_phrase('Selected platform is disabled by administrator settings.'),
            ]);
        }

        $scheduledAt = Carbon::parse($validated['start_date'] . ' ' . $validated['start_time'], $validated['timezone'] ?? config('app.timezone', 'UTC'));
        $endsAt = Carbon::parse($validated['start_date'] . ' ' . $validated['end_time'], $validated['timezone'] ?? config('app.timezone', 'UTC'));

        $meetingUrl = $validated['meeting_url'] ?? ($existing?->meeting_url ?? null);
        $googleEventId = $existing?->google_calendar_event_id;
        $googleConferenceStatus = $existing?->google_conference_status;

        if (empty($meetingUrl)) {
            $platform = $validated['platform'] ?? 'jitsi';

            // The full resolution, not just the URL: a Google event's id is the
            // only handle that lets a later edit or cancel reach the calendar entry
            // and revoke the Meet link. Discarding it here would leave an orphan
            // conference — and a join link — alive on the lecturer's real calendar
            // after PIIE believes the class is cancelled.
            $resolution = $this->resolveMeeting(
                $platform,
                $validated['title'] ?? 'class',
                $scheduledAt,
                $endsAt,
                $validated['timezone'] ?? config('app.timezone', 'UTC'),
                $existing,
                $validated['description'] ?? null
            );

            $meetingUrl = $resolution->url !== '' ? $resolution->url : null;
            $googleEventId = $resolution->eventId;
            $googleConferenceStatus = $resolution->conferenceStatus;
        }

        $isPublished = array_key_exists('is_published', $validated)
            ? (bool) $validated['is_published']
            : (bool) ($existing?->is_published ?? false);

        $statusInput = $validated['status'] ?? ($existing?->status ?? LiveClass::STATUS_DRAFT);
        $status = $this->deriveStatus($statusInput, $scheduledAt, $endsAt, $isPublished);

        return [
            'school_id' => $this->school_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            'class_id' => $validated['class_id'] ?? null,
            'programme_id' => $validated['programme_id'] ?? null,
            'academic_session_id' => $validated['academic_session_id'] ?? null,
            'teacher_id' => $validated['teacher_id'] ?? Auth::id(),
            'platform' => $validated['platform'],
            'meeting_url' => $meetingUrl,
            'meeting_id' => $validated['meeting_id'] ?? ($existing?->meeting_id ?? null),
            'meeting_password' => $validated['meeting_password'] ?? ($existing?->meeting_password ?? null),
            'start_date' => $validated['start_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'timezone' => $validated['timezone'] ?? config('app.timezone', 'UTC'),
            'scheduled_at' => $scheduledAt->timezone('UTC')->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->timezone('UTC')->format('Y-m-d H:i:s'),
            'status' => $status,
            'is_published' => $isPublished,
            'attendance_enabled' => !empty($validated['attendance_enabled']) ? 1 : 0,
            'recording_url' => $validated['recording_url'] ?? ($existing?->recording_url ?? null),
            'created_by' => $existing?->created_by ?: Auth::id(),
            'updated_by' => Auth::id(),
        ];

        /**
         * Google Calendar bookkeeping is added ONLY when there is something to
         * record.
         *
         * Writing an explicit NULL is indistinguishable from omitting the key for
         * any class on another platform, but it is not indistinguishable to the
         * database: it names a column that a good number of this application's
         * test fixtures do not define, and a payload naming an absent column is a
         * hard SQL error rather than a skipped write. Omitting it means those
         * fixtures keep working untouched, and in production the column simply
         * takes its own default of NULL — which is the value we wanted anyway.
         *
         * Both keys travel together. An event id with no conference status, or a
         * conference status with no event, each describe half a Google class.
         */
        if ($googleEventId !== null || $googleConferenceStatus !== null) {
            $payload['google_calendar_event_id'] = $googleEventId;
            $payload['google_conference_status'] = $googleConferenceStatus;
        }

        return $payload;
    }

    /** Legacy/K12 forms cannot attach an HEI Offering by supplying a raw ID. */
    private function rejectOfferingContextOnLegacyWorkflow(Request $request): void
    {
        if ($request->exists('course_offering_id')) {
            throw ValidationException::withMessages([
                'course_offering_id' => get_phrase('Offering-backed Live Classes must be created through the Course Offering workflow.'),
            ]);
        }
    }

    private function getRoutePrefix(Request $request): string
    {
        return $request->routeIs('teacher.*') ? 'teacher' : 'admin';
    }

    private function deriveStatus(string $inputStatus, Carbon $scheduledAt, Carbon $endsAt, bool $isPublished): string
    {
        if ($inputStatus === LiveClass::STATUS_CANCELLED) {
            return LiveClass::STATUS_CANCELLED;
        }

        if (!$isPublished) {
            return LiveClass::STATUS_DRAFT;
        }

        $now = now()->timezone('UTC');
        if ($now->greaterThan($endsAt->copy()->timezone('UTC'))) {
            return LiveClass::STATUS_ENDED;
        }

        if ($now->betweenIncluded($scheduledAt->copy()->timezone('UTC'), $endsAt->copy()->timezone('UTC'))) {
            return LiveClass::STATUS_LIVE;
        }

        return LiveClass::STATUS_SCHEDULED;
    }

    private function canManageAll($user): bool
    {
        return in_array((int) $user->role_id, [1, 2, 10, 12, 14], true);
    }

    private function getAllowedSubjects()
    {
        $query = Subject::where('school_id', $this->school_id);

        if ((int) Auth::user()->role_id === 3 && !$this->canManageAll(Auth::user())) {
            $classIds = TeacherPermission::where('school_id', $this->school_id)
                ->where('teacher_id', Auth::id())
                ->pluck('class_id')
                ->unique();

            // Programme-linked (HEI) subjects have no class_id, so the
            // class_id filter below must not silently exclude them. Only
            // restrict them once this school has actually started assigning
            // teachers to programmes (see TeacherProgrammeAssignment) —
            // until then, fail open the same way class-based access always
            // did before TeacherPermission existed.
            $schoolHasConfiguredProgrammeAssignments = TeacherProgrammeAssignment::where('school_id', $this->school_id)->exists();

            $programmeIds = $schoolHasConfiguredProgrammeAssignments
                ? TeacherProgrammeAssignment::where('school_id', $this->school_id)
                    ->where('teacher_id', Auth::id())
                    ->pluck('programme_id')
                    ->unique()
                : collect();

            if ($classIds->isNotEmpty() || $schoolHasConfiguredProgrammeAssignments) {
                $query->where(function ($q) use ($classIds, $schoolHasConfiguredProgrammeAssignments, $programmeIds) {
                    $q->whereIn('class_id', $classIds);

                    if ($schoolHasConfiguredProgrammeAssignments) {
                        $q->orWhereIn('programme_id', $programmeIds);
                    } else {
                        $q->orWhereNull('class_id');
                    }
                });
            }
        }

        return $query->orderBy('name')->get();
    }

    private function canStudentAccessClass(LiveClass $liveClass): bool
    {
        $enroll = \App\Models\Enrollment::where('user_id', Auth::id())
            ->where('school_id', $this->school_id)
            ->first();

        if (!$enroll) {
            return false;
        }

        if ($liveClass->class_id && (int) $liveClass->class_id !== (int) $enroll->class_id) {
            return false;
        }

        if ($liveClass->academic_session_id && (int) $liveClass->academic_session_id !== (int) $enroll->session_id) {
            return false;
        }

        if ($liveClass->subject_id) {
            $subject = Subject::where('id', $liveClass->subject_id)
                ->where('school_id', $this->school_id)
                ->first();

            if (!$subject) {
                return false;
            }

            if ($subject->class_id && (int) $subject->class_id !== (int) $enroll->class_id) {
                return false;
            }
        }

        return (bool) $liveClass->is_published;
    }

    private function generateMeetingUrl(string $platform, string $title): string
    {
        if ($platform === 'jitsi') {
            $base = rtrim((string) get_settings('live_class_jitsi_base_url'), '/');
            if ($base === '') {
                $base = 'https://meet.jit.si';
            }

            return $base . '/' . Str::slug($title . '-' . Str::random(8));
        }

        return '';
    }

    /**
     * Calls a meeting provider (Zoom / Google Meet). If the provider cannot be reached at all —
     * network, DNS, TLS certificate verification — the request fails as a normal validation
     * error on meeting_url (input kept, nothing saved) instead of an HTTP 500. The failure is
     * logged server-side with its class and school only: no tokens, secrets or response bodies.
     * A reachable provider that refuses the request still returns null (existing behaviour).
     */
    private function callMeetingProvider(string $provider, callable $call): ?string
    {
        try {
            return $call();
        } catch (\Illuminate\Http\Client\ConnectionException | \Illuminate\Http\Client\RequestException | \GuzzleHttp\Exception\TransferException $e) {
            /*
             * Classify the failure before deciding what to tell the lecturer.
             *
             * This handler used to report one flat "could not be reached right now"
             * for every transport-level fault, which is how a missing CA bundle on
             * the host was indistinguishable from a genuine Google outage: the
             * request never left the machine, yet the message blamed Google. The
             * log line below now names the transport error and, for a TLS fault,
             * says so, so the next occurrence is diagnosable from the log alone
             * rather than requiring a live reproduction.
             *
             * Logged fields are deliberately limited to the exception class, the
             * transport error code and its short description. No access token,
             * refresh token, client secret, authorization code or response body
             * is recorded - `getMessage()` on a Guzzle exception can echo a
             * request URL with query parameters, so only the cURL error is used.
             */
            $transport = $this->describeTransportFailure($e);

            \Illuminate\Support\Facades\Log::warning("Live class: {$provider} transport failure ({$transport['kind']})", [
                'exception' => get_class($e),
                'failure_kind' => $transport['kind'],
                'curl_errno' => $transport['errno'],
                'curl_error' => $transport['message'],
                'hint' => $transport['hint'],
                'school_id' => auth()->user()->school_id ?? null,
                'user_id' => auth()->id(),
            ]);

            throw ValidationException::withMessages([
                'meeting_url' => get_phrase($transport['userMessage'] ?? $provider . ' could not be reached right now, so the class was not saved. Please try again shortly, or paste a meeting link to schedule it now.'),
            ]);
        }
    }

    /**
     * Turn a transport-level HTTP exception into a diagnosable classification.
     *
     * The distinction that matters: a TLS trust failure is a fault in THIS
     * installation's PHP configuration, so retrying will never help and the
     * administrator has to act. Reporting that as a transient outage sends the
     * lecturer into a retry loop and sends the administrator to the wrong system
     * entirely - which is exactly the wrong turn this investigation took.
     *
     * No secret material is read. `getMessage()` is not used, because a Guzzle
     * message may echo the full request URL.
     */
    private function describeTransportFailure(\Throwable $e): array
    {
        $errno = 0;
        $curlError = '';

        // Laravel 12 also wraps response-less Guzzle failures in ConnectionException.
        // Follow only known HTTP wrappers, with a bound; never inspect messages or URLs.
        for ($depth = 0; $depth < 8; $depth++) {
            if (! $e instanceof \Illuminate\Http\Client\RequestException
                && ! $e instanceof \Illuminate\Http\Client\ConnectionException) { break; }
            $previous = $e->getPrevious();
            if (! $previous instanceof \Throwable) { break; }
            $e = $previous;
        }

        if (method_exists($e, 'getHandlerContext')) {
            $context = $e->getHandlerContext();
            if (is_array($context)) {
                $errno = (int) ($context['errno'] ?? 0);
                $curlError = substr((string) ($context['error'] ?? ''), 0, 200);
            }
        }

        // cURL 60/35/51/58/59/77/83 are all certificate-trust failures. Grouping
        // them is the point: the remediation is the same in every case.
        if (in_array($errno, [35, 51, 58, 59, 60, 77, 83], true)) {
            return [
                'kind' => 'tls_trust_failure',
                'errno' => $errno,
                'message' => $curlError,
                'hint' => 'PHP cannot verify the provider\'s TLS certificate. Check curl.cainfo / openssl.cafile in php.ini point to a readable CA bundle.',
                'userMessage' => get_phrase('This server cannot establish a secure connection to the meeting provider, so the class was not saved. This is a configuration fault, not something that will resolve by retrying. Please paste a meeting link to schedule the class now, and ask your administrator to check the server\'s TLS certificate settings.'),
            ];
        }

        if (in_array($errno, [5, 6, 7, 28], true)) {
            return [
                'kind' => 'network_unreachable',
                'errno' => $errno,
                'message' => $curlError,
                'hint' => 'DNS resolution, connection or timeout failure reaching the provider.',
            ];
        }

        return [
            'kind' => 'transport_error',
            'errno' => $errno,
            'message' => $curlError,
            'hint' => 'Unclassified transport failure; inspect the exception class.',
        ];
    }

    /**
     * The error for "no meeting link came back": not configured (setup message), or configured
     * but the provider refused / failed / answered without a link (HTTP 401/403/404/429/5xx,
     * expired or invalid credentials, malformed response). Nothing is saved; logged without secrets.
     */
    private function meetingLinkFailure(string $platform, string $label, string $notConfiguredMessage): ValidationException
    {
        if (!$this->platformIsConfigured($platform)) {
            return ValidationException::withMessages(['meeting_url' => get_phrase($notConfiguredMessage)]);
        }

        \Illuminate\Support\Facades\Log::warning("Live class: {$label} API returned no meeting link", [
            'school_id' => auth()->user()->school_id ?? null,
            'user_id' => auth()->id(),
        ]);

        return ValidationException::withMessages([
            'meeting_url' => get_phrase($label . ' did not create a meeting link, so the class was not saved. The service may be temporarily unavailable, or its connection settings may need attention from the system administrator. Please try again shortly, or paste a meeting link to schedule it now.'),
        ]);
    }

    private function resolveMeetingUrl(string $platform, string $title, Carbon $scheduledAt, Carbon $endsAt, string $timezone): string
    {
        return $this->resolveMeeting($platform, $title, $scheduledAt, $endsAt, $timezone, null)->url;
    }

    /**
     * Resolve a meeting, returning everything the provider told us.
     *
     * `$class` is optional and only used for Google, which needs the description
     * and the guest list to build a faithful event. Callers that already hold the
     * LiveClass being created pass it; callers that do not (an ad-hoc "meet now")
     * get an event without a description, which Google accepts.
     *
     * `resolveMeetingUrl()` above remains as the string-returning shortcut so the
     * three existing call sites and their tests keep working unchanged.
     *
     * `$description` is the class description being scheduled. It is a separate
     * parameter rather than read off `$class` because on CREATE there is no class
     * yet — the row is written after this runs — so the text exists only in the
     * validated input. `$class` is still consulted, but only as the fallback for
     * an edit where the field was left untouched.
     */
    private function resolveMeeting(string $platform, string $title, Carbon $scheduledAt, Carbon $endsAt, string $timezone, ?LiveClass $class = null, ?string $description = null, bool $requireOwnGoogleAccount = false): MeetingResolution
    {
        if ($platform === 'jitsi') {
            return MeetingResolution::urlOnly($this->generateMeetingUrl('jitsi', $title));
        }

        if ($platform === 'zoom') {
            $url = $this->callMeetingProvider('Zoom', fn () => $this->createZoomMeetingUrl($title, $scheduledAt, $endsAt, $timezone));
            if (!empty($url)) {
                return MeetingResolution::urlOnly($url);
            }

            throw $this->meetingLinkFailure('zoom', 'Zoom', 'Zoom API is not configured. Add ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET in your .env file.');
        }

        if ($platform === 'google_meet') {
            /**
             * The lecturer's OWN Google account takes precedence.
             *
             * This is the whole point of the OAuth work: the conference lands on
             * the calendar of the person who will actually teach it, and PIIE never
             * needs to hold a shared service credential. Where the lecturer has
             * connected an account, the institution-wide fallback below is not
             * consulted — mixing the two would create classes on a calendar
             * belonging to whoever set up the installation, which the lecturer
             * cannot then edit or cancel.
             */
            $own = $this->createGoogleMeetEventForActor(
                $title,
                $description ?? ($class->description ?? null),
                $scheduledAt,
                $endsAt,
                $timezone,
                $class
            );

            if ($own !== null) {
                return $own;
            }

            /**
             * NO SILENT SUBSTITUTION — the Course Offering workflow.
             *
             * An unconnected lecturer used to fall straight through to the
             * installation-wide credential below, so choosing Google Meet quietly
             * created the conference on the institution's calendar instead of the
             * lecturer's own. Nothing said so. The class was created, the students
             * were notified, and the lecturer's own calendar — the one they can edit
             * or cancel from — never saw the class at all. That is not a fallback,
             * it is a substitution, and it is refused here rather than performed.
             *
             * The shared credential is left completely intact: it still serves the
             * non-Offering path and an administrator scheduling on someone's behalf.
             * Only the lecturer's own automatic creation is refused, and it is
             * refused LOUDLY and BEFORE any Google request, so nothing is written to
             * any calendar as a side effect of the mistake.
             *
             * Failing on `platform` rather than `meeting_url`: this is a problem with
             * the provider the lecturer chose, not with the meeting link they may
             * still paste in by hand. Manual entry is untouched.
             */
            if ($requireOwnGoogleAccount) {
                throw ValidationException::withMessages([
                    'platform' => get_phrase('Connect your Google Account before scheduling a Google Meet class.'),
                ]);
            }

            $url = $this->callMeetingProvider('Google Meet', fn () => $this->createGoogleMeetUrl($title, $scheduledAt, $endsAt, $timezone));
            if (!empty($url)) {
                return MeetingResolution::urlOnly($url);
            }

            throw $this->meetingLinkFailure('google_meet', 'Google Meet',
                'Google Meet is not configured for this account. Connect your Google Account on the Live Classes page, or ask your administrator to configure the installation-wide Google Meet settings.'
            );
        }

        throw ValidationException::withMessages([
            'platform' => get_phrase('Automatic link generation is supported only for Jitsi, Zoom API, or Google Meet API.'),
        ]);
    }

    /**
     * Create a Meet conference using the ACTING USER's own connected Google account.
     *
     * Returns null — meaning "fall through to the installation-wide path" — only
     * when there is nothing to use: the user never connected, or their grant has
     * lapsed. It does NOT return null when Google is reachable but refused the
     * request; that is a real failure and must be reported, because falling
     * through would silently create the class on a different calendar and the
     * lecturer would learn of it only when students could not get in.
     *
     * Guests come from the same school-scoped guest list the installation-wide
     * path uses, so switching between the two does not change who is invited.
     */
    private function createGoogleMeetEventForActor(
        string $title,
        ?string $description,
        Carbon $scheduledAt,
        Carbon $endsAt,
        string $timezone,
        ?LiveClass $class,
    ): ?MeetingResolution {
        $actor = Auth::user();

        // Only an individual lecturer's own account is used. An administrator
        // scheduling on someone's behalf has no business writing to that
        // lecturer's calendar, and the installation-wide path remains available.
        //
        // This compares against PermissionService::TEACHER (role_id 3), the same
        // value TeacherMiddleware admits. It previously read 6, which is the
        // PARENT role - so it locked out every real lecturer (including the one
        // who reported the failure) while admitting a parent, who would then have
        // written the class onto a lecturer's personal Google calendar. The
        // tenancy of the calendar is not re-derived here: accessTokenFor() only
        // ever returns the caller's OWN connection row.
        if (! $actor || (int) $actor->role_id !== PermissionService::TEACHER) {
            return null;
        }

        $access = app(GoogleAccountService::class)->accessTokenFor($actor);

        if ($access === null) {
            return null;
        }

        $attendees = LiveClassMeetGuest::forSchool($this->school_id)
            ->pluck('email')
            ->map(fn (string $email) => ['email' => $email])
            ->values()
            ->all();

        try {
            $result = app(GoogleCalendarService::class)->createMeetingEvent(
                $access['token'],
                $access['connection']->calendar_id ?: 'primary',
                [
                    'title' => $title,
                    'description' => $description,
                    'starts_at' => $scheduledAt,
                    'ends_at' => $endsAt,
                    // Africa/Kampala unless the tenant explicitly scheduled in
                    // another zone. Forcing Kampala over a deliberate choice would
                    // put the class at the wrong hour on the lecturer's calendar.
                    'timezone' => $timezone ?: GoogleCalendarService::DEFAULT_TIMEZONE,
                    'attendees' => $attendees,
                ]
            );
        } catch (RuntimeException $e) {
            // Recorded, not swallowed: the message is Google's own error text,
            // which contains no credential.
            \Illuminate\Support\Facades\Log::warning('Google Meet event creation failed.', [
                'live_class_id' => $class->id ?? null,
                'message' => $e->getMessage(),
            ]);

            throw $this->meetingLinkFailure('google_meet', 'Google Meet', $e->getMessage());
        }

        return $result['conference_status'] === GoogleCalendarService::CONFERENCE_PENDING
            ? MeetingResolution::googlePending($result['event_id'], $result['html_link'])
            : MeetingResolution::googleReady($result['event_id'], $result['meeting_url'], $result['html_link']);
    }

    private function createZoomMeetingUrl(string $title, Carbon $scheduledAt, Carbon $endsAt, string $timezone): ?string
    {
        $accountId = (string) config('services.zoom.account_id');
        $clientId = (string) config('services.zoom.client_id');
        $clientSecret = (string) config('services.zoom.client_secret');

        if ($accountId === '' || $clientId === '' || $clientSecret === '') {
            return null;
        }

        $tokenResponse = Http::asForm()->connectTimeout(10)->timeout(20)
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]);

        if (!$tokenResponse->successful()) {
            return null;
        }

        $accessToken = (string) $tokenResponse->json('access_token');
        if ($accessToken === '') {
            return null;
        }

        $duration = max(1, \App\Support\Compatibility\WholeDateIntervals::minutes($scheduledAt, $endsAt));
        $meetingResponse = Http::withToken($accessToken)->connectTimeout(10)->timeout(20)
            ->acceptJson()
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $title,
                'type' => 2,
                'start_time' => $scheduledAt->copy()->timezone('UTC')->toIso8601String(),
                'duration' => $duration,
                'timezone' => $timezone,
                'settings' => [
                    'join_before_host' => true,
                    'waiting_room' => false,
                ],
            ]);

        if (!$meetingResponse->successful()) {
            return null;
        }

        $joinUrl = (string) $meetingResponse->json('join_url');
        return $joinUrl !== '' ? $joinUrl : null;
    }

    private function createGoogleMeetUrl(string $title, Carbon $scheduledAt, Carbon $endsAt, string $timezone): ?string
    {
        $clientId = (string) config('services.google_meet.client_id');
        $clientSecret = (string) config('services.google_meet.client_secret');
        $refreshToken = (string) config('services.google_meet.refresh_token');
        $calendarId = (string) config('services.google_meet.calendar_id', 'primary');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return null;
        }

        $tokenResponse = Http::asForm()->connectTimeout(10)->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$tokenResponse->successful()) {
            return null;
        }

        $accessToken = (string) $tokenResponse->json('access_token');
        if ($accessToken === '') {
            return null;
        }

        // Guests configured in admin/live-classes/meet-guests — added as
        // attendees so a teacher signed into one of these addresses is a
        // named, recognised guest on the invite (and is emailed a calendar
        // invite via sendUpdates=all below), instead of every joiner being
        // an anonymous link-holder the way this event used to be created.
        $guestEmails = LiveClassMeetGuest::forSchool($this->school_id)->pluck('email');
        $attendees = $guestEmails->map(fn (string $email) => ['email' => $email])->values()->all();

        $eventResponse = Http::withToken($accessToken)->connectTimeout(10)->timeout(20)
            ->acceptJson()
            ->post('https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendarId) . '/events?conferenceDataVersion=1&sendUpdates=all', [
                'summary' => $title,
                'start' => [
                    'dateTime' => $scheduledAt->copy()->setTimezone($timezone)->toIso8601String(),
                    'timeZone' => $timezone,
                ],
                'end' => [
                    'dateTime' => $endsAt->copy()->setTimezone($timezone)->toIso8601String(),
                    'timeZone' => $timezone,
                ],
                'attendees' => $attendees,
                'guestsCanSeeOtherGuests' => true,
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => [
                            'type' => 'hangoutsMeet',
                        ],
                    ],
                ],
            ]);

        if (!$eventResponse->successful()) {
            return null;
        }

        $hangoutLink = (string) $eventResponse->json('hangoutLink');
        if ($hangoutLink !== '') {
            return $hangoutLink;
        }

        $entryPoints = (array) $eventResponse->json('conferenceData.entryPoints', []);
        foreach ($entryPoints as $entryPoint) {
            $entryUri = (string) ($entryPoint['uri'] ?? '');
            if ($entryUri !== '') {
                return $entryUri;
            }
        }

        return null;
    }

    /**
     * @return int registered students notified (0 for the legacy
     *             school-wide path, which is a Noticeboard entry and has no
     *             recipient-scoped recipient count to report)
     */
    private function createStudentLiveClassNotice(LiveClass $liveClass, string $eventType): int
    {
        $liveClass->loadMissing(['subject', 'classRoom', 'academicSession']);

        if ($liveClass->course_offering_id !== null) {
            // Recipient-scoped and deduplicated. A school-wide Noticeboard entry
            // would disclose an Offering's class to every student in the
            // institution, so the Offering-backed path never uses one: it goes
            // only to CONFIRMED registrations for that exact Offering, and the
            // live_class_notifications unique index stops a repeated publish or
            // a repeated save from re-notifying the same students.
            return \App\Support\LiveClasses\LiveClassNotifier::announcePublished($liveClass);
        }

        $classInfo = $liveClass->class_id
            ? ('Class: ' . (optional($liveClass->classRoom)->name ?: ('ID ' . $liveClass->class_id)))
            : 'Class: All classes';
        $subjectName = optional($liveClass->subject)->name ?: 'All courses';
        $sessionInfo = $liveClass->academic_session_id
            ? ('Session: ' . (optional($liveClass->academicSession)->session_title ?: ('ID ' . $liveClass->academic_session_id)))
            : 'Session: All sessions';
        $actionLabel = $eventType === 'published' ? 'published' : 'scheduled';

        $noticeTitle = 'Live Class ' . ucfirst($actionLabel) . ': ' . $liveClass->title;
        $noticeBody = "A live class has been {$actionLabel}.\n"
            . "Course: {$subjectName}\n"
            . "{$classInfo}\n"
            . "{$sessionInfo}\n"
            . "Date: " . optional($liveClass->start_date)->format('Y-m-d') . "\n"
            . "Time: {$liveClass->start_time} - {$liveClass->end_time}\n"
            . "Join Link: " . ($liveClass->meeting_url ?: 'TBD');

        $sessionId = (int) get_school_settings($this->school_id)->value('running_session');
        if ($sessionId === 0) {
            $sessionId = (int) Session::where('school_id', $this->school_id)->max('id');
        }

        Noticeboard::create([
            'notice_title' => $noticeTitle,
            'notice' => $noticeBody,
            'start_date' => optional($liveClass->start_date)->format('Y-m-d') ?: now()->format('Y-m-d'),
            'start_time' => (string) ($liveClass->start_time ?: ''),
            'end_date' => optional($liveClass->start_date)->format('Y-m-d') ?: now()->format('Y-m-d'),
            'end_time' => (string) ($liveClass->end_time ?: ''),
            'status' => 1,
            'show_on_website' => 0,
            'image' => '',
            'school_id' => $this->school_id,
            'session_id' => $sessionId > 0 ? $sessionId : 0,
        ]);

        return 0;
    }

    /**
     * The platform pre-selected for a brand-new class.
     *
     * Google Meet is the intended default — but only once it can actually
     * create a meeting. Defaulting to it before GOOGLE_CLIENT_ID/SECRET/
     * REFRESH_TOKEN are set in .env would make every new class fail
     * validation the moment it's saved (see resolveMeetingUrl()), so the
     * fallback to Jitsi — the one platform that never needs external
     * credentials — holds until Google Meet is actually configured.
     */
    /**
     * Admin-only list of emails added as attendees on every Google Meet
     * event this school creates (see createGoogleMeetUrl()) — lets an admin
     * grow the "recognised guest" list without ever touching .env.
     */
    public function meetGuests()
    {
        $this->authorize('create', LiveClass::class);
        abort_unless((int) Auth::user()->role_id === 2, 403);

        $guests = LiveClassMeetGuest::forSchool($this->school_id)->orderBy('email')->get();

        return view('admin.live_class.meet_guests', compact('guests'));
    }

    public function storeMeetGuest(Request $request)
    {
        $this->authorize('create', LiveClass::class);
        abort_unless((int) Auth::user()->role_id === 2, 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'label' => ['nullable', 'string', 'max:191'],
        ]);

        $exists = LiveClassMeetGuest::forSchool($this->school_id)
            ->where('email', $validated['email'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', get_phrase('That email is already on the list'));
        }

        LiveClassMeetGuest::create([
            'school_id' => $this->school_id,
            'email' => $validated['email'],
            'label' => $validated['label'] ?? null,
            'created_by' => Auth::id(),
        ]);

        AuditLog::record('create', 'Live Classes', "Added Google Meet guest: {$validated['email']}");

        return redirect()->back()->with('success', get_phrase('Guest email added'));
    }

    public function destroyMeetGuest($id)
    {
        $this->authorize('create', LiveClass::class);
        abort_unless((int) Auth::user()->role_id === 2, 403);

        $guest = LiveClassMeetGuest::forSchool($this->school_id)->findOrFail((int) $id);
        $email = $guest->email;
        $guest->delete();

        AuditLog::record('delete', 'Live Classes', "Removed Google Meet guest: {$email}");

        return redirect()->back()->with('success', get_phrase('Guest email removed'));
    }

    private function defaultPlatform(): string
    {
        return $this->platformIsConfigured('google_meet') ? 'google_meet' : 'jitsi';
    }

    /**
     * Whether a platform has the credentials it needs to create a real
     * meeting. Jitsi/BigBlueButton/custom need none of this — only Zoom and
     * Google Meet call out to an external API (see resolveMeetingUrl()).
     */
    private function platformIsConfigured(string $platform): bool
    {
        if ($platform === 'google_meet') {
            return (string) config('services.google_meet.client_id') !== ''
                && (string) config('services.google_meet.client_secret') !== ''
                && (string) config('services.google_meet.refresh_token') !== '';
        }

        if ($platform === 'zoom') {
            return (string) config('services.zoom.account_id') !== ''
                && (string) config('services.zoom.client_id') !== ''
                && (string) config('services.zoom.client_secret') !== '';
        }

        return true;
    }

    /** Configured-state per platform, for labelling <select> options in the views. */
    private function platformConfigurationStatus(): array
    {
        return [
            'jitsi' => true,
            'google_meet' => $this->platformIsConfigured('google_meet'),
            'zoom' => $this->platformIsConfigured('zoom'),
            'bigbluebutton' => true,
            'custom' => true,
        ];
    }

    private function getEnabledPlatforms(): array
    {
        $map = [
            'jitsi' => get_settings('live_class_platform_jitsi') !== '0',
            'google_meet' => get_settings('live_class_platform_google_meet') !== '0',
            'zoom' => get_settings('live_class_platform_zoom') !== '0',
            'bigbluebutton' => get_settings('live_class_platform_bigbluebutton') === '1',
            'custom' => get_settings('live_class_platform_custom') === '1',
        ];

        $enabled = [];
        foreach ($map as $platform => $isEnabled) {
            if ($isEnabled) {
                $enabled[] = $platform;
            }
        }

        return $enabled ?: ['jitsi', 'google_meet', 'zoom'];
    }
}
