<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class CourseOffering extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'school_id' => 'integer',
        'subject_id' => 'integer',
        'academic_year_id' => 'integer',
        'academic_period_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new DomainException('Course Offerings may only be created through CourseOfferingService.');
        });

        static::updating(function (self $offering): void {
            throw new DomainException('Course Offerings may only be changed through CourseOfferingService.');
        });

        static::deleting(function (self $offering): void {
            throw new DomainException('Course Offerings cannot be deleted; cancel the Offering to preserve history.');
        });
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id')->whereKey($this->school_id);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id')->where('school_id', $this->school_id);
    }

    /**
     * Academic Year and Academic Period for this Offering.
     *
     * The tenant constraint is applied only when the owning row is actually
     * available, i.e. on lazy access. It used to be applied unconditionally at
     * relation-definition time from `$this->school_id`, which silently broke
     * EVERY eager load: to eager-load a relation Laravel instantiates a fresh,
     * empty parent and calls this method on it, so `$this->school_id` was null
     * and the relation compiled to `where school_id is null`, matching nothing.
     * `CourseOffering::with('academicYear')` therefore returned null on a
     * perfectly valid row, and any screen eager-loading these relations showed
     * blank academic context. This is why the Live Class detail page could not
     * show "2026/2027 / Semester 1" no matter how correct the underlying data
     * was.
     *
     * The guard is not lost where it matters: the foreign key already pins one
     * specific row, and every CourseOffering query is itself tenant-scoped, so
     * skipping the constraint on a fresh parent cannot surface another
     * institution's calendar.
     */
    public function academicYear()
    {
        $relation = $this->belongsTo(AcademicYear::class, 'academic_year_id');

        return $this->school_id !== null
            ? $relation->where('school_id', $this->school_id)
            : $relation;
    }

    public function academicPeriod()
    {
        $relation = $this->belongsTo(AcademicPeriod::class, 'academic_period_id');

        return $this->school_id !== null
            ? $relation->where('school_id', $this->school_id)
            : $relation;
    }

    public function applicability()
    {
        return $this->hasMany(CourseOfferingCurriculumMembership::class, 'course_offering_id')
            ->where('school_id', $this->school_id);
    }

    /**
     * Who is allocated to teach this Offering.
     *
     * ── WHY THE TENANT CONSTRAINT IS A SUBQUERY AND NOT A SCALAR ───────────
     *
     * This relation used to read:
     *
     *     ->where('school_id', $this->school_id)
     *
     * which is correct when the relation is reached as `$offering->relation()`, and
     * SILENTLY EMPTY when it is eager loaded. Eloquent builds an eager-load
     * relation from `$model->newInstance()` - a fresh model with no attributes - so
     * `$this->school_id` is null at that moment and the constraint becomes
     * `where school_id is null`, which matches nothing.
     *
     * So `CourseOffering::with('lecturerAllocations')` returned an empty
     * collection for every Offering in the product, with no error and no warning.
     * It failed CLOSED, which is the safe direction, and it failed SILENTLY, which
     * is how a tenant-shaped constraint goes unnoticed for as long as nobody
     * eager-loads the relation - and a Course Home that names no lecturers at all
     * is exactly the kind of wrong that reads as plausible.
     *
     * ── WHY NOT `whereColumn` ─────────────────────────────────────────────
     *
     * The obvious repair - compare the two columns - breaks the OTHER path. A
     * direct `$offering->lecturerAllocations()` query has only the child table in
     * its FROM, so `course_offerings.school_id` is an unknown table and the query
     * errors. A constraint that is right under eager loading and broken under
     * direct access is not a fix.
     *
     * ── WHY A SUBQUERY IS THE SHAPE THAT WORKS IN BOTH ───────────────────
     *
     * The subquery reads the school from the parent row that the CHILD's own
     * foreign key names, and it is evaluated by the database in both paths. It
     * therefore states the same rule with no dependence on a PHP-side value that
     * may or may not be loaded:
     *
     *     this allocation row must belong to the same tenant as its Offering
     *
     * That is the tenant constraint, and it is now actually enforced on the
     * eager-load path where it previously was not.
     *
     * The same latent fault is worth naming for `applicability()` above and for
     * `curriculumMemberships()`'s `wherePivot` - both close over `$this`. They are
     * left alone here because nothing eager-loads them today, and a fix nobody has
     * asked for is a change nobody has tested. This one was found by a test that
     * asserted a lecturer's name appeared on a page.
     */
    public function lecturerAllocations()
    {
        return $this->hasMany(CourseOfferingLecturerAllocation::class, 'course_offering_id')
            ->whereIn('course_offering_lecturer_allocations.school_id', function ($query): void {
                $query->select('school_id')
                    ->from('course_offerings')
                    ->whereColumn(
                        'course_offerings.id',
                        'course_offering_lecturer_allocations.course_offering_id'
                    );
            });
    }

    public function curriculumMemberships()
    {
        return $this->belongsToMany(
            CurriculumMembership::class,
            'course_offering_curriculum_memberships',
            'course_offering_id',
            'curriculum_membership_id'
        )->withPivot(['school_id', 'curriculum_id', 'subject_id', 'created_at', 'updated_at'])
            ->wherePivot('school_id', $this->school_id);
    }
}
