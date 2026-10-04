<?php

namespace App\Support\LiveClasses;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\CourseOffering\SystemTesterAccess;
use App\Support\Permissions\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Authoritative Offering-scoped Live Class participation and staffing checks. */
class LiveClassAccessService
{
    private const VIEW_CAPABILITY = 'live_classes.view';
    private const MANAGE_CAPABILITIES = ['live_classes.create', 'live_classes.manage_all'];

    /**
     * The single pre-start testing authority, shared with the lecturer workspace
     * so the two can never disagree. It relaxes only the allocation DATE gate.
     */
    public function __construct(private SystemTesterAccess $testers) {}

    public function isOfferingBacked(LiveClass $class): bool
    {
        return $class->course_offering_id !== null;
    }

    /**
     * May this student READ this class?
     *
     * A CANCELLED class is deliberately INCLUDED. Cancellation is a fact about
     * the class, not a withdrawal of the student from it: the student was
     * notified, the notification is a durable artefact, and the whole point of
     * telling them is that they can see what happened to it. Refusing the page
     * used to make a cancellation notification a dead link - the one event where
     * a student most needs to know what they are looking at, and the one moment
     * where a 404 reads as "PIIE lost my class".
     *
     * What cancellation withholds is the JOIN, not the record. That is enforced
     * separately in canStudentJoin(), which admits only scheduled or live
     * classes, so nothing here can re-open a cancelled meeting.
     */
    public function canStudentViewClass(User $user, LiveClass $class): bool
    {
        return (bool) $class->is_published
            && $this->studentOfferingPermitsHistoricalAccess($class)
            && $this->confirmedStudentRegistration($user, $class);
    }

    public function confirmedStudentRegistration(User $user, LiveClass $class): bool
    {
        return (int) $user->role_id === 7
            && $user->account_status !== 'disable'
            && (int) $user->school_id === (int) $class->school_id
            && $this->offering($class) !== null
            && CourseRegistration::query()->where('school_id', $class->school_id)
                ->where('course_offering_id', $class->course_offering_id)
                ->where('student_id', $user->id)
                ->where('status', CourseRegistration::STATUS_CONFIRMED)->exists();
    }

