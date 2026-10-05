<?php

namespace App\Support\ProgrammeCatalogue;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the Stage 1 catalogue columns are present on `programmes`.
 *
 * ── WHY THIS IS A RESOLVED SERVICE AND NOT A STATIC ─────────────────────────
 *
 * The obvious implementation is a `private static ?bool` on the model or the
 * controller, memoised so the information_schema probe runs once. That was the
 * first version, and it is wrong.
 *
 * A static outlives the application instance. The test suite boots a fresh
 * application per test but keeps ONE PHP process, so whichever test happened to
 * ask first decided the answer for every test after it — a test whose fixture has
 * the columns read "absent" and vice versa, producing failures that depended on
 * execution order rather than on anything the test did.
 *
 * Resolving this from the container gives a fresh instance per application, so the
 * memo is per-request in production and per-test in the suite. That is exactly the
 * lifetime the answer has.
 *
 * The probe itself is still worth memoising: without it, every public page render
 * and every programme save would hit information_schema.
 */
class ProgrammeCatalogueSchema
{
    private ?bool $columnsExist = null;

    /**
     * True once migration 2026_10_04_000003 has been applied.
     *
     * Checked on `website_sort_order` because it is the column the public ordering
     * cannot do without, so its absence means the whole feature is unavailable
     * rather than partially available.
     */
    public function columnsExist(): bool
    {
        return $this->columnsExist ??= Schema::hasColumn('programmes', 'website_sort_order');
    }

    /**
     * The catalogue-metadata keys, or an empty list when the columns are absent.
     *
     * Callers write the returned keys into a model update. Returning `[]` on an
     * unmigrated installation is what makes a rolling deploy degrade to "no
     * catalogue metadata yet" instead of failing every programme save with an
     * unknown-column SQL error.
     *
     * @return array<int,string>
     */
    public function writableKeys(): array
    {
        return $this->columnsExist()
            ? ['tuition_currency', 'tuition_fee_basis', 'website_sort_order']
            : [];
    }
}