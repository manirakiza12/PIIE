<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('website_pages') || !Schema::hasTable('global_settings')) return;
        $school = filter_var(DB::table('global_settings')->where('key', 'primary_school_id')->value('value'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$school || !DB::table('schools')->where('id', $school)->exists()) return;
        $scoped = Schema::hasColumn('website_pages', 'school_id');
        $query = DB::table('website_pages')->where('page_key', 'careers');
        if ($scoped) $query->where(fn ($q) => $q->where('school_id', $school)->orWhereNull('school_id'))->orderByRaw('school_id IS NULL');
        $page = $query->first();
        if ($page) {
            // Keep all existing institutional wording and vacancies.
            DB::table('website_pages')->where('id', $page->id)->update(['status' => 1, 'slug' => 'careers', 'updated_at' => now()]);
        } else {
            $row = ['page_key' => 'careers', 'slug' => 'careers', 'title' => 'Careers', 'nav_title' => 'Careers',
                'subtitle' => 'Opportunities at Prime International Institute of Excellence.', 'status' => 1,
                'display_order' => 7, 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now()];
            if ($scoped) $row['school_id'] = $school;
            DB::table('website_pages')->insert($row);
        }
        if (!Schema::hasTable('website_sections')) return;
        $sections = [
            'careers' => ['title' => 'Careers at PIIE', 'content' => 'Explore opportunities to work with Prime International Institute of Excellence. Current vacancies are published on this page when available.'],
            'careers_vacancies' => ['title' => 'Current vacancies', 'content' => ''],
            'careers_application' => ['title' => 'How to apply for a role', 'content' => 'Follow the application instructions in each published vacancy. For enquiries about working at PIIE, use the institutional contact channels.'],
        ];
        foreach ($sections as $key => $data) {
            $existing = DB::table('website_sections')->where('page_key', 'careers')->where('section_key', $key);
            if (Schema::hasColumn('website_sections', 'school_id')) $existing->where(fn ($q) => $q->where('school_id', $school)->orWhereNull('school_id'));
            if ($existing->exists()) continue;
            $row = $data + ['page_key' => 'careers', 'section_key' => $key, 'status' => 1, 'sort_order' => 40 + array_search($key, array_keys($sections)), 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('website_sections', 'school_id')) $row['school_id'] = $school;
            DB::table('website_sections')->insert($row);
        }
    }

    public function down(): void
    {
        // Publishing a release must not delete CMS content on a code rollback.
    }
};
