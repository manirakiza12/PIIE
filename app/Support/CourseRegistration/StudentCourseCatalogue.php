<?php

namespace App\Support\CourseRegistration;

use App\Models\CourseOffering;
use App\Support\CourseOffering\CourseCoverImage;
use Illuminate\Support\Collection;

/**
 * Turns the discovery view model into the card catalogue the student sees.
 *
 * ── WHY A SEPARATE CLASS AND NOT LOGIC IN THE BLADE FILE ────────────────────
 *
 * Filtering, searching and the cover decision are rules with real edge cases. In a
 * template they can only be checked by rendering a page, which makes them hard to
 * test exhaustively and easy to get subtly wrong. Here they are pure functions
 * over an array, so every rule below is asserted directly.
 *
 * ── WHAT THIS CLASS IS DELIBERATELY NOT ────────────────────────────────────
 *
 * It adds NO query and NO eligibility rule of its own. Everything it returns was
 * already decided by `StudentCourseOfferingDiscovery::discover()`, which had
 * already narrowed the Offerings to the student's curriculum, year, period and
 * cohort, and had already fetched only this student's own registrations.
 *
 * That is the point of the search and filters being applied HERE rather than in
 * the database: a student cannot widen their own view of the catalogue by editing
 * a query string, because there is no query to widen. Filtering a collection
 * that was already authorised cannot leak a row that was not already theirs.
 *
 * It also means the year/period filters are NOT a cross-year query. They narrow
 * the rows `discover()` already returned, which is why a dropped registration
 * reappearing under a different year's filter cannot grant any access: the drop
 * is still a drop, and every action below still routes through the same
 * controller checks it always did.
 */
final class StudentCourseCatalogue
{
    /**
     * Section key => [heading, empty-state message].
     *
     * The headings are the ones the page already used. Renaming a workflow state
     * mid-redesign is how a student stops recognising their own registration, and
     * `CourseRegistrationOfferingFoundationTest` asserts one of these strings.
     */
    private const SECTIONS = [
        'available' => ['Available to Register', 'No Course Offering is currently available for this period.'],
        'pending' => ['Registered — Pending Confirmation', 'No registrations are waiting for confirmation.'],
        'confirmed' => ['Confirmed Courses', 'No confirmed courses for this academic history.'],
        'history' => ['Dropped / History', 'No dropped Offering registrations.'],
    ];