    public function confirmedOfferingIdsQuery(User $user, int $schoolId): Builder
    {
        return CourseRegistration::query()->select('course_offering_id')
            ->where('school_id', $schoolId)
            ->where('student_id', $user->id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->whereNotNull('course_offering_id')
            ->whereIn('student_id', User::query()->select('id')->where('school_id', $schoolId)
                ->where('role_id', 7)
                ->where(function ($query): void {
                    $query->whereNull('account_status')->orWhere('account_status', '!=', 'disable');
                }))
            ->whereIn('course_offering_id', CourseOffering::query()
                ->select('id')
                ->where('school_id', $schoolId)
                ->whereIn('status', [
                    CourseOffering::STATUS_OPEN,
                    CourseOffering::STATUS_IN_PROGRESS,
                    CourseOffering::STATUS_COMPLETED,
                ]));
    }

    public function canStudentJoin(User $user, LiveClass $class, ?Carbon $now = null): bool
    {
        if (! $this->canStudentViewClass($user, $class)
            || ! in_array($class->status, [LiveClass::STATUS_SCHEDULED, LiveClass::STATUS_LIVE], true)
            || ! $this->withinJoinWindow($class, $now)) return false;
        return $this->offeringAllowsOperations($class);
    }

    public function canStudentViewMaterials(User $user, LiveClass $class): bool
    {
        return $this->canStudentViewClass($user, $class);
    }

    public function canStudentViewRecording(User $user, LiveClass $class): bool
    {
        return $this->canStudentViewClass($user, $class);
    }

    /**
     * Historical display uses allocation validity on the scheduled date.
     *
     * Read authority, so it must agree with lecturerVisibleClassIdsQuery() or
     * the list and the detail page will disagree about the same class. That
     * disagreement is what made a lecturer's dashboard read "Live Classes 0"
     * while the classes plainly existed and were theirs.
     *
     * As in the list query, only the END of the allocation window narrows this:
     * a class scheduled before the lecturer's own allocation start is part of
     * their Offering's history and is theirs to read.
     */
    public function canLecturerView(User $user, LiveClass $class): bool
    {
        return $this->hasCapability($user, self::VIEW_CAPABILITY)
            && $this->readableAllocation($user, $class) !== null;
    }

    /**
     * An allocation that entitles this lecturer to READ this class.
     *
     * Separate from allocation() on purpose. allocation() answers "may this
     * lecturer act NOW", which legitimately requires an appointment in force
     * today. Reusing it for reading made visibility depend on the calendar,
     * which is not what an appointment is for.
     */
    private function readableAllocation(User $user, LiveClass $class): ?CourseOfferingLecturerAllocation
    {
        if ((int) $user->school_id !== (int) $class->school_id || ! $this->offering($class)) {
            return null;
        }

        return CourseOfferingLecturerAllocation::query()
            ->where('school_id', $class->school_id)
            ->where('course_offering_id', $class->course_offering_id)
            ->where('user_id', $user->id)
            ->whereIn('role', CourseOfferingLecturerAllocation::ROLES)
            ->whereIn('status', [
                CourseOfferingLecturerAllocation::STATUS_ACTIVE,
                CourseOfferingLecturerAllocation::STATUS_ENDED,
            ])
            ->when(
                $class->ends_at !== null || $class->start_date !== null,
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $this->meetingDate($class)))
            )
            ->first();
    }

    /** Current mutation requires current allocation and an operational Offering. */
    public function canLecturerManage(User $user, LiveClass $class): bool
    {
        return $this->hasAnyCapability($user, self::MANAGE_CAPABILITIES)
            && ! in_array($class->status, [LiveClass::STATUS_CANCELLED, LiveClass::STATUS_ENDED], true)
            && $this->offeringAllowsOperations($class)
            && $this->allocation($user, $class, true, now()->toDateString()) !== null;
    }

    /**
     * May this lecturer CONCLUDE this class - mark it completed, or cancel it?
     *
     * Deliberately a separate question from canLecturerManage(), and deliberately
     * WIDER in one direction and narrower in another:
     *
     *  - Narrower: it excludes Draft. A class that was never published was
     *    never announced, so there is nothing to conclude - it can simply be
     *    edited or left as a draft.
     *  - Wider: it is checked against the meeting date rather than today, so a
     *    lecturer whose allocation has since moved on can still close out a
     *    session they actually ran. Refusing that would leave the record
     *    permanently stuck in the unclaimed state, which is the one outcome this
     *    exists to prevent.
     *
     * Either way the transition itself is checked against the lifecycle table,
     * so this decides WHO may ask, not WHETHER the move is legal.
     */
    public function canLecturerConclude(User $user, LiveClass $class): bool
    {
        if ($class->status === LiveClass::STATUS_DRAFT) {
            return false;
        }

        if (in_array($class->status, [LiveClass::STATUS_CANCELLED, LiveClass::STATUS_ENDED], true)) {
            return false;
        }

        $allowed = $this->canLecturerManage($user, $class);

        if ($allowed) {
            return true;
        }

        // Post-meeting fallback: the allocation must still cover the class's own
        // date, so this cannot be used to reach a session that was never theirs.
        return $this->hasAnyCapability($user, self::MANAGE_CAPABILITIES)
            && $this->readableAllocation($user, $class) !== null;
    }

    public function canLecturerCreateForOffering(User $user, CourseOffering $offering, ?Carbon $date = null): bool
    {
        $date ??= now();
        return (int) $user->school_id === (int) $offering->school_id
            && $this->hasCapability($user, 'live_classes.create')
            && in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)
            && $this->activeManagerAllocation($user, $offering, $date->toDateString()) !== null;
    }

    public function canAdminCreateForOffering(User $user, CourseOffering $offering): bool
    {
        $adminRole = in_array((int) $user->role_id, [PermissionService::SUPER_ADMIN, PermissionService::SCHOOL_ADMIN], true);
        return (int) $user->school_id === (int) $offering->school_id
            && $this->hasCapability($user, 'live_classes.create')
            && ($adminRole || $this->hasCapability($user, 'live_classes.manage_all'))
            && in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true);
    }

    /** Current, active Primary/Co allocations available as normal facilitators. */
    public function activeManagerAllocationsForOffering(CourseOffering $offering, ?Carbon $date = null)
    {
        $date ??= now();
        $dateString = $date->toDateString();
        $allocations = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER])
            ->where('status', CourseOfferingLecturerAllocation::STATUS_ACTIVE)
            ->whereDate('starts_on', '<=', $dateString)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dateString))
            ->whereHas('lecturer', fn ($query) => $query->where('school_id', $offering->school_id)
                ->where(function ($active) {
                    $active->whereNull('account_status')->orWhere('account_status', '!=', 'disable');
                }))
            ->with('lecturer')
            ->get();

        // Pre-start exception, judged per allocation through the same shared
        // authority the lecturer workspace uses, so the two can never disagree.
        // A lecturer without the grant, or on an Offering that was not
        // deliberately early-started, is simply not added.
        $extra = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER])
            ->where('status', CourseOfferingLecturerAllocation::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dateString))
            ->whereHas('lecturer', fn ($query) => $query->where('school_id', $offering->school_id)
                ->where(function ($active) {
                    $active->whereNull('account_status')->orWhere('account_status', '!=', 'disable');
                }))
            ->with('lecturer')
            ->get()
            ->filter(fn ($allocation) => ! $allocations->contains('id', $allocation->id)
                && $allocation->lecturer
                && $this->testers->onlyPreStartDateBlocks($allocation->lecturer, $allocation, $dateString, $offering))
            ->values();

        return $allocations->concat($extra)->values();
    }

    public function canLecturerManageMaterials(User $user, LiveClass $class): bool
    {
        return $this->canLecturerManage($user, $class);
    }

    /**
     * May this person attach a POST-CLASS resource - a recording, slides, notes?
     *
     * A separate authority from canLecturerManage() because the two genuinely
     * differ in time. A recording is normally produced by the provider AFTER the
     * class has ended, so requiring the class to still be manageable would make
     * the resource workflow unusable for precisely the classes it exists for -
     * it would refuse a completed class, which is the only one with a recording.
     *
     * The test is responsibility rather than current manageability: this person
     * is, or was, allocated to the Offering the class belongs to, and holds a
     * manage capability. That keeps the class bound to its Offering and to a real
     * appointment, while allowing the work to be finished afterwards.
     *
     * Available on a concluded class on purpose. Cancelling a class does not
     * delete the notes explaining why, and a lecturer who has to upload the
     * agenda for a class they cancelled still has to be able to.
     */
    public function canManagePostClassResources(User $user, LiveClass $class): bool
    {
        if ($class->course_offering_id === null) {
            return false;
        }

        if ($this->canTenantAdmin($user, $class, 'live_classes.manage_all')) {
            return true;
        }

        return $this->hasAnyCapability($user, self::MANAGE_CAPABILITIES)
            && $this->readableAllocation($user, $class) !== null;
    }

    /** Any valid allocation role may join; only Primary/Co may host. */
    public function canLecturerJoin(User $user, LiveClass $class, ?Carbon $now = null): bool
    {
        return $this->hasCapability($user, self::VIEW_CAPABILITY)
            && (bool) $class->is_published
            && in_array($class->status, [LiveClass::STATUS_SCHEDULED, LiveClass::STATUS_LIVE], true)
            && $this->offeringAllowsOperations($class)
            && $this->withinJoinWindow($class, $now)
            && $this->allocation($user, $class, true, $this->meetingDate($class), false, false) !== null;
    }

    public function canLecturerHost(User $user, LiveClass $class, ?Carbon $now = null): bool
    {
        return $this->hasAnyCapability($user, self::MANAGE_CAPABILITIES)
            && (bool) $class->is_published
            && in_array($class->status, [LiveClass::STATUS_SCHEDULED, LiveClass::STATUS_LIVE], true)
            && $this->offeringAllowsOperations($class)
            && $this->withinJoinWindow($class, $now)
            && $this->allocation($user, $class, true, ($now ?? now())->toDateString(), true) !== null
            && $this->allocation($user, $class, true, $this->meetingDate($class), true) !== null;
    }

    public function canTenantAdmin(User $user, LiveClass $class, string $capability = 'live_classes.manage_all'): bool
    {
        return in_array((int) $user->role_id, [PermissionService::SUPER_ADMIN, PermissionService::SCHOOL_ADMIN], true)
            && $this->canAdminForTenant($user, (int) $class->school_id, $capability);
    }

    public function canTenantAdminJoin(User $user, LiveClass $class, ?Carbon $now = null): bool
    {
        return $this->canTenantAdmin($user, $class, 'live_classes.manage_all')
            && (bool) $class->is_published
            && in_array($class->status, [LiveClass::STATUS_SCHEDULED, LiveClass::STATUS_LIVE], true)
            && $this->offeringAllowsOperations($class)
            && $this->withinJoinWindow($class, $now);
    }

    public function canTenantAdminManage(User $user, LiveClass $class): bool
    {
        return $this->canTenantAdmin($user, $class, 'live_classes.manage_all')
            && ! in_array($class->status, [LiveClass::STATUS_CANCELLED, LiveClass::STATUS_ENDED], true)
            && $this->offeringAllowsOperations($class);
    }

    public function canAdminForTenant(User $user, int $schoolId, string $capability): bool
    {
        $platformAdmin = (int) $user->role_id === PermissionService::SUPER_ADMIN;
        return ($platformAdmin || (int) $user->school_id === $schoolId)
            && $this->hasCapability($user, $capability);
    }

    public function canViewAllOfferingClasses(User $user, int $schoolId): bool
    {
        $tenantAdmin = in_array((int) $user->role_id, [PermissionService::SUPER_ADMIN, PermissionService::SCHOOL_ADMIN], true);
        return ($tenantAdmin && $this->canAdminForTenant($user, $schoolId, self::VIEW_CAPABILITY))
            || $this->canAdminForTenant($user, $schoolId, 'live_classes.manage_all');
    }

    /**
     * Which Live Classes may this lecturer READ?
     *
     * READ, not act. The distinction matters and was the cause of a real,
     * reported bug: a lecturer's Course Offering dashboard showed "Live Classes
     * 0" while they demonstrably owned, and had created, several of them.
     *
     * The old query required the CLASS DATE to fall inside the allocation's own
     * date window, so any class scheduled before the allocation's agreed
     * `starts_on` became invisible. That is wrong in principle and it is wrong
     * in practice here: an allocation for 1 Oct does not make a class taught on
     * 29 Sep somebody else's, and a class belongs to its Offering, not to a
     * slice of the calendar. It also disagreed with the per-record checks
     * below, which do let an authorised tester past a pre-start date - so the
     * list and the detail page could contradict each other about one class.
     *
     * The window is therefore applied in the only direction defensible for
     * reading history: a class is hidden when it falls AFTER the allocation
     * ended, because a lecturer must not be shown as responsible for a session
     * beyond their appointment. The start date hides nothing.
     *
     * Acting - creating, hosting, joining, managing - still requires the
     * allocation to be in force NOW, through canLecturerManage/Host/Join. No
     * authority to act is relaxed here.
     */
    public function lecturerVisibleClassIdsQuery(User $user, int $schoolId): Builder
    {
        $query = DB::table('live_classes as access_lc')
            ->join('course_offering_lecturer_allocations as access_alloc', function ($join): void {
                $join->on('access_alloc.course_offering_id', '=', 'access_lc.course_offering_id')
                    ->on('access_alloc.school_id', '=', 'access_lc.school_id');
            })
            ->where('access_lc.school_id', $schoolId)
            ->where('access_alloc.school_id', $schoolId)
            ->where('access_alloc.user_id', $user->id)
            ->whereIn('access_alloc.role', CourseOfferingLecturerAllocation::ROLES)
            ->whereIn('access_alloc.status', [CourseOfferingLecturerAllocation::STATUS_ACTIVE, CourseOfferingLecturerAllocation::STATUS_ENDED])
            // Query builder (not the Eloquent builder): this is a DB::table() chain.
            // The type hint was wrong, which made this authorization unusable.
            ->where(function (\Illuminate\Database\Query\Builder $q): void {
                $q->whereNull('access_alloc.ends_on')
                    ->orWhereRaw('COALESCE(DATE(access_lc.scheduled_at), access_lc.start_date) <= access_alloc.ends_on');
            })
            ->select('access_lc.id');

        if (! $this->hasCapability($user, self::VIEW_CAPABILITY)) {
            $query->whereRaw('1 = 0');
        }

        // The outer select MUST name exactly one column.
        //
        // This builder is fed straight back into `whereIn('id', ...)` by
        // LiveClassController::index(), which makes it an IN operand. Leaving
        // the select unset compiled `select * from live_classes where ...`, so
        // the operand returned every column of the table and MySQL/MariaDB
        // refused the whole request with SQLSTATE 21000 / error 1241
        // ("Operand should contain 1 column(s)"). Because the failure only
        // occurs where a lecturer's visibility sub-query is used, and tenant
        // admins take the `orWhereNotNull('course_offering_id')` branch
        // instead, this 500 was invisible to admins and hit every lecturer's
        // Live Classes page.
        //
        // Selecting the id also makes the method honest: it is named ...IdsQuery.
        // The one caller that needs hydrated LiveClass models asks for them
        // explicitly with ->select('live_classes.*').
        return LiveClass::query()->whereIn('id', $query)->select('live_classes.id');
    }

    public function withinJoinWindow(LiveClass $class, ?Carbon $now = null): bool
    {
        if (! $class->scheduled_at || ! $class->ends_at) return false;
        $now ??= now();
        return $now->betweenIncluded(
            $class->scheduled_at->copy()->subMinutes(15),
            $class->ends_at->copy()->addMinutes(15)
        );
    }

    public function offeringAllowsOperations(LiveClass $class): bool
    {
        $offering = $this->offering($class);
        return $offering !== null
            && in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true);
    }

    private function offering(LiveClass $class): ?CourseOffering
    {
        if (! $class->course_offering_id) return null;
        return CourseOffering::query()->where('school_id', $class->school_id)->whereKey($class->course_offering_id)->first();
    }

    private function studentOfferingPermitsHistoricalAccess(LiveClass $class): bool
    {
        $offering = $this->offering($class);
        if (! $offering) return false;
        if (in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) return true;

        // Once the Offering itself is completed, a student keeps read access to
        // classes that reached a real outcome - it happened, or it was
        // cancelled, and both are the academic record. A class that was never
        // concluded is deliberately NOT included here: the Offering is over and
        // nothing was ever asserted about this one, so there is no settled
        // history to show.
        return $offering->status === CourseOffering::STATUS_COMPLETED
            && $class->hasConclusiveOutcome();
    }

    private function meetingDate(LiveClass $class): string
    {
        return $class->scheduled_at?->toDateString() ?: $class->start_date?->toDateString() ?: now()->toDateString();
    }

    private function allocation(User $user, LiveClass $class, bool $operational, string $date, bool $host = false, bool $manager = true): ?CourseOfferingLecturerAllocation
    {
        if ((int) $user->school_id !== (int) $class->school_id || ! $this->offering($class)) return null;
        $query = CourseOfferingLecturerAllocation::query()->where('school_id', $class->school_id)
            ->where('course_offering_id', $class->course_offering_id)
            ->where('user_id', $user->id)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
        $query->whereIn('status', $operational
            ? [CourseOfferingLecturerAllocation::STATUS_ACTIVE]
            : [CourseOfferingLecturerAllocation::STATUS_ACTIVE, CourseOfferingLecturerAllocation::STATUS_ENDED]);
        if ($operational && $manager && ! $host) {
            $query->whereIn('role', [
                CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
                CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
            ]);
        }
        if ($host) {
            $query->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER]);
        }
        $found = $query->first();
        if ($found) return $found;

        // The allocation date gate refused. This is the ONLY gate an authorised
        // System Tester may relax, and only for a deliberately early-started
        // Offering: the identical query is re-run without the starts_on clause and
        // the row is then re-validated by SystemTesterAccess, which re-checks
        // tenant, identity, allocation status, Offering lifecycle and the early
        // start evidence. Host/join/publish rules above are untouched.
        return $this->testerPreStartAllocation($user, $class, $date, function () use ($user, $class, $operational, $date, $host, $manager) {
            $relaxed = CourseOfferingLecturerAllocation::query()->where('school_id', $class->school_id)
                ->where('course_offering_id', $class->course_offering_id)
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
            $relaxed->whereIn('status', $operational
                ? [CourseOfferingLecturerAllocation::STATUS_ACTIVE]
                : [CourseOfferingLecturerAllocation::STATUS_ACTIVE, CourseOfferingLecturerAllocation::STATUS_ENDED]);
            if ($operational && $manager && ! $host) {
                $relaxed->whereIn('role', [
                    CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
                    CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
                ]);
            }
            if ($host) {
                $relaxed->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER]);
            }

            return $relaxed->first();
        });
    }

    /**
     * Re-validates a pre-start allocation through the single testing authority.
     * Returns the allocation only if the allocation's ONLY failing condition is
     * the pre-start date, so a cancelled/ended record, a wrong tenant, an
     * un-early-started Offering or a missing grant can never slip through here.
     */
    private function testerPreStartAllocation(User $user, LiveClass $class, string $date, callable $resolve): ?CourseOfferingLecturerAllocation
    {
        $candidate = $resolve();
        if (! $candidate) {
            return null;
        }
        $offering = $this->offering($class);
        if (! $offering) {
            return null;
        }
        if (! $this->testers->onlyPreStartDateBlocks($user, $candidate, $date, $offering)) {
            return null;
        }

        return $candidate;
    }

    private function activeManagerAllocation(User $user, CourseOffering $offering, string $date): ?CourseOfferingLecturerAllocation
    {
        $query = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER])
            ->where('status', CourseOfferingLecturerAllocation::STATUS_ACTIVE)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));

        $found = $query->first();
        if ($found) return $found;

        $relaxed = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('user_id', $user->id)
            ->whereIn('role', [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER])
            ->where('status', CourseOfferingLecturerAllocation::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->first();

        if ($relaxed && $this->testers->onlyPreStartDateBlocks($user, $relaxed, $date, $offering)) {
            return $relaxed;
        }

        return null;
    }

    private function hasCapability(User $user, string $capability): bool
    {
        return app(PermissionService::class)->allows($user, $capability);
    }

    private function hasAnyCapability(User $user, array $capabilities): bool
    {
        return app(PermissionService::class)->allowsAny($user, $capabilities);
    }
}
