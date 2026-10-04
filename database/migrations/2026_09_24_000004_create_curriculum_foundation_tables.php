<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROGRAMME_TENANT_INDEX = 'programmes_school_id_id_unique';

    public function up(): void
    {
        $this->assertUnsignedBigint('programmes', 'school_id');
        $this->assertUnsignedBigint('programmes', 'id');
        $this->assertUnsignedBigint('academic_years', 'school_id');
        $this->assertUnsignedBigint('academic_years', 'id');
        $this->assertUnsignedBigint('subjects', 'school_id');
        $this->assertUnsignedBigint('subjects', 'id');
        $this->assertUniqueIndex('academic_years', 'academic_years_school_id_id_unique', ['school_id', 'id']);
        $this->assertUniqueIndex('subjects', 'subjects_school_id_id_unique', ['school_id', 'id']);

        if (! $this->hasIndex('programmes', self::PROGRAMME_TENANT_INDEX)) {
            Schema::table('programmes', function (Blueprint $table): void {
                $table->unique(['school_id', 'id'], self::PROGRAMME_TENANT_INDEX);
            });
        } else {
            $this->assertUniqueIndex('programmes', self::PROGRAMME_TENANT_INDEX, ['school_id', 'id']);
        }

        Schema::create('curricula', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('programme_id');
            $table->string('version', 50);
            $table->unsignedBigInteger('effective_academic_year_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['school_id', 'id'], 'curricula_school_id_id_unique');
            $table->unique(['school_id', 'programme_id', 'version'], 'curricula_programme_version_unique');
            $table->index(['school_id', 'programme_id', 'status'], 'curricula_school_programme_status_idx');
            $table->index(['school_id', 'effective_academic_year_id'], 'curricula_school_year_idx');

            $table->foreign('school_id', 'curricula_school_fk')->references('id')->on('schools')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'programme_id'], 'curricula_programme_tenant_fk')->references(['school_id', 'id'])->on('programmes')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'effective_academic_year_id'], 'curricula_year_tenant_fk')->references(['school_id', 'id'])->on('academic_years')->onDelete('restrict')->onUpdate('restrict');
        });

        Schema::create('curriculum_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->string('label', 100);
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();

            $table->unique(['school_id', 'curriculum_id', 'id'], 'curriculum_stages_tenant_parent_id_unique');
            $table->unique(['curriculum_id', 'sequence'], 'curriculum_stages_sequence_unique');
            $table->index(['school_id', 'curriculum_id'], 'curriculum_stages_tenant_parent_idx');
            $table->foreign(['school_id', 'curriculum_id'], 'curriculum_stages_curriculum_tenant_fk')->references(['school_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
        });

        Schema::create('curriculum_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('curriculum_stage_id');
            $table->string('period_type', 32)->nullable();
            $table->unsignedSmallInteger('period_sequence')->nullable();
            $table->enum('classification', ['compulsory', 'elective']);
            $table->decimal('credits', 6, 2)->unsigned();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'curriculum_id', 'id'], 'curriculum_memberships_tenant_parent_id_unique');
            $table->unique(['curriculum_id', 'subject_id'], 'curriculum_memberships_subject_unique');
            $table->index(['school_id', 'curriculum_id', 'curriculum_stage_id'], 'curriculum_memberships_stage_idx');
            $table->index(['school_id', 'subject_id'], 'curriculum_memberships_subject_tenant_idx');

            $table->foreign(['school_id', 'curriculum_id'], 'curriculum_memberships_curriculum_tenant_fk')->references(['school_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'curriculum_id', 'curriculum_stage_id'], 'curriculum_memberships_stage_tenant_fk')->references(['school_id', 'curriculum_id', 'id'])->on('curriculum_stages')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'subject_id'], 'curriculum_memberships_subject_tenant_fk')->references(['school_id', 'id'])->on('subjects')->onDelete('restrict')->onUpdate('restrict');
        });

        Schema::create('curriculum_prerequisites', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('prerequisite_membership_id');

            $table->primary(['membership_id', 'prerequisite_membership_id'], 'curriculum_prerequisites_edge_pk');
            $table->index(['school_id', 'curriculum_id'], 'curriculum_prerequisites_tenant_curriculum_idx');
            $table->foreign(['school_id', 'curriculum_id', 'membership_id'], 'curriculum_prerequisites_member_tenant_fk')->references(['school_id', 'curriculum_id', 'id'])->on('curriculum_memberships')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'curriculum_id', 'prerequisite_membership_id'], 'curriculum_prerequisites_required_tenant_fk')->references(['school_id', 'curriculum_id', 'id'])->on('curriculum_memberships')->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_prerequisites');
        Schema::dropIfExists('curriculum_memberships');
        Schema::dropIfExists('curriculum_stages');
        Schema::dropIfExists('curricula');

        if ($this->hasIndex('programmes', self::PROGRAMME_TENANT_INDEX)) {
            Schema::table('programmes', function (Blueprint $table): void {
                $table->dropUnique(self::PROGRAMME_TENANT_INDEX);
            });
        }
    }

    private function assertUnsignedBigint(string $table, string $column): void
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        if (! $row || ! preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $row->column_type)) {
            throw new \RuntimeException("Cannot create tenant-safe Curriculum foreign keys: {$table}.{$column} is not BIGINT UNSIGNED.");
        }
    }

    private function assertUniqueIndex(string $table, string $index, array $columns): void
    {
        $actual = array_map(
            fn ($row) => $row->column_name,
            DB::select(
                'SELECT COLUMN_NAME AS column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? ORDER BY seq_in_index',
                [$table, $index]
            )
        );
        $unique = DB::selectOne(
            'SELECT MAX(non_unique) AS non_unique FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        if ($actual !== $columns || ! $unique || (int) $unique->non_unique !== 0) {
            throw new \RuntimeException("Cannot create tenant-safe Curriculum foreign key: expected unique {$table}." . implode(',', $columns) . '.');
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