    /**
     * Build the catalogue.
     *
     * @param  array  $view   the array from `StudentCourseOfferingDiscovery::discover()`
     * @param  array<string,string>  $filters  ['q' => ..., 'year' => ..., 'period' => ...]
     * @return array<string,mixed>
     */
    public function build(array $view, array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $year = trim((string) ($filters['year'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));

        $financeEligible = (bool) ($view['finance_eligible'] ?? false);

        $confirmedRows = $view['confirmed'] ?? collect();

        // Resolved ONCE, before any card is built, and only for the confirmed rows
        // that are about to become cards.
        $covered = $this->coveredOfferings(collect($confirmedRows));

        $offerings = $this->cards($view['offerings'] ?? collect(), 'available', $financeEligible, $covered);
        $pending = $this->cards($view['pending'] ?? collect(), 'pending', $financeEligible, $covered);
        $confirmed = $this->cards($confirmedRows, 'confirmed', $financeEligible, $covered);
        $history = $this->cards($view['history'] ?? collect(), 'history', $financeEligible, $covered);

        // Option lists are taken from the cards BEFORE filtering, so the choices
        // never depend on what happens to be selected: picking a year must not
        // remove that year from the dropdown.
        $years = $this->options(collect([$offerings, $pending, $confirmed, $history])->flatten(1)->pluck('year'));
        $periods = $this->options(collect([$offerings, $pending, $confirmed, $history])->flatten(1)->pluck('period'));

        $match = fn (Collection $cards) => $this->filter($cards, $q, $year, $period);

        $sections = [];
        $total = 0;
        $matched = 0;

        foreach (['available' => $offerings, 'pending' => $pending, 'confirmed' => $confirmed, 'history' => $history] as $key => $cards) {
            $visible = $match($cards);
            $total += $cards->count();
            $matched += $visible->count();

            $sections[$key] = [
                'key' => $key,
                'heading' => self::SECTIONS[$key][0],
                'empty_state' => self::SECTIONS[$key][1],
                'cards' => $visible,
                'total' => $cards->count(),
                // Distinguishes "you have nothing here" from "the filter hid
                // everything you have". Showing the wrong one is misleading.
                'filtered_empty' => $cards->isNotEmpty() && $visible->isEmpty(),
            ];
        }

        return [
            'sections' => $sections,
            'filters' => [
                'q' => $q,
                'year' => $year,
                'period' => $period,
                'years' => $years,
                'periods' => $periods,
            ],
            'has_filter' => $q !== '' || $year !== '' || $period !== '',
            'total_count' => $total,
            'matching_count' => $matched,
            'finance_eligible' => $financeEligible,
        ];
    }

    /**
     * Map discovery rows to card arrays.
     *
     * Rows are stdClass, not models: `discover()` builds them with the query
     * builder, and they are deliberately read-only here. Nothing is written, so
     * there is no academic record to duplicate.
     *
     * @param  array<int,true>  $covered  offering ids the viewer may be shown a cover for
     * @return Collection<int,array<string,mixed>>
     */
    private function cards($rows, string $section, bool $financeEligible, array $covered = []): Collection
    {
        return collect($rows)->map(function ($row) use ($section, $financeEligible, $covered): array {
            $offeringId = (int) ($row->offering_id ?? 0);
            $team = collect($row->teaching_team ?? []);

            $card = [
                'key' => $section.':'.((int) ($row->id ?? 0)).':'.$offeringId,
                'section' => $section,
                'offering_id' => $offeringId,
                'registration_id' => $section === 'available' ? null : (int) ($row->id ?? 0),
                'title' => (string) ($row->subject_name ?? ''),
                'code' => (string) ($row->subject_code ?? ''),
                'reference' => (string) ($row->reference ?? ''),
                'credits' => $row->registered_credits ?? $row->membership_credits ?? null,
                'classification' => (string) (
                    $row->registered_classification
                    ?? $row->membership_classification
                    ?? ''
                ),
                'year' => (string) ($row->academic_year_label ?? ''),
                'period' => (string) ($row->academic_period_label ?? ''),
                'period_type' => (string) ($row->academic_period_type ?? ''),
                'offering_status' => (string) ($row->offering_status ?? ''),
                'lecturer' => (string) ($row->primary_lecturer ?? ''),
                'team' => $team->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),

                'status' => $section,
                'status_label' => match ($section) {
                    'available' => 'Available to Register',
                    'pending' => 'Pending confirmation',
                    'confirmed' => 'Confirmed',
                    default => 'Dropped',
                },
                'status_tone' => match ($section) {
                    'available' => 'info',
                    'pending' => 'warning',
                    'confirmed' => 'success',
                    default => 'secondary',
                },
                'action' => $this->actionFor($section, $financeEligible),

                /**
                 * The cover, and WHY it is this narrow.
                 *
                 * `CourseCoverImage::assertMayView()` admits a student only on a
                 * CONFIRMED registration — a rule that `CourseOfferingCoverImageTest`
                 * pins with `test_a_student_awaiting_confirmation_cannot_read_the_cover`.
                 *
                 * So a cover is offered here on confirmed cards and nowhere else. A
                 * pending registration, an Offering the student has not registered
                 * for, and a dropped one would each render an `<img>` whose URL the
                 * server answers 404 — a broken image in the card grid.
                 *
                 * The alternative, widening `assertMayView()` to include pending and
                 * eligible students, would be a real change to who may read stored
                 * bytes, and it is not this phase's decision to make. The fallback
                 * card is the honest answer instead.
                 *
                 * `has_cover` is a boolean. The stored path is not read here, not
                 * passed to the view, and not rendered — the view builds the URL from
                 * `route('student.courses.cover', ...)`, so no private storage location
                 * can reach the page even by accident.
                 */
                'has_cover' => $section === 'confirmed' && isset($covered[$offeringId]),

                // Deterministic fallback tone, so a course keeps the same colour
                // between page loads instead of flickering between the four.
                'tone' => self::TONES[abs(crc32($offeringId.':'.($row->subject_name ?? ''))) % 4],
            ];

            return $card;
        })->values();
    }

