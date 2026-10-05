<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use App\Models\WebsiteItem;
use App\Support\PublicTenantResolver;
use Illuminate\Support\Collection;

/**
 * The ONE read path the public programme catalogue uses.
 *
 * ── WHY THE ACADEMIC RECORD IS THE PRIMARY SOURCE ──────────────────────────
 *
 * Until Stage 2 the public site read `website_items`, which meant every published
 * programme was typed twice: once into `programmes` for the academic side and once
 * into the CMS for the marketing side. Two copies of one qualification cannot stay
 * true, and the copy a candidate reads is the one that wins.
 *
 * So the read order is inverted. A published `Programme` is the record. The CMS
 * projection it maintains is no longer what the catalogue renders — it exists only so
 * the marketing CMS keeps working for administrators who work there.
 *
 * ── WHY LEGACY CMS ROWS ARE STILL SHOWN ─────────────────────────────────────
 *
 * The `programme_catalog_*` sections hold 67 hand-authored programme cards. Deleting
 * or hiding them would remove real published content an administrator wrote, which
 * this stage is explicitly not authorised to do. They are therefore still rendered —
 * but ONLY those that are not already represented by a published Programme, and they
 * are ranked below the academic records.
 *
 * The practical effect: nothing disappears, and nothing is listed twice.
 *
 * ── WHAT CANNOT APPEAR, AND WHY IT IS ENFORCED HERE RATHER THAN IN A VIEW ───
 *
 *   unpublished          `is_published = 0`
 *   deactivated          `is_active = 0`
 *   another institution  every query is tenant-scoped, resolved server-side
 *   no title             a card with no title is not a programme; it is a stub
 *
 * A view is the wrong place for these: a template change can drop a `where`, and a
 * public catalogue is exactly the surface where a dropped filter becomes a data
 * disclosure. The rules live in the query, and a test asserts the rendered output
 * rather than trusting the filter to still be there.
 */
class PublicProgrammeCatalogue
{
    /** The four sections the public catalogue aggregates. */
    public const CATALOGUE_SECTIONS = [
        'programme_catalog_graduate_school',
        'programme_catalog_business_management',
        'programme_catalog_humanities',
        'programme_catalog_education',
    ];

    /**
     * Homepage block size: four columns across two rows.
     *
     * A maximum, never a target. Three published programmes render as three cards
     * in a four-column grid and the row simply ends short — which is the honest
     * result. Padding the block to eight with invented or repeated cards would be a
     * lie about what the institution offers.
     */
    public const HOMEPAGE_LIMIT = 8;

    /** Catalogue page size. Twelve fills three rows of four with one to spare. */
    public const PER_PAGE = 12;

    /**
     * URL prefixes that must never appear as a public programme CTA.
     *
     * A CMS `link` field is free text typed by an administrator, and the same field
     * is used across every section. A link to an admin or student route is not a
     * broken link for a visitor, it is a dead end at a login screen — or worse, an
     * invitation to try credentials. Anything matching these is refused and replaced
     * with the apply path.
     */
    private const INTERNAL_PREFIXES = [
        'admin', 'student', 'teacher', 'superadmin', 'staff', 'parent', 'accountant',
        'librarian', 'warden', 'registrar', 'bursar', 'hod', 'director', 'hr_manager',
        'procurement', 'store_keeper', 'receptionist', 'examinations', 'admissions_staff',
        'api', 'login', 'logout', 'register', 'dashboard', 'course-offerings',
    ];

    public function __construct(private ProgrammeCoverImage $covers) {}

    /**
     * Every programme card the public site may show, academic records first.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function cards(?int $schoolId = null, ?int $limit = null): Collection
    {
        $schoolId ??= PublicTenantResolver::resolveSchoolId();

        // Academic Programmes can ONLY be shown for a resolved tenant, because every
        // one of them belongs to a school. With no tenant resolved there are no
        // academic programmes to show, and falling back to "the first school" here
        // would be exactly the cross-tenant leak this service exists to prevent.
        $academic = $schoolId ? $this->academicCards($schoolId) : collect();

        // Legacy CMS cards are different: the catalogue sections have always held
        // GLOBAL rows (`school_id IS NULL`) that were visible without a tenant, and
        // the public site showed them. They keep doing so. Restricting them to a
        // resolved tenant would silently empty the public catalogue on any
        // installation whose tenant setting is unset — a regression dressed as a
        // security fix.
        $legacy = $this->legacyCards($schoolId, $this->academicTitles ?? collect());

        $cards = $academic->concat($legacy)->values();

        return $limit !== null ? $cards->take($limit)->values() : $cards;
    }

    /** The homepage block. */
    public function homepageCards(?int $schoolId = null): Collection
    {
        return $this->cards($schoolId, self::HOMEPAGE_LIMIT);
    }

    /**
     * Published Programmes, in the order the academic administrator chose.
     *
     * `websiteOrder()` puts explicitly ordered programmes first and leaves the rest
     * after them, alphabetically, so adding a programme never silently reshuffles
     * the ones an administrator has deliberately positioned.
     */
    private function academicCards(int $schoolId): Collection
    {
        // Before the catalogue migration has run there are no published academic
        // programmes to read, and the query would fail on an unknown column. A public
        // page must never 500 for that, so the catalogue falls back to exactly the
        // behaviour that existed before Stage 2: the hand-authored CMS cards. Nothing
        // is hidden — the academic catalogue simply has not been migrated yet.
        if (! app(ProgrammeCatalogueSchema::class)->columnsExist()) {
            $this->academicTitles = collect();

            return collect();
        }

        $programmes = Programme::query()
            ->published()
            ->where('school_id', $schoolId)
            ->with('department')
            ->websiteOrder()
            ->get();

        // Recorded here and consumed by legacyCards(), which must know which titles
        // the academic side already occupies before it can decide what is a
        // duplicate. An empty title is excluded: a card with no name is a stub, and
        // de-duplicating on "" would let the first untitled row suppress the rest.
        $this->academicTitles = $programmes
            ->map(fn (Programme $programme) => $this->normaliseTitle((string) $programme->name))
            ->filter()
            ->flip()
            ->collect();

        return $programmes
            ->map(fn (Programme $programme) => $this->academicCard($programme))
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function academicCard(Programme $programme): array
    {
        $price = ProgrammePrice::forDisplay($programme);
        $faculty = $programme->department?->name;

        return [
            'key'            => 'programme:'.$programme->id,
            'origin'         => 'academic',
            'programme_id'   => (int) $programme->id,

            'title'          => (string) $programme->name,
            'level'          => (string) $programme->level,
            'mode'           => $programme->mode ? (string) $programme->mode : null,
            'duration'       => $programme->duration ? (string) $programme->duration : null,
            'code'           => $programme->code ? (string) $programme->code : null,

            // A published Programme has no prose of its own. The CMS projection may
            // carry one an administrator wrote; when it does, it is shown, because
            // inventing a description from a title would be filler.
            'excerpt'        => $this->projectionExcerpt($programme),

            'faculty_key'    => $programme->department_id ? 'dept:'.$programme->department_id : null,
            'faculty_label'  => $faculty ?: 'Unassigned',

            'image'          => $this->covers->url($programme),
            'fallback'       => $faculty ?: $programme->level,

            'price'          => $price,
            'contact_label'  => ProgrammePrice::contactLabel(),

            'link'           => $this->ctaFor($programme),
        ];
    }

    /**
     * Hand-authored CMS programme cards that no published Programme represents.
     *
     * Skipped when the row carries a `programme_id` marker — that row is Stage 1's
     * projection, and rendering it alongside its Programme is precisely the duplicate
     * this stage exists to prevent.
     */
    private function legacyCards(?int $schoolId, Collection $academicTitles): Collection
    {
        return WebsiteItem::query()
            ->whereIn('section_key', self::CATALOGUE_SECTIONS)
            // This tenant's rows, or the global ones. Never another tenant's.
            ->where(function ($q) use ($schoolId) {
                $q->whereNull('school_id');

                if ($schoolId !== null) {
                    $q->orWhere('school_id', $schoolId);
                }
            })
            ->where('status', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->reject(function (WebsiteItem $item) use ($academicTitles): bool {
                if ($this->markerOf($item) !== null) {
                    return true; // A projection; its Programme is already listed.
                }

                // De-duplicate against the academic records by normalised title, so
                // a programme that exists in both places is listed once — from the
                // academic record, which is the authoritative copy.
                $key = $this->normaliseTitle((string) $item->title);

                return $key === '' || $academicTitles->has($key);
            })
            ->map(fn (WebsiteItem $item) => $this->legacyCard($item))
            ->values();
    }

    /**
     * Normalised titles of the published Programmes, for legacy de-duplication.
     *
     * Populated by academicCards(), which runs first. A value rather than a query
     * parameter so the two halves of `cards()` cannot be called out of order.
     */
    private Collection $academicTitles;

    /**
     * @return array<string,mixed>
     */
    private function legacyCard(WebsiteItem $item): array
    {
        $title = (string) $item->title;

        return [
            'key'           => 'legacy:'.$item->id,
            'origin'        => 'cms',
            'programme_id'  => null,

            'title'         => $title,
            'level'         => $item->subtitle ? (string) $item->subtitle : null,
            'mode'          => null,
            'duration'      => null,
            'code'          => null,

            'excerpt'       => $item->description
                ? \Illuminate\Support\Str::limit(strip_tags((string) $item->description), 130)
                : null,

            'faculty_key'   => $item->section_key,
            'faculty_label' => $this->facultyLabel((string) $item->section_key),

            'image'         => $this->legacyImage($item->image),
            'fallback'      => $this->facultyLabel((string) $item->section_key),

            // A legacy card carries no price metadata of its own, so it states none.
            // The CMS has no fee basis and inventing one here is exactly the guess
            // Stage 1 exists to prevent.
            'price'         => null,
            'contact_label' => ProgrammePrice::contactLabel(),

            'link'          => ($url = $this->safeLink($item->link)) === null
                ? ['url' => route('apply.form'), 'text' => 'Apply now']
                : ['url' => $url, 'text' => 'View programme'],
        ];
    }

    /**
     * The call to action for a published Programme.
     *
     * A projection's CMS `link` wins when it is safe, so an administrator can point
     * a programme at a prospectus PDF or an external page. Otherwise the visitor goes
     * to the application form — the action an applicant actually wants, rather than
     * a page that does not exist.
     *
     * The destination is validated either way: a link into an admin or student route
     * is refused, because a catalogue card pointing at a login screen is a dead end
     * that looks like a broken site.
     *
     * @return array{url:string,text:string}
     */
    private function ctaFor(Programme $programme): array
    {
        $link = $programme->website_item_id
            ? WebsiteItem::query()->value('link', $programme->website_item_id)
            : null;

        // A safe CMS link wins, so an administrator can point a programme at a
        // prospectus or an external page. Otherwise the visitor goes to the
        // application form — the action an applicant actually wants, rather than a
        // detail page that does not exist.
        $url = $this->safeLink($link);

        return $url === null
            ? ['url' => route('apply.form'), 'text' => 'Apply for this programme']
            : ['url' => $url, 'text' => 'View programme'];
    }

    /**
     * Validate a CMS-supplied destination.
     *
     * Returns the URL when it is safe to hand a stranger, or NULL when it is not —
     * so the caller chooses its own fallback rather than this method inventing one.
     *
     * @return string|null
     */
    private function safeLink(?string $link): ?string
    {
        $link = is_string($link) ? trim($link) : '';

        if ($link === '') {
            return null;
        }

        // Only http(s) and site-relative paths. This rejects javascript:, data:,
        // vbscript: and file: outright — a CMS `link` is free text typed by an
        // administrator, and rendering it unescaped into an href would be a stored
        // XSS vector on the public homepage.
        if (preg_match('#^(javascript|data|vbscript|file|ftp|mailto|tel):#i', $link)) {
            return null;
        }

        // An absolute URL is only accepted for http(s); anything else with a host
        // has already been refused above by the scheme check.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $link) && ! preg_match('#^https?://#i', $link)) {
            return null;
        }

        $path = (string) (parse_url($link, PHP_URL_PATH) ?: '');

        // An internal or authentication route is a dead end for a visitor: they
        // arrive at a login screen and conclude the site is broken. A card pointing
        // at /admin/... is also an invitation to try credentials, so these are
        // refused rather than merely discouraged.
        foreach (self::INTERNAL_PREFIXES as $prefix) {
            if (preg_match('#(^|/)'.preg_quote($prefix, '#').'(/|$)#i', $path)) {
                return null;
            }
        }

        return $link;
    }

    /**
     * The CMS projection's prose, when an administrator wrote any.
     *
     * Read through the projection rather than a copied column, so an edit made in
     * the CMS is picked up without a second mechanism.
     */
    private function projectionExcerpt(Programme $programme): ?string
    {
        if (! $programme->website_item_id) {
            return null;
        }

        $description = WebsiteItem::query()
            ->where('id', $programme->website_item_id)
            ->value('description');

        return $description ? \Illuminate\Support\Str::limit(strip_tags((string) $description), 130) : null;
    }

    /** A legacy CMS image, resolved only if the file actually exists. */
    private function legacyImage(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $clean = ltrim(str_replace(['assets/uploads/website/', 'uploads/website/'], '', (string) $path), '/');
        $absolute = public_path('assets/uploads/website/'.$clean);

        return is_file($absolute) ? asset('assets/uploads/website/'.$clean) : null;
    }

    private function facultyLabel(string $sectionKey): string
    {
        if ($sectionKey === '') {
            return 'PIIE';
        }

        return \Illuminate\Support\Str::of($sectionKey)
            ->replace('programme_catalog_', '')
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    private function markerOf(WebsiteItem $item): ?int
    {
        $meta = is_string($item->meta_json) ? json_decode($item->meta_json, true) : null;

        if (! is_array($meta) || ! isset($meta[ProgrammePublisher::META_KEY]) || ! is_numeric($meta[ProgrammePublisher::META_KEY])) {
            return null;
        }

        return (int) $meta[ProgrammePublisher::META_KEY];
    }

    /**
     * Titles compared without case, spacing or punctuation differences.
     *
     * "Bachelor of Science in Computer Science" and "bachelor of science in computer
     * science  " are the same qualification to a reader and must not be listed twice.
     */
    private function normaliseTitle(string $title): string
    {
        $collapsed = preg_replace('/[^a-z0-9]+/i', ' ', strtolower(trim($title)));

        return trim((string) $collapsed);
    }

    /**
     * Distinct levels available for the filter, academic records only.
     *
     * Legacy rows have no level of their own worth filtering on, and offering a
     * filter whose only result is "no programmes" is worse than not offering it.
     *
     * @return Collection<int,string>
     */
    public function levels(?int $schoolId = null): Collection
    {
        return $this->cards($schoolId)
            ->pluck('level')
            ->filter()
            ->map(fn ($level) => (string) $level)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Distinct faculties, as [key => label] for the filter control.
     *
     * @return array<string,string>
     */
    public function faculties(?int $schoolId = null): array
    {
        return $this->cards($schoolId)
            ->filter(fn ($card) => $card['faculty_key'] !== null)
            ->mapWithKeys(fn ($card) => [$card['faculty_key'] => $card['faculty_label']])
            ->unique()
            ->sortByDesc(fn ($label) => $label)
            ->all();
    }
}