<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use Illuminate\Support\Collection;

/**
 * The public programme catalogue's category grouping.
 *
 * ── WHY A SEPARATE CLASS RATHER THAN A NEW COLUMN ───────────────────────────
 *
 * `programmes.level` ALREADY is the programme's real stored classification: an
 * ENUM on the table, validated on write by ProgrammeController against
 * Programme::LEVELS / ::LEVELS_LEGACY, and used by AdmissionsController to
 * decide which document requirements apply. A candidate's category can therefore
 * be derived from data that already exists and is already trusted by the
 * academic side.
 *
 * Adding a `category` column would create a SECOND classification that has to be
 * kept in step with the first, and the moment an administrator changes a
 * programme's level in one place and not the other, the public site advertises a
 * programme under a category the registry disagrees with. Deriving instead means
 * there is exactly one fact and no synchronisation problem to have.
 *
 * What this class adds is only PRESENTATION: the levels PIIE actually offers are
 * grouped into the categories a prospective student browses by. It never changes
 * what is stored, never writes anything, and adding a level to a category is a
 * one-line change here rather than a data migration.
 *
 * ── WHY THE GROUPS ARE WHAT THEY ARE ────────────────────────────────────────
 *
 * Each grouping maps whole stored values, never substrings:
 *
 *   postgraduate  'Masters'                     — the postgraduate degrees offered
 *   bachelors    'Bachelors', 'Degree'         — 'Degree' is the legacy spelling
 *                                                  of a bachelor's award; both are
 *                                                  real enum values in use
 *   diploma       'Diploma', 'PGD'             — PGD is a Postgraduate Diploma,
 *                                                  so it is a diploma award
 *   certificate   'Certificate'
 *   other         'Short Course', 'PhD'        — doctoral study and short
 *                                                  courses are genuinely neither
 *                                                  degree nor diploma nor
 *                                                  certificate
 *
 * Any level this class does not recognise falls into `other` rather than
 * disappearing, so a newly added enum value is visible on the site immediately
 * instead of vanishing from it.
 */
final class ProgrammeCategories
{
    public const POSTGRADUATE = 'postgraduate';
    public const BACHELORS    = 'bachelors';
    public const DIPLOMA      = 'diploma';
    public const CERTIFICATE  = 'certificate';
    public const OTHER        = 'other';

    /**
     * Category slug => [label, stored levels], in the order they are rendered.
     *
     * `other` is last so the four named award types lead the page.
     *
     * @var array<string, array{label: string, levels: list<string>}>
     */
    private const CATEGORIES = [
        self::POSTGRADUATE => [
            'label'  => 'Master’s Programmes',
            'levels' => ['Masters'],
        ],
        self::BACHELORS => [
            'label'  => 'Bachelor’s Degree Programmes',
            'levels' => ['Bachelors', 'Degree'],
        ],
        self::DIPLOMA => [
            'label'  => 'Diploma Programmes',
            'levels' => ['Diploma', 'PGD'],
        ],
        self::CERTIFICATE => [
            'label'  => 'Certificate Programmes',
            'levels' => ['Certificate'],
        ],
        self::OTHER => [
            'label'  => 'Other Programmes',
            'levels' => ['Short Course', 'PhD'],
        ],
    ];

    /** Lookup of every stored level to its category, built once per request. */
    private static ?array $index = null;

    /**
     * Every category, in render order.
     *
     * Returned unconditionally — including categories with no programmes — so the
     * public page can show a section per award type. An empty section states that
     * nothing is offered yet, which is information; a missing section looks like
     * an oversight.
     *
     * @return array<string, array{label: string, levels: list<string>}>
     */
    public static function all(): array
    {
        return self::CATEGORIES;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function label(string $slug): ?string
    {
        return self::CATEGORIES[$slug]['label'] ?? null;
    }

    /**
     * The category a stored `programmes.level` belongs to.
     *
     * Comparison is case-insensitive and whitespace-tolerant because `level` is
     * free-form text on legacy CMS cards and an ENUM on academic Programmes, and
     * the two have not always agreed on capitalisation.
     */
    public static function forLevel(?string $level): string
    {
        if ($level === null) {
            return self::OTHER;
        }

        $needle = mb_strtolower(trim($level));

        foreach (self::levelIndex() as $stored => $slug) {
            if (mb_strtolower($stored) === $needle) {
                return $slug;
            }
        }

        return self::OTHER;
    }

    /**
     * Partition catalogue cards into categories, preserving render order.
     *
     * @param  Collection<int, array<string,mixed>>  $cards
     * @return array<string, Collection<int, array<string,mixed>>>
     */
    public static function group(Collection $cards): array
    {
        $grouped = [];

        foreach (self::CATEGORIES as $slug => $definition) {
            $grouped[$slug] = collect();
        }

        foreach ($cards as $card) {
            $grouped[self::forLevel($card['level'] ?? null)]->push($card);
        }

        return $grouped;
    }

    /**
     * Distinct levels actually present in a set of cards.
     *
     * Used by the catalogue page's level filter, which offers only what a
     * candidate can actually select.
     *
     * @param  Collection<int, array<string,mixed>>  $cards
     * @return Collection<int, string>
     */
    public static function levelsIn(Collection $cards): Collection
    {
        return $cards
            ->pluck('level')
            ->filter()
            ->map(fn ($level) => (string) $level)
            ->unique()
            ->sort()
            ->values();
    }

    /** Every level value the academic side can store, current and legacy. */
    public static function storedLevels(): array
    {
        return array_merge(Programme::LEVELS, Programme::LEVELS_LEGACY);
    }

    /** @return array<string, string> stored level => category slug */
    private static function levelIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (self::CATEGORIES as $slug => $definition) {
            foreach ($definition['levels'] as $level) {
                $index[$level] = $slug;
            }
        }

        return self::$index = $index;
    }
}