    /** The one action offered on a card, decided from state the server already knows. */
    private function actionFor(string $section, bool $financeEligible): string
    {
        return match ($section) {
            'available' => 'register',
            // Confirmation is gated on financial eligibility exactly as the table
            // was: the card must not offer a button the controller will refuse.
            'pending' => $financeEligible ? 'confirm' : 'blocked',
            'confirmed' => 'open',
            default => 'none',
        };
    }

    /**
     * Search and filter an already-authorised set.
     *
     * Matching on the label rather than the id is deliberate: the label is what the
     * card shows, so a filter that visibly does not apply is impossible.
     *
     * @param  Collection<int,array<string,mixed>>  $cards
     * @return Collection<int,array<string,mixed>>
     */
    private function filter(Collection $cards, string $q, string $year, string $period): Collection
    {
        return $cards->filter(function (array $card) use ($q, $year, $period): bool {
            if ($year !== '' && $card['year'] !== $year) {
                return false;
            }
            if ($period !== '' && $card['period'] !== $period) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [
                $card['title'], $card['code'], $card['reference'], $card['lecturer'],
                implode(' ', $card['team']), $card['year'], $card['period'],
                $card['status_label'],
            ]));

            // Every whitespace-separated term must appear, so "bba semester" finds
            // the course without needing a phrase search.
            foreach (preg_split('/\s+/', mb_strtolower($q)) ?: [] as $term) {
                if ($term !== '' && ! str_contains($haystack, $term)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int,string>  $values
     * @return array<int,string>
     */
    private function options(Collection $values): array
    {
        return $values->filter()->unique()->sort()->values()->all();
    }

    private const TONES = ['a', 'b', 'c', 'd'];

    public function __construct(
        private readonly int $schoolId,
    ) {}

    /**
     * Which of the student's confirmed Offerings actually carry a cover?
     *
     * ── WHY THIS IS A LOOKUP AND NOT A COLUMN IN `discover()` ────────────────
     *
     * The obvious move is to add `o.cover_image_path` to the discovery query's
     * select list. That was tried, and it breaks: 24 test fixtures build
     * `course_offerings` from a hand-written schema that predates the cover
     * columns, and a missing column in a select is a hard SQL error, not a null.
     * Editing 24 fixtures to accommodate a card grid is the wrong trade — it puts
     * a presentation concern into the core registration service, which
     * `assignmentMembershipForOffering()` also calls on the write path.
     *
     * Hydrating the model instead is robust by construction: on a schema without
     * the column the attribute is simply absent, `hasCover()` reads it as false,
     * and the card falls back. Degrading to a fallback is the correct behaviour
     * for a missing image anyway.
     *
     * Scoped twice over — to this school, and to the Offering ids the student has
     * a CONFIRMED registration on — so it cannot reveal that an unrelated Offering
     * has a cover.
     *
     * @param  Collection<int,\stdClass>  $confirmedRows
     * @return array<int,true>
     */
    private function coveredOfferings(Collection $confirmedRows): array
    {
        $ids = $confirmedRows->pluck('offering_id')->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $covers = app(CourseCoverImage::class);

        return CourseOffering::query()
            ->where('school_id', $this->schoolId)
            ->whereIn('id', $ids->all())
            ->get()
            ->filter(fn (CourseOffering $offering) => $covers->hasCover($offering))
            ->mapWithKeys(fn (CourseOffering $offering) => [(int) $offering->id => true])
            ->all();
    }
}