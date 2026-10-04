<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the one genuinely missing Next of Kin field.
 *
 * The common staff profile already stores the Next of Kin across every staff
 * type: emergency_contact_name, emergency_contact_relationship,
 * emergency_contact_phone, emergency_contact_alternative_phone and
 * emergency_contact_address. Only an email address is absent, so this is a
 * single additive nullable column rather than a new table or a per-role split.
 *
 * Additive only: no column is altered, renamed or dropped, no table is created
 * or removed, and no existing row is read, copied or rewritten. Nullable so
 * every existing staff profile stays valid and no backfill is required.
 *
 * The Next of Kin is contact information, never an account: nothing here grants
 * login access, and the value is not required to be unique.
 */
return new class extends Migration
{
    private const TABLE = 'staff_profiles';
    private const COLUMN = 'emergency_contact_email';

    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, 191)->nullable()->after('emergency_contact_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
