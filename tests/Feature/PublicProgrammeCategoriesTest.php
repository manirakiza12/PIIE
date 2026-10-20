<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Support\ProgrammeCatalogue\ProgrammeCategories;
use App\Support\ProgrammeCatalogue\PublicProgrammeCatalogue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CreatesPublicSiteSchema;
use Tests\TestCase;

/**
 * The public programme catalogue's award-type categories.
 *
 * The rule under test throughout: a programme's category is derived from the
 * REAL stored `programmes.level` — the enum the academic side already validates
 * and the admissions side already reads — so there is exactly one classification
 * and no second one to drift out of step.
 *
 * Each category test proves both halves: the programmes that belong appear, and
 * programmes belonging to OTHER categories do not.
 *
 * In-memory SQLite; no database, network or gateway involved.
 */
class PublicProgrammeCategoriesTest extends TestCase
{
    // The public-site schema, not the admissions one: this suite exercises the
    // rendered website, which needs the CMS tables and the page/section/items
    // the catalogue page reads.
    use CreatesPublicSiteSchema;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();

        // The catalogue columns Stage 1 added, so a programme can be published
        // and priced the way it is in production.
        if (! Schema::hasColumn('programmes', 'is_published')) {
            Schema::table('programmes', function (Blueprint $table) {
                $table->string('cover_image_path', 255)->nullable();
                $table->string('cover_image_name', 191)->nullable();
                $table->string('cover_image_mime', 100)->nullable();
                $table->unsignedBigInteger('cover_image_size')->nullable();
                $table->dateTime('cover_image_updated_at')->nullable();
                $table->string('tuition_currency', 10)->nullable();
                $table->string('tuition_fee_basis', 32)->nullable();
                $table->tinyInteger('is_published')->default(0);
                $table->integer('website_sort_order')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->unsignedBigInteger('website_item_id')->nullable();
            });
        }

        // The pre-existing academic fee columns the public-site fixture lacks.
        if (! Schema::hasColumn('programmes', 'tuition_fee')) {
            Schema::table('programmes', function (Blueprint $table) {
                $table->decimal('tuition_fee', 15, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('schools', 'school_currency')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->string('school_currency', 20)->nullable();
            });
        }

        // Programme::department() is eager-loaded for the faculty label and the
        // fallback panel; a missing table would be a 500 on every catalogue page.
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('school_id')->nullable();
                $table->timestamps();
            });
        }

        $this->school = (int) DB::table('schools')->insertGetId([
            'title' => 'PIIE', 'school_type' => 'higher_ed', 'school_currency' => 'UGX',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // This deployment serves one institution's public site.
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'primary_school_id'],
            ['value' => (string) $this->school]
        );
    }

    private function publishedProgramme(string $name, string $level, array $overrides = []): Programme
    {
        $id = DB::table('programmes')->insertGetId(array_merge([
            'school_id'     => $this->school,
            'code'          => strtoupper(substr(md5($name), 0, 6)),
            'name'          => $name,
            'level'         => $level,
            'mode'          => 'ODEL',
            'is_active'     => 1,
            'is_published'  => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));

        return Programme::query()->findOrFail($id);
    }

    private function cataloguePage(array $query = []): string
    {
        return $this->get(route('website.page', 'academic-programmes').($query ? '?'.http_build_query($query) : ''))
            ->assertOk()->getContent();
    }

    public function test_homepage_award_cards_open_only_the_selected_programme_group(): void
    {
        $names = ['Masters' => 'AWARD MASTER', 'PGD' => 'AWARD PGD', 'Bachelors' => 'AWARD BACHELOR', 'Degree' => 'AWARD LEGACY DEGREE', 'Diploma' => 'AWARD DIPLOMA', 'Certificate' => 'AWARD CERTIFICATE'];
        foreach ($names as $level => $name) {
            $this->publishedProgramme($name, $level);
        }
        $document = new \DOMDocument();
        @$document->loadHTML($this->get('/')->assertOk()->getContent());
        $xpath = new \DOMXPath($document);
        foreach ([
            'Masters Degrees' => ['AWARD MASTER'],
            'Postgraduate Diplomas' => ['AWARD PGD'],
            "Bachelor's Degrees" => ['AWARD BACHELOR', 'AWARD LEGACY DEGREE'],
            'Diplomas' => ['AWARD DIPLOMA'],
            'Certificates' => ['AWARD CERTIFICATE'],
        ] as $label => $expected) {
            $links = $xpath->query('//a[contains(@class,"piie-card")][.//span[contains(@class,"piie-card__title") and normalize-space(.)='.json_encode($label).']]');
            $link = $links->item(0);
            $this->assertNotNull($link, $label);
            $url = $link->getAttribute('href');
            $this->assertSame('catalogue', parse_url($url, PHP_URL_FRAGMENT));
            $html = $this->get($url)->assertOk()->getContent();
            $resultDocument = new \DOMDocument();
            @$resultDocument->loadHTML($html);
            $resultXpath = new \DOMXPath($resultDocument);
            $titles = [];
            foreach ($resultXpath->query('//*[@id="catalogue"]//h3') as $heading) $titles[] = trim($heading->textContent);
            foreach ($names as $name) {
                $this->assertSame(in_array($name, $expected, true), in_array($name, $titles, true), $label.': '.$name);
            }
        }
    }

    public function test_selected_category_survives_the_filter_form_and_other_category_counts_remain_available(): void
    {
        $this->publishedProgramme('MASTER ONE', 'Masters');
        $this->publishedProgramme('DIPLOMA ONE', 'Diploma');
        $html = $this->cataloguePage(['category' => 'postgraduate']);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $category = $xpath->query('//form[@role="search"]//input[@name="category"]')->item(0);
        $this->assertNotNull($category);
        $this->assertSame('postgraduate', $category->getAttribute('value'));
        $this->assertStringContainsString(route('website.page', ['slug' => 'academic-programmes', 'category' => 'diploma']).'#catalogue', $html);
        $this->assertMatchesRegularExpression('/Diploma Programmes\s*<span class="piie-category-nav__count">1<\/span>/', $html);
        $empty = $this->cataloguePage(['category' => 'certificate']);
        $this->assertStringContainsString('No programmes match those filters', $empty);
        $this->assertStringNotContainsString('#piie-category-certificate', $empty);
        $this->assertStringContainsString('id="catalogue"', $empty);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE CLASSIFICATION ITSELF
    // ══════════════════════════════════════════════════════════════════════

    public function test_each_stored_level_maps_to_the_expected_category(): void
    {
        $this->assertSame(ProgrammeCategories::POSTGRADUATE, ProgrammeCategories::forLevel('Masters'));
        $this->assertSame(ProgrammeCategories::BACHELORS, ProgrammeCategories::forLevel("Bachelor's"));
        $this->assertSame(ProgrammeCategories::BACHELORS, ProgrammeCategories::forLevel('Bachelors'));
        // 'Degree' is the legacy spelling of a bachelor's award and is a real
        // enum value, so it must land with the bachelor's programmes.
        $this->assertSame(ProgrammeCategories::BACHELORS, ProgrammeCategories::forLevel('Degree'));
        $this->assertSame(ProgrammeCategories::DIPLOMA, ProgrammeCategories::forLevel('Diploma'));
        // PGD is a Postgraduate Diploma, which is a diploma award.
        $this->assertSame(ProgrammeCategories::DIPLOMA, ProgrammeCategories::forLevel('PGD'));
        $this->assertSame(ProgrammeCategories::CERTIFICATE, ProgrammeCategories::forLevel('Certificate'));
        $this->assertSame(ProgrammeCategories::CERTIFICATE, ProgrammeCategories::forLevel('HEC'));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel('Professional Course'));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel('Short course'));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel('Short Course'));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel('PhD'));
    }

    public function test_a_level_the_classification_does_not_recognise_still_appears(): void
    {
        // Rather than vanishing, an unknown level is surfaced under "other".
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel('Something New'));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel(null));
        $this->assertSame(ProgrammeCategories::OTHER, ProgrammeCategories::forLevel(''));
    }

    public function test_matching_a_level_is_case_insensitive(): void
    {
        $this->assertSame(ProgrammeCategories::POSTGRADUATE, ProgrammeCategories::forLevel('masters'));
        $this->assertSame(ProgrammeCategories::BACHELORS, ProgrammeCategories::forLevel('  BACHELORS '));
    }

    public function test_every_category_has_a_label_and_covers_a_real_stored_level(): void
    {
        $stored = ProgrammeCategories::storedLevels();

        foreach (ProgrammeCategories::all() as $slug => $definition) {
            $this->assertNotEmpty($definition['label'], "Category {$slug} needs a label.");
            $this->assertNotEmpty($definition['levels'], "Category {$slug} needs at least one level.");

            foreach ($definition['levels'] as $level) {
                $this->assertContains(
                    $level,
                    $stored,
                    "Category {$slug} lists '{$level}', which is not a level the academic side can store."
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // PER-CATEGORY FILTERING — what belongs, and what must not leak in
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_masters_category_lists_only_masters_programmes(): void
    {
        $this->publishedProgramme('MASTER OF BUSINESS ADMINISTRATION', 'Masters');
        $this->publishedProgramme('MASTER OF PUBLIC HEALTH', 'Masters');
        $this->publishedProgramme('BACHELOR OF BUSINESS IT', 'Bachelors');
        $this->publishedProgramme('DIPLOMA IN ACCOUNTING', 'Diploma');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringContainsString('MASTER OF BUSINESS ADMINISTRATION', $html);
        $this->assertStringContainsString('MASTER OF PUBLIC HEALTH', $html);
        $this->assertStringNotContainsString('BACHELOR OF BUSINESS IT', $html, 'A bachelor programme must not appear under Master’s.');
        $this->assertStringNotContainsString('DIPLOMA IN ACCOUNTING', $html, 'A diploma programme must not appear under Master’s.');
    }

    public function test_the_bachelors_category_includes_the_legacy_degree_value(): void
    {
        $this->publishedProgramme('BACHELOR OF SCIENCE IN NURSING', 'Bachelors');
        $this->publishedProgramme('LEGACY DEGREE PROGRAMME', 'Degree');
        $this->publishedProgramme('MASTER OF EDUCATION', 'Masters');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::BACHELORS]);

        $this->assertStringContainsString('BACHELOR OF SCIENCE IN NURSING', $html);
        $this->assertStringContainsString('LEGACY DEGREE PROGRAMME', $html, 'The legacy Degree value is a bachelor award.');
        $this->assertStringNotContainsString('MASTER OF EDUCATION', $html);
    }

    public function test_the_diploma_category_includes_the_postgraduate_diploma_value(): void
    {
        $this->publishedProgramme('DIPLOMA IN TOURISM', 'Diploma');
        $this->publishedProgramme('POSTGRADUATE DIPLOMA IN FINANCE', 'PGD');
        $this->publishedProgramme('CERTIFICATE IN ICT', 'Certificate');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::DIPLOMA]);

        $this->assertStringContainsString('DIPLOMA IN TOURISM', $html);
        $this->assertStringContainsString('POSTGRADUATE DIPLOMA IN FINANCE', $html);
        $this->assertStringNotContainsString('CERTIFICATE IN ICT', $html);
    }

    public function test_the_certificate_category_lists_only_certificates(): void
    {
        $this->publishedProgramme('CERTIFICATE IN PROJECT MANAGEMENT', 'Certificate');
        $this->publishedProgramme('MASTER OF BUSINESS ADMINISTRATION', 'Masters');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::CERTIFICATE]);

        $this->assertStringContainsString('CERTIFICATE IN PROJECT MANAGEMENT', $html);
        $this->assertStringNotContainsString('MASTER OF BUSINESS ADMINISTRATION', $html);
    }

    public function test_the_other_category_holds_short_courses_and_doctoral_programmes(): void
    {
        $this->publishedProgramme('SHORT COURSE IN DATA ANALYTICS', 'Short Course');
        $this->publishedProgramme('DOCTOR OF PHILOSOPHY', 'PhD');
        $this->publishedProgramme('MASTER OF BUSINESS ADMINISTRATION', 'Masters');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::OTHER]);

        $this->assertStringContainsString('SHORT COURSE IN DATA ANALYTICS', $html);
        $this->assertStringContainsString('DOCTOR OF PHILOSOPHY', $html);
        $this->assertStringNotContainsString('MASTER OF BUSINESS ADMINISTRATION', $html);
    }

    /** Every category, checked against the other four so no cross-contamination. */
    public function test_no_category_shows_a_programme_belonging_to_another(): void
    {
        // Keyed by category, but each entry records the STORED level the
        // programme is actually created with — the classification under test is
        // the real `programmes.level`, not the category slug.
        $fixtures = [
            ProgrammeCategories::POSTGRADUATE => ['level' => 'Masters',       'names' => ['Masters one', 'Masters two']],
            ProgrammeCategories::BACHELORS    => ['level' => 'Bachelors',    'names' => ['Bachelors one', 'Legacy degree']],
            ProgrammeCategories::DIPLOMA      => ['level' => 'Diploma',      'names' => ['Diploma one', 'PGD two']],
            ProgrammeCategories::CERTIFICATE  => ['level' => 'Certificate',  'names' => ['Certificate one']],
            ProgrammeCategories::OTHER        => ['level' => 'Short Course', 'names' => ['Short course one', 'PhD two']],
        ];

        foreach ($fixtures as $fixture) {
            foreach ($fixture['names'] as $name) {
                $this->publishedProgramme($name, $fixture['level']);
            }
        }

        foreach (array_keys($fixtures) as $category) {
            $html = $this->cataloguePage(['category' => $category]);

            foreach ($fixtures as $ownCategory => $fixture) {
                foreach ($fixture['names'] as $name) {
                    if ($ownCategory === $category) {
                        $this->assertStringContainsString($name, $html, "'{$name}' belongs in {$category}.");
                    } else {
                        $this->assertStringNotContainsString($name, $html, "'{$name}' must not appear under {$category}.");
                    }
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // VISIBILITY AND EMPTY STATES
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unpublished_programme_never_appears_in_any_category(): void
    {
        $this->publishedProgramme('PUBLISHED MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('UNPUBLISHED MASTER PROGRAMME', 'Masters', ['is_published' => 0]);

        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringContainsString('PUBLISHED MASTER PROGRAMME', $html);
        $this->assertStringNotContainsString('UNPUBLISHED MASTER PROGRAMME', $html);
    }

    public function test_a_deactivated_programme_never_appears_in_any_category(): void
    {
        $this->publishedProgramme('ACTIVE MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('WITHDRAWN MASTER PROGRAMME', 'Masters', ['is_active' => 0]);

        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringContainsString('ACTIVE MASTER PROGRAMME', $html);
        $this->assertStringNotContainsString('WITHDRAWN MASTER PROGRAMME', $html);
    }

    public function test_another_tenants_programme_never_appears_in_a_category(): void
    {
        $otherSchool = (int) DB::table('schools')->insertGetId([
            'title' => 'Other Institution', 'school_type' => 'higher_ed', 'school_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->publishedProgramme('OUR MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('THEIR MASTER PROGRAMME', 'Masters', ['school_id' => $otherSchool]);

        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringContainsString('OUR MASTER PROGRAMME', $html);
        $this->assertStringNotContainsString('THEIR MASTER PROGRAMME', $html);
    }

    /** An award type nobody is enrolled in is still offered as a section. */
    public function test_an_empty_category_is_still_offered_as_a_section(): void
    {
        $this->publishedProgramme('BACHELOR OF BUSINESS IT', 'Bachelors');

        $html = $this->cataloguePage();

        foreach (ProgrammeCategories::all() as $slug => $definition) {
            $this->assertStringContainsString(
                $definition['label'],
                $html,
                "Category {$slug} must be offered even when it has no programmes."
            );
        }
    }

    public function test_an_empty_category_reports_that_it_matches_nothing(): void
    {
        $this->publishedProgramme('BACHELOR OF BUSINESS IT', 'Bachelors');

        // Selecting a category with nothing in it must say so rather than
        // silently falling back to the whole catalogue.
        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringNotContainsString('BACHELOR OF BUSINESS IT', $html);
        $this->assertStringContainsString('No programmes match those filters', $html);
    }

    public function test_an_unknown_category_is_ignored_rather_than_emptying_the_page(): void
    {
        $this->publishedProgramme('BACHELOR OF BUSINESS IT', 'Bachelors');

        $html = $this->cataloguePage(['category' => 'no-such-category']);

        $this->assertStringContainsString('BACHELOR OF BUSINESS IT', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CONSISTENCY ACROSS SEARCH, LEVEL, FACULTY AND PAGINATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_category_combines_with_the_search_filter(): void
    {
        $this->publishedProgramme('MASTER OF BUSINESS ADMINISTRATION', 'Masters');
        $this->publishedProgramme('MASTER OF PUBLIC HEALTH', 'Masters');
        $this->publishedProgramme('BACHELOR OF BUSINESS ADMINISTRATION', 'Bachelors');

        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE, 'q' => 'public health']);

        $this->assertStringContainsString('MASTER OF PUBLIC HEALTH', $html);
        $this->assertStringNotContainsString('MASTER OF BUSINESS ADMINISTRATION', $html);
        $this->assertStringNotContainsString('BACHELOR OF BUSINESS ADMINISTRATION', $html);
    }

    public function test_a_category_combines_with_the_level_filter(): void
    {
        $this->publishedProgramme('MASTER OF BUSINESS ADMINISTRATION', 'Masters');
        $this->publishedProgramme('LEGACY DEGREE PROGRAMME', 'Degree');

        // 'Degree' is in the bachelors category; narrowing to the exact Masters
        // level inside the postgraduate category leaves only the Masters one.
        $html = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE, 'level' => 'Masters']);

        $this->assertStringContainsString('MASTER OF BUSINESS ADMINISTRATION', $html);
        $this->assertStringNotContainsString('LEGACY DEGREE PROGRAMME', $html);
    }

    public function test_the_category_nav_counts_agree_with_the_listing(): void
    {
        $this->publishedProgramme('MASTER ONE', 'Masters');
        $this->publishedProgramme('BACHELOR ONE', 'Bachelors');
        $this->publishedProgramme('BACHELOR TWO', 'Bachelors');

        $html = $this->cataloguePage();

        // Each category link carries its own count, taken from the same filtered
        // collection that renders the grid, so the number cannot drift from it.
        $this->assertMatchesRegularExpression(
            '/Bachelor[^<]*\s*<span class="piie-category-nav__count">2<\/span>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/Master[^<]*\s*<span class="piie-category-nav__count">1<\/span>/',
            $html
        );
    }

    public function test_pagination_is_preserved_across_a_category(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->publishedProgramme(sprintf('MASTER PROGRAMME %02d', $i), 'Masters');
        }

        $this->publishedProgramme('BACHELOR PROGRAMME', 'Bachelors');

        $firstPage = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE]);

        $this->assertStringContainsString('MASTER PROGRAMME 01', $firstPage);
        $this->assertStringNotContainsString('BACHELOR PROGRAMME', $firstPage, 'Pagination must respect the category.');
        // PER_PAGE is 12, so a 14-item category genuinely paginates.
        $this->assertStringContainsString('page 1 of 2', $firstPage);

        $secondPage = $this->cataloguePage(['category' => ProgrammeCategories::POSTGRADUATE, 'page' => 2]);

        $this->assertStringContainsString('MASTER PROGRAMME 14', $secondPage);
        $this->assertStringNotContainsString('MASTER PROGRAMME 01', $secondPage);
    }

    public function test_every_programme_still_appears_when_no_category_is_selected(): void
    {
        $this->publishedProgramme('MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('BACHELOR PROGRAMME', 'Bachelors');
        $this->publishedProgramme('DIPLOMA PROGRAMME', 'Diploma');
        $this->publishedProgramme('CERTIFICATE PROGRAMME', 'Certificate');
        $this->publishedProgramme('SHORT COURSE PROGRAMME', 'Short Course');

        $html = $this->cataloguePage();

        foreach (['MASTER PROGRAMME', 'BACHELOR PROGRAMME', 'DIPLOMA PROGRAMME', 'CERTIFICATE PROGRAMME', 'SHORT COURSE PROGRAMME'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE SERVICE LAYER
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_service_partitions_cards_into_categories(): void
    {
        $this->publishedProgramme('MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('BACHELOR PROGRAMME', 'Bachelors');

        $grouped = app(PublicProgrammeCatalogue::class)->cardsByCategory();

        // Every category is present, including empty ones.
        foreach (array_keys(ProgrammeCategories::all()) as $slug) {
            $this->assertArrayHasKey($slug, $grouped, "Category {$slug} must always be present.");
        }

        $this->assertSame(1, $grouped[ProgrammeCategories::POSTGRADUATE]->count());
        $this->assertSame(1, $grouped[ProgrammeCategories::BACHELORS]->count());
        $this->assertSame(0, $grouped[ProgrammeCategories::DIPLOMA]->count());
    }

    public function test_a_single_category_can_be_read_on_its_own(): void
    {
        $this->publishedProgramme('MASTER PROGRAMME', 'Masters');
        $this->publishedProgramme('BACHELOR PROGRAMME', 'Bachelors');

        $cards = app(PublicProgrammeCatalogue::class)->cardsInCategory(ProgrammeCategories::POSTGRADUATE);

        $this->assertCount(1, $cards);
        $this->assertSame('MASTER PROGRAMME', $cards->first()['title']);
    }
}
