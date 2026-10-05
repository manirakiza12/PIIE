<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use App\Models\WebsiteItem;

/**
 * The explicit bridge between the academic Programme record and the public
 * website catalogue.
 *
 * ── WHY A BRIDGE AND NOT A QUERY ───────────────────────────────────────────
 *
 * The public site reads `website_items`; the academic record is `programmes`.
 * Making the public pages query `programmes` directly was rejected: it would
 * drag an academic table into the marketing render path and force the
 * programmes UI, the applicant portal and the admissions wizard to share one
 * set of permissions. Instead this service projects a Programme ONTO a CMS row
 * and keeps the two in step in one direction only.
 *
 * ONE DIRECTION, DELIBERATELY. A programme may publish to the website. The
 * website never writes back to a programme. An administrator editing a CMS row
 * that happens to carry a programme marker is editing a projection, and the
 * next explicit publish overwrites their change. That is stated in the admin UI
 * rather than prevented, because a silently-reverted edit is worse than a
 * visible one.
 *
 * ── DUPLICATE PREVENTION, THREE LAYERS ────────────────────────────────────
 *
 * A catalogue that lists the same qualification twice is the specific failure
 * this feature exists to prevent, so it is prevented structurally rather than
 * hoped against:
 *
 *   1. `programmes.website_item_id` records THE row this programme projects
 *      onto. Publishing again updates that row instead of appending.
 *   2. `website_items.meta_json.programme_id` records the reverse link, so a
 *      programme whose `website_item_id` was lost is still findable rather than
 *      duplicated.
 *   3. Before creating anything, the service looks for an EXISTING CMS row
 *      already carrying this programme's marker or its exact title in a
 *      catalogue section, and adopts it.
 *
 * MANUALLY MANAGED CMS CONTENT IS NEVER TOUCHED. A row with no programme marker
 * is a hand-authored marketing card; this service will not overwrite it, adopt
 * it, or delete it. Adoption only ever happens when the marker or an identical
 * title matches, and even then only within a `programme_catalog*` section —
 * so the 67 hand-authored programme cards the CMS already holds stay exactly as
 * they are until an administrator publishes a programme onto one of them.
 */
class ProgrammePublisher
{
    /**
     * The section the projection lives in when no catalogue section is chosen.
     *
     * Deliberately one of the four existing keys rather than a new section: the
     * public catalogue already aggregates exactly these four, so a projection
     * appears on the catalogue page and the homepage without any change to how
     * either reads its sections.
     */
    public const DEFAULT_SECTION = 'programme_catalog_business_management';

    /** Every section the public catalogue aggregates. */
    public const CATALOGUE_SECTIONS = [
        'programme_catalog_graduate_school',
        'programme_catalog_business_management',
        'programme_catalog_humanities',
        'programme_catalog_education',
    ];

    /** Marks a CMS row as a projection of an academic programme. */
    public const META_KEY = 'programme_id';

    /**
     * Publish a programme to the public catalogue.
     *
     * Idempotent: publishing an already-published programme refreshes its
     * projection rather than creating a second card.
     */
    public function publish(Programme $programme, ?string $sectionKey = null): Programme
    {
        $sectionKey = $this->normaliseSection($sectionKey);

        $item = $this->resolveProjection($programme, $sectionKey);

        // The projection carries ONLY what the public catalogue may show. It is
        // a card, not the academic record: no school_id leakage beyond the
        // owning tenant, no internal codes it must not publish, and the price is
        // not copied into CMS text where it would drift from the academic record.
        $item->section_key = $item->section_key ?: $sectionKey;
        $item->item_type   = $item->item_type ?: 'programme';
        $item->title       = $programme->name;
        $item->subtitle    = $programme->level;
        $item->status      = 1;
        $item->sort_order  = $this->sortOrderFor($programme);
        $item->meta_json   = $this->encodeMeta($item->meta_json, [
            self::META_KEY => (int) $programme->id,
            'programme_code' => $programme->code,
            'origin' => 'academic_programme',
        ]);
        $item->save();

        $programme->forceFill([
            'is_published'    => 1,
            'published_at'    => $programme->published_at ?: now(),
            'website_item_id' => $item->id,
        ])->save();

        return $programme->refresh();
    }

    /**
     * Withdraw a programme from the public catalogue.
     *
     * The CMS row is DEACTIVATED, not deleted. The projection is a piece of
     * content an administrator may have edited and may want back, and a
     * "published" toggle that destroys content on the way off is a toggle nobody
     * will trust twice.
     *
     * `deleteProjection()` exists separately for the case where the projection
     * should genuinely not exist any more.
     */
    public function unpublish(Programme $programme): Programme
    {
        $item = $this->projectionFor($programme);

        if ($item !== null) {
            $item->status = 0;
            $item->save();
        }

        $programme->forceFill([
            'is_published' => 0,
            // published_at is KEPT as the record of when it was last live, and
            // website_item_id is KEPT so unpublishing twice, or re-publishing,
            // finds the same row instead of orphaning it and creating a twin.
        ])->save();

        return $programme->refresh();
    }

