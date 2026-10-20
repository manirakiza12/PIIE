<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'code', 'name', 'level', 'duration', 'mode',
        'tuition_fee', 'department_id', 'is_active',
        'description', 'curriculum_overview', 'career_prospects',
        // Catalogue publication (added 2026_10_04_000003). `is_active` above
        // governs whether the programme is OFFERED academically; `is_published`
        // governs whether it is ADVERTISED. They are separate on purpose: a
        // programme can be taught and not yet marketed, and making marketing a
        // precondition of teaching is not a decision a schema should make.
        'tuition_currency', 'tuition_fee_basis',
        'is_published', 'website_sort_order',
    ];

    /**
     * Columns deliberately NOT fillable, because they are owned by a service and
     * must never arrive from a request body.
     *
     *   cover_image_*     written only by ProgrammeCoverImage
     *   published_at      written only by ProgrammePublisher
     *   website_item_id   written only by ProgrammePublisher
     *
     * `website_item_id` is the interesting one: it is the pointer that makes
     * publishing idempotent, so a crafted `website_item_id` in a form post could
     * aim one institution's publish at another tenant's CMS row. The publisher
     * re-checks tenant and marker before following it, and the column is not
     * mass-assignable at all. Two independent guards, because the cost of the
     * second one is zero.
     */

    protected $casts = [
        'is_active'           => 'boolean',
        'is_published'        => 'boolean',
        'tuition_fee'         => 'decimal:2',
        'website_sort_order'  => 'integer',
        'website_item_id'     => 'integer',
        'cover_image_size'    => 'integer',
        'published_at'        => 'datetime',
        'cover_image_updated_at' => 'datetime',
    ];

    /**
     * Programmes that may appear on the public website.
     *
     * Publication is NOT sufficient on its own: an academically inactive
     * programme must not be advertised, because a withdrawn qualification that
     * still takes applications is a worse outcome than a missing card. The
     * `is_active` term is therefore part of the scope, not an oversight.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', 1)->where('is_active', 1);
    }

    /**
     * Public catalogue ordering: explicit order first, then name.
     *
     * Unpositioned programmes sort after every explicitly ordered one, so adding a
     * programme never silently reshuffles the ones an administrator has
     * deliberately placed.
     *
     * Falls back to plain alphabetical order when the catalogue columns are not
     * there yet. A public page returning 500 is the worst possible failure mode for
     * a missing column, and this scope sits on the homepage's read path.
     */
    public function scopeWebsiteOrder($query)
    {
        if (! app(\App\Support\ProgrammeCatalogue\ProgrammeCatalogueSchema::class)->columnsExist()) {
            return $query->orderBy('name');
        }

        return $query->orderByRaw('website_sort_order IS NULL')
            ->orderBy('website_sort_order')
            ->orderBy('name');
    }

    /** Client-preferred Level/Mode option lists. */
    public const LEVELS = ['HEC', 'Certificate', 'Diploma', "Bachelor's", 'Masters', 'PhD', 'Professional Course', 'Short course'];
    public const LEVELS_LEGACY = ['Degree', 'Bachelors', 'PGD', 'Short Course'];
    public const MODES = ['Day', 'Evening', 'Weekend', 'ODEL/Blended', 'ODEL'];
    public const MODES_LEGACY = ['fulltime', 'parttime', 'online', 'blended'];

    /** Keep an existing legacy value visible while editing its record. */
    public static function selectableModes(?string $existing = null): array
    {
        $modes = self::MODES;
        if ($existing !== null && $existing !== '' && ! in_array($existing, $modes, true)) {
            $modes[] = $existing;
        }

        return $modes;
    }

    /** Keep a programme's existing legacy category visible while editing. */
    public static function selectableLevels(?string $existing = null): array
    {
        $levels = self::LEVELS;
        if ($existing !== null && $existing !== '' && ! in_array($existing, $levels, true)) {
            $levels[] = $existing;
        }

        return $levels;
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** The former School/Faculty row retained for manual programme correction. */
    public function legacyDepartment()
    {
        return $this->belongsTo(Department::class, 'legacy_department_id');
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class, 'programme_id');
    }

    public function curricula()
    {
        return $this->hasMany(Curriculum::class, 'programme_id');
    }

    public function programmeCohorts()
    {
        return $this->hasMany(ProgrammeCohort::class, 'programme_id');
    }

    public function studentCurriculumAssignments()
    {
        return $this->hasMany(StudentCurriculumAssignment::class, 'programme_id')->where('school_id', $this->school_id);
    }

    public function admissions()
    {
        return $this->hasMany(Admission::class, 'programme_id');
    }

    public function liveClasses()
    {
        return $this->hasMany(LiveClass::class, 'programme_id');
    }

    /**
     * The one reliable "currently enrolled" signal — see StudentProfile,
     * which is kept in sync at student-creation/enrollment time (unlike
     * Admission.programme_id, which reflects the original application and
     * is never updated afterward).
     */
    public function studentProfiles()
    {
        return $this->hasMany(StudentProfile::class, 'programme_id');
    }

    public function activeStudentCount(): int
    {
        return $this->studentProfiles()->where('status', 'active')->count();
    }
}
