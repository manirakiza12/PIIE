<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Programme's GALLERY images — the optional additional pictures that sit
 * beside its cover.
 *
 * ── WHY A CHILD TABLE AND NOT MORE COLUMNS ON `programmes` ────────────────────
 *
 * The cover already lives as six `cover_image_*` columns on `programmes`, and it
 * is the right shape there: there is exactly ONE cover, it is read on every
 * catalogue card, and widening that row on every catalogue read is a cost paid by
 * the public site. A gallery is different in both respects — it is a small,
 * bounded set (at most two), and it is read only where one programme is shown in
 * full. Adding `gallery_1_*` … `gallery_2_*` would put two more joins' worth of
 * nullable columns on the hot path and make "add image three" a schema change.
 *
 * A child table keeps the hot path exactly as narrow as it is today, makes the
 * limit a rule the service enforces rather than a column count, and lets an image
 * carry its own identity so it can be removed without touching the programme.
 *
 * ── WHY THE PRIMARY IMAGE IS NOT HERE ────────────────────────────────────────
 *
 * The cover IS the primary image and it is `programmes.cover_image_path`. Duplicating
 * it here would give one picture two sources of truth, and the two would disagree
 * the first time an administrator replaced the cover. One image, one home.
 *
 * ── ADDITIVE, AND SAFE TO RE-RUN ──────────────────────────────────────────────
 *
 * Creates a new table and touches no existing one. No data is read, written or
 * rewritten, and no historical invoice, payment or programme record is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('programme_gallery_images')) {
            return;
        }

        Schema::create('programme_gallery_images', function (Blueprint $table) {
            $table->id();

            // Tenant-scoped, mirroring `programmes.school_id`. `programmes` itself
            // has no FK on school_id (it is a plain indexed column), so this column
            // is plain too — consistent with the table it points at rather than
            // pretending to a constraint the parent does not have.
            $table->unsignedBigInteger('school_id')->index();

            $table->unsignedBigInteger('programme_id')->index();

            // Display order within the gallery. Small integers, so a plain TINYINT
            // is ample and keeps reordering cheap.
            $table->unsignedTinyInteger('sort_order')->default(1);

            // Generated filename only. The administrator's original name is kept
            // for display but is NEVER used to build a path.
            $table->string('path', 255);
            $table->string('original_name', 191)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();

            // One row per image position per programme. `sort_order` is part of
            // the key so two images can never occupy the same slot, and deleting
            // one image cannot silently take a neighbour with it.
            $table->unique(
                ['programme_id', 'sort_order'],
                'programme_gallery_images_programme_order_unique'
            );
        });
    }

    public function down(): void
    {
        // Only ever removes images a programme chose to publish. Because the
        // `up()` creates a dedicated table and copies nothing out of any existing
        // one, dropping it cannot lose programme, invoice or payment data.
        Schema::dropIfExists('programme_gallery_images');
    }
};