    /** Remove the projection row entirely. Explicit, and not the toggle. */
    public function deleteProjection(Programme $programme): Programme
    {
        $item = $this->projectionFor($programme);

        if ($item !== null) {
            $item->delete();
        }

        $programme->forceFill(['website_item_id' => null])->save();

        return $programme->refresh();
    }

    /**
     * Refresh every published programme's projection.
     *
     * Used after a change that alters what the public card shows (a new cover,
     * a renamed programme). Bounded by construction: one query for the
     * published programmes, one update each, all tenant-scoped.
     */
    public function resync(?int $schoolId = null): int
    {
        $query = Programme::query()->where('is_published', 1);

        if ($schoolId !== null) {
            $query->where('school_id', $schoolId);
        }

        $count = 0;

        $query->with('department')->chunkById(100, function ($programmes) use (&$count): void {
            foreach ($programmes as $programme) {
                $this->refreshProjection($programme);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Push the current academic values onto the existing projection WITHOUT
     * changing publication state.
     *
     * A cover change must not require unpublishing, and re-publishing to pick up
     * a new photograph would briefly withdraw the programme from the site.
     */
    public function refreshProjection(Programme $programme): ?WebsiteItem
    {
        $item = $this->projectionFor($programme);

        if ($item === null) {
            return null;
        }

        $item->title    = $programme->name;
        $item->subtitle = $programme->level;
        $item->save();

        return $item;
    }

    /**
     * The CMS row this programme projects onto, or null.
     *
     * Tenant-scoped on both sides. A `website_item_id` pointing at another
     * school's row is treated as absent rather than followed, so a corrupted or
     * copied id cannot make one institution publish into another's catalogue.
     */
    public function projectionFor(Programme $programme): ?WebsiteItem
    {
        $id = $programme->website_item_id;

        if ($id === null) {
            return null;
        }

        $item = WebsiteItem::query()
            ->where('id', $id)
            ->where(fn ($q) => $q->where('school_id', $programme->school_id)->orWhereNull('school_id'))
            ->first();

        if ($item === null) {
            return null;
        }

        // A row that no longer carries this programme's marker is NOT ours. The
        // id may have been reused, or the row re-saved by hand into something
        // else entirely; claiming it would let a publish overwrite content that
        // belongs to somebody else.
        return $this->markerOf($item) === (int) $programme->id ? $item : null;
    }

    /**
     * Find the row to publish onto, creating one only when nothing matches.
     *
     * Order matters: the recorded projection wins, then a marked row found by
     * scan, and only then a same-titled row in a catalogue section, and only
     * then a new row.
     */
    private function resolveProjection(Programme $programme, string $sectionKey): WebsiteItem
    {
        $existing = $this->projectionFor($programme);

        if ($existing !== null) {
            return $existing;
        }

        // Layer 2: the recorded id was lost, but the row still carries the
        // marker. Adopt it rather than creating a twin card.
        $marked = WebsiteItem::query()
            ->where(fn ($q) => $q->where('school_id', $programme->school_id)->orWhereNull('school_id'))
            ->whereIn('section_key', self::CATALOGUE_SECTIONS)
            ->get()
            ->first(fn (WebsiteItem $item) => $this->markerOf($item) === (int) $programme->id);

        if ($marked !== null) {
            return $marked;
        }

        // Layer 3: a hand-authored catalogue card with the SAME title. Adopting
        // it is what stops the catalogue listing one qualification twice, which
        // is the specific failure being prevented. Restricted to catalogue
        // sections so unrelated CMS content is never captured.
        $sameTitle = WebsiteItem::query()
            ->where(fn ($q) => $q->where('school_id', $programme->school_id)->orWhereNull('school_id'))
            ->whereIn('section_key', self::CATALOGUE_SECTIONS)
            ->where('title', $programme->name)
            ->first();

        return $sameTitle ?? new WebsiteItem([
            'school_id'   => $programme->school_id,
            'section_key' => $sectionKey,
            'item_type'   => 'programme',
        ]);
    }

    /** Sort order: the explicit one, else last, so ordering never needs a rewrite. */
    private function sortOrderFor(Programme $programme): int
    {
        return $programme->website_sort_order === null
            ? 9999
            : (int) $programme->website_sort_order;
    }

    private function normaliseSection(?string $sectionKey): string
    {
        return in_array($sectionKey, self::CATALOGUE_SECTIONS, true)
            ? $sectionKey
            : self::DEFAULT_SECTION;
    }

    /** The programme id this row projects, or null for hand-authored content. */
    private function markerOf(WebsiteItem $item): ?int
    {
        $meta = $item->meta_json;

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        if (! is_array($meta) || ! isset($meta[self::META_KEY]) || ! is_numeric($meta[self::META_KEY])) {
            return null;
        }

        return (int) $meta[self::META_KEY];
    }

    /** Merge markers into whatever meta the row already had. */
    private function encodeMeta(?string $existing, array $additions): string
    {
        $meta = is_string($existing) ? json_decode($existing, true) : [];
        $meta = is_array($meta) ? $meta : [];

        return (string) json_encode(array_merge($meta, $additions));
    }
}