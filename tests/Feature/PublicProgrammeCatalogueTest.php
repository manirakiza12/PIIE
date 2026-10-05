<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Support\ProgrammeCatalogue\PublicProgrammeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stage 2: the public programme catalogue reads published ACADEMIC records.
 *
 * ── WHY THESE TESTS EXIST ──────────────────────────────────────────────────
 *
 * Stage 1 made the academic Programme authoritative for publication. Stage 2 makes
 * it authoritative for READING too, which is where a mistake becomes public. Before
 * this, the site rendered `website_items`; now a filter dropped in the wrong place
 * would put a withdrawn qualification on the homepage, or hide a live one.
 *
 * So the assertions here are made against RENDERED HTML wherever the risk is
 * visible to a visitor, and against the service where the risk is structural.
 *
 * ORDER IS DELIBERATE. Visibility rules first (what must never appear), then the
 * layout contract, then the CMS-compatibility promises. A test that proves the grid
 * is four-across is worthless if the grid can also render an unpublished programme.
 */
class PublicProgrammeCatalogueTest extends TestCase
{
    use CreatesPublicSiteSchema;

    private int $school;
    private int $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();

        // The catalogue columns Stage 1 added.
        Schema::table('programmes', function ($table): void {
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

        // The tenant currency the price inherits. The public-site trait's `schools`
        // table does not carry it, and the whole point of the price work is that the
        // currency is read from the tenant rather than assumed to be UGX.
        Schema::table('schools', function ($table): void {
            $table->string('school_currency', 20)->nullable();
        });

        // The pre-existing academic fee columns, which the public-site trait's
        // `programmes` table does not carry. `tuition_fee` is the figure this whole
        // feature is about, so it must exist in the fixture.
        Schema::table('programmes', function ($table): void {
            if (! Schema::hasColumn('programmes', 'tuition_fee')) {
                $table->decimal('tuition_fee', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('programmes', 'duration')) {
                $table->string('duration', 50)->nullable();
            }
            if (! Schema::hasColumn('programmes', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable();
            }
        });

        // Programme::department() is eager-loaded for the faculty label and the
        // fallback panel. A missing table there would be a 500 on every public
        // catalogue page, so the table is declared rather than mocked.
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function ($table): void {
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

        $this->otherSchool = (int) DB::table('schools')->insertGetId([
            'title' => 'Other Institution', 'school_type' => 'higher_ed', 'school_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // This deployment serves one institution's public site.
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'primary_school_id'],
            ['value' => (string) $this->school]
        );
    }

    private function programme(array $overrides = []): Programme
    {
        $id = DB::table('programmes')->insertGetId(array_merge([
            'school_id' => $this->school,
            'code'      => 'PRG',
            'name'      => 'Test Programme',
            'level'     => 'Bachelors',
            'mode'      => 'ODEL',
            'is_active' => 1,
            'is_published' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return Programme::query()->findOrFail($id);
    }

    private function publish(Programme $programme, array $overrides = []): Programme
    {
        DB::table('programmes')->where('id', $programme->id)
            ->update(array_merge(['is_published' => 1, 'is_active' => 1], $overrides));

        return $programme->refresh();
    }

    private function legacyItem(array $overrides = []): int
    {
        return (int) DB::table('website_items')->insertGetId(array_merge([
            'school_id'   => null,
            'section_key' => PublicProgrammeCatalogue::CATALOGUE_SECTIONS[1],
            'item_type'   => 'programme',
            'title'       => 'Hand Authored Programme',
            'subtitle'    => 'Diploma',
            'description' => 'Marketing copy written by an administrator.',
            'status'      => 1,
            'sort_order'  => 10,
            'created_at'  => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function catalogue(): PublicProgrammeCatalogue
    {
        return app(PublicProgrammeCatalogue::class);
    }

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    private function cataloguePage(array $query = []): string
    {
        return $this->get(route('website.page', 'academic-programmes').($query ? '?'.http_build_query($query) : ''))
            ->assertOk()->getContent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // VISIBILITY RULES — what must never appear
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unpublished_programme_never_appears(): void
    {
        $this->programme(['name' => 'HIDDEN UNPUBLISHED PROGRAMME', 'code' => 'HID1']);

        $this->assertStringNotContainsString('HIDDEN UNPUBLISHED PROGRAMME', $this->home());
        $this->assertStringNotContainsString('HIDDEN UNPUBLISHED PROGRAMME', $this->cataloguePage());
    }

    public function test_a_deactivated_programme_never_appears_even_when_published(): void
    {
        // Created deactivated, then published. The deactivation must survive the
        // publish call — which is the whole scenario: a programme that was withdrawn
        // but whose publication flag was left on.
        $programme = $this->programme([
            'name' => 'HIDDEN WITHDRAWN PROGRAMME', 'code' => 'HID2',
            'is_active' => 0, 'is_published' => 0,
        ]);

        DB::table('programmes')->where('id', $programme->id)->update(['is_published' => 1]);

        $this->assertSame(0, (int) DB::table('programmes')->where('id', $programme->id)->value('is_active'));

        // A withdrawn qualification that still advertises itself is worse than a
        // missing card: it takes applications for something that cannot be studied.
        $this->assertStringNotContainsString('HIDDEN WITHDRAWN PROGRAMME', $this->home());
        $this->assertStringNotContainsString('HIDDEN WITHDRAWN PROGRAMME', $this->cataloguePage());
    }

    public function test_another_tenants_programme_never_appears(): void
    {
        $this->publish($this->programme([
            'school_id'   => $this->otherSchool,
            'name'        => 'FOREIGN INSTITUTION PROGRAMME',
            'code'        => 'FOR1',
            'is_published' => 1,
        ]));

        $html = $this->home().$this->cataloguePage();

        $this->assertStringNotContainsString('FOREIGN INSTITUTION PROGRAMME', $html);
    }

    public function test_a_legacy_cms_card_from_another_tenant_never_appears(): void
    {
        $this->legacyItem([
            'school_id' => $this->otherSchool,
            'title'     => 'FOREIGN CMS PROGRAMME',
        ]);

        $this->assertStringNotContainsString('FOREIGN CMS PROGRAMME', $this->cataloguePage());
    }

    public function test_an_unpublished_legacy_cms_card_never_appears(): void
    {
        $this->legacyItem(['title' => 'CMS HIDDEN PROGRAMME', 'status' => 0]);

        $this->assertStringNotContainsString('CMS HIDDEN PROGRAMME', $this->cataloguePage());
    }

    public function test_a_published_programme_appears_on_both_pages(): void
    {
        $this->publish($this->programme(['name' => 'VISIBLE PROGRAMME ALPHA', 'code' => 'VIS1']));

        $this->assertStringContainsString('VISIBLE PROGRAMME ALPHA', $this->home());
        $this->assertStringContainsString('VISIBLE PROGRAMME ALPHA', $this->cataloguePage());
    }

    // ══════════════════════════════════════════════════════════════════════
    // HOMEPAGE — a maximum of eight
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_homepage_renders_a_maximum_of_eight_programmes(): void
    {
        // Zero-padded names, because ordering is alphabetical for anything without an
        // explicit website_sort_order: "PROGRAMME 10" sorts before "PROGRAMME 2",
        // and a test that meant to check the first eight would otherwise be checking
        // an arbitrary eight.
        for ($i = 1; $i <= 12; $i++) {
            $this->publish($this->programme([
                'name' => 'PUBLISHED PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'code' => sprintf('PUB%02d', $i),
            ]));
        }

        $html = $this->home();

        for ($i = 1; $i <= 8; $i++) {
            $this->assertStringContainsString('PUBLISHED PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $html);
        }

        // Nine through twelve are published and on the catalogue page, but must NOT
        // be in the homepage block.
        for ($i = 9; $i <= 12; $i++) {
            $this->assertStringNotContainsString(
                'PUBLISHED PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                $html,
                "Programme {$i} must not appear in an eight-card homepage block"
            );
        }

        // All twelve are genuinely published — this is a cap on the homepage block,
        // not a publication limit.
        $this->assertSame(8, $this->catalogue()->homepageCards()->count());
        $this->assertSame(12, $this->catalogue()->cards()->count());
    }

    /**
     * @dataProvider partialCounts
     */
    public function test_fewer_than_eight_programmes_render_exactly_those_and_no_filler(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->publish($this->programme([
                'name' => 'ONLY PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'code' => sprintf('ONLY%02d', $i),
            ]));
        }

        $html = $this->home();

        // The block shows everything up to eight, and nothing beyond it.
        $expected = min($count, PublicProgrammeCatalogue::HOMEPAGE_LIMIT);

        $this->assertSame($expected, $this->catalogue()->homepageCards()->count());

        // Exactly `expected` programme cards. A filler card would make this number
        // larger, and asserting the COUNT rather than the presence of the cards is
        // what actually catches invented content.
        $this->assertSame(
            $expected,
            substr_count($html, 'piie-card--programme'),
            'the block must render exactly the published programmes, with no filler cards'
        );

        for ($i = 1; $i <= $expected; $i++) {
            $this->assertStringContainsString('ONLY PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $html);
        }
    }

    public static function partialCounts(): array
    {
        // 1, 3, 5, 6, 7 and 9 are the counts the brief names explicitly; 2, 4 and 8
        // complete the grid-shaped cases.
        return [
            'one programme' => [1],
            'two programmes' => [2],
            'three programmes' => [3],
            'four programmes, one full row' => [4],
            'five programmes' => [5],
            'six programmes' => [6],
            'seven programmes' => [7],
            'exactly eight' => [8],
            'more than eight' => [9],
        ];
    }

    public function test_a_single_programme_renders_one_card_and_no_empty_grid_gaps(): void
    {
        $this->publish($this->programme(['name' => 'THE ONLY PROGRAMME', 'code' => 'ONE1']));

        $html = $this->home();

        $this->assertStringContainsString('THE ONLY PROGRAMME', $html);
        $this->assertSame(1, substr_count($html, 'piie-card--programme'));
        // The CTA to the full catalogue must still be offered.
        $this->assertStringContainsString('View All Programmes', $html);
    }

    public function test_homepage_ordering_follows_website_sort_order_then_name(): void
    {
        $this->publish($this->programme(['name' => 'ZEBRA PROGRAMME', 'code' => 'ZBR1', 'website_sort_order' => 1]));
        $this->publish($this->programme(['name' => 'ALPHA PROGRAMME', 'code' => 'ALP1', 'website_sort_order' => 2]));
        // Unpositioned programmes sort after every explicitly ordered one, then
        // alphabetically — so adding one never reshuffles what an administrator
        // has deliberately placed.
        $this->publish($this->programme(['name' => 'BRAVO PROGRAMME', 'code' => 'BRV1']));
        $this->publish($this->programme(['name' => 'ALPHA UNSORTED', 'code' => 'AUS1']));

        $titles = $this->catalogue()->homepageCards()->pluck('title')->all();

        $this->assertSame([
            'ZEBRA PROGRAMME',
            'ALPHA PROGRAMME',
            'ALPHA UNSORTED',
            'BRAVO PROGRAMME',
        ], $titles);
    }

    public function test_an_empty_catalogue_shows_a_designed_state_not_a_blank_page(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('Programme catalogue is being prepared', $html);
        // A designed empty state still offers the visitor a way forward.
        $this->assertStringContainsString('How to apply', $html);
    }

    public function test_the_catalogue_page_empty_state_distinguishes_no_content_from_no_matches(): void
    {
        // Nothing published at all.
        $empty = $this->cataloguePage();
        $this->assertStringContainsString('Programme catalogue is being prepared', $empty);

        // Something published, but the filter matches nothing. Telling this visitor
        // to "clear filters" when there is nothing to clear is actively unhelpful.
        $this->publish($this->programme(['name' => 'SOMETHING ELSE ENTIRELY', 'code' => 'SOM1']));
        $filtered = $this->cataloguePage(['q' => 'zzzzznothing']);
        $this->assertStringContainsString('No programmes match those filters', $filtered);
        $this->assertStringNotContainsString('Programme catalogue is being prepared', $filtered);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRICE — never a zero, never an invented basis
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_priced_programme_shows_amount_currency_and_basis(): void
    {
        $this->publish($this->programme([
            'name' => 'PRICED PROGRAMME', 'code' => 'PRI1',
            'tuition_fee' => 4500000, 'tuition_fee_basis' => 'per_year',
        ]));

        $html = $this->cataloguePage();

        $this->assertStringContainsString('4,500,000', $html);
        $this->assertStringContainsString('UGX', $html);
        $this->assertStringContainsString('per academic year', $html);
    }

    public function test_a_programme_with_no_price_states_contact_for_fees_and_never_zero(): void
    {
        $this->publish($this->programme(['name' => 'UNPRICED PROGRAMME', 'code' => 'UNP1']));

        $html = $this->cataloguePage();

        $this->assertStringContainsString('Contact us for tuition fees', $html);
        // "0" next to a currency is the specific failure the brief names. Checked
        // as a rendered currency-and-zero pair, since the bare digit 0 appears
        // legitimately in dates, pagination and ARIA attributes.
        $this->assertDoesNotMatchRegularExpression('/UGX\s*0\b/i', $html, 'UGX 0 must never be rendered');
        $this->assertDoesNotMatchRegularExpression('/\bFree\b/', $html, 'the word Free must never be rendered');
    }

    public function test_a_zero_fee_programme_is_withheld_rather_than_shown_as_free(): void
    {
        $this->publish($this->programme([
            'name' => 'ZERO FEE PROGRAMME', 'code' => 'ZER1',
            'tuition_fee' => 0, 'tuition_fee_basis' => 'per_programme',
        ]));

        $html = $this->cataloguePage();

        $this->assertStringContainsString('Contact us for tuition fees', $html);
        $this->assertStringNotContainsString('Free', $html);
    }

    public function test_an_amount_with_no_basis_is_not_printed_as_a_bare_number(): void
    {
        $this->publish($this->programme([
            'name' => 'UNSTATED BASIS PROGRAMME', 'code' => 'UNS1',
            'tuition_fee' => 7700000,
        ]));

        $html = $this->cataloguePage();

        $this->assertStringNotContainsString('7,700,000', $html,
            'a figure with no stated period must be withheld, not printed bare');
        $this->assertStringContainsString('Contact us for tuition fees', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // COVER IMAGES AND THE BRANDED FALLBACK
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_programme_with_no_cover_renders_the_branded_fallback(): void
    {
        $this->publish($this->programme(['name' => 'NO PHOTO PROGRAMME', 'code' => 'NOP1']));

        $html = $this->cataloguePage();

        $this->assertStringContainsString('piie-card__media--fallback', $html);
        $this->assertStringContainsString('piie-card__fallback-label', $html);
        // An empty media frame is what the fallback exists to prevent.
        $this->assertDoesNotMatchRegularExpression('/piie-card__media">\s*<\/div>/', $html);
    }

    public function test_a_cover_row_pointing_at_a_missing_file_falls_back_rather_than_breaking_the_image(): void
    {
        $this->publish($this->programme([
            'name' => 'MISSING FILE PROGRAMME', 'code' => 'MIS1',
            'cover_image_path' => 'does-not-exist.jpg',
        ]));

        $html = $this->cataloguePage();

        // A row whose bytes are gone must not emit an <img> the server will 404.
        $this->assertStringContainsString('MISSING FILE PROGRAMME', $html);
        $this->assertStringContainsString('piie-card__media--fallback', $html);
        $this->assertStringNotContainsString('does-not-exist.jpg', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DUPLICATE PREVENTION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_projection_row_is_never_rendered_alongside_its_programme(): void
    {
        $programme = $this->publish($this->programme([
            'name' => 'PROJECTED PROGRAMME', 'code' => 'PRJ1',
        ]));

        // Exactly what Stage 1's publisher creates.
        $this->legacyItem([
            'title'     => 'PROJECTED PROGRAMME',
            'meta_json' => json_encode(['programme_id' => $programme->id]),
        ]);

        $html = $this->cataloguePage();

        $this->assertSame(1, substr_count($html, 'PROJECTED PROGRAMME'),
            'a projection row and its Programme must not both be listed');
    }

    public function test_a_legacy_row_with_the_same_title_as_a_programme_is_not_listed_twice(): void
    {
        $this->publish($this->programme(['name' => 'BSc Computer Science', 'code' => 'DUP1']));
        $this->legacyItem(['title' => '  bsc   COMPUTER   science  ']);

        $html = $this->cataloguePage();

        // Case, spacing and punctuation differences are the same qualification to a
        // reader, so the legacy row is suppressed rather than listed beside it.
        $this->assertSame(1, substr_count($html, 'piie-card--programme'));
    }

    public function test_the_academic_record_wins_the_shared_title(): void
    {
        $this->publish($this->programme([
            'name' => 'BSc Computer Science', 'code' => 'DUP2',
            'tuition_fee' => 3000000, 'tuition_fee_basis' => 'per_programme',
        ]));
        $this->legacyItem(['title' => 'BSc Computer Science', 'description' => 'OLD CMS COPY']);

        $html = $this->cataloguePage();

        // The authoritative copy is the academic one, so its price is what shows.
        $this->assertStringContainsString('3,000,000', $html);
        $this->assertStringNotContainsString('OLD CMS COPY', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CMS COMPATIBILITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_legacy_hand_authored_cards_are_still_shown(): void
    {
        $this->legacyItem(['title' => 'LEGACY MARKETING CARD', 'description' => 'Administrator copy.']);

        $html = $this->cataloguePage();

        // Legacy CMS content is NOT deleted or hidden by this stage. That would be a
        // content change nobody asked for.
        $this->assertStringContainsString('LEGACY MARKETING CARD', $html);
        $this->assertStringContainsString('Administrator copy.', $html);
    }

    public function test_academic_records_are_listed_before_legacy_cards(): void
    {
        $this->legacyItem(['title' => 'AAA LEGACY CARD', 'sort_order' => 1]);
        $this->publish($this->programme(['name' => 'ZZZ ACADEMIC PROGRAMME', 'code' => 'ORD1']));

        $titles = $this->catalogue()->cards()->pluck('title')->all();

        // The academic record is authoritative, so it leads regardless of the CMS
        // sort_order an administrator set long ago.
        $this->assertSame('ZZZ ACADEMIC PROGRAMME', $titles[0]);
        $this->assertContains('AAA LEGACY CARD', $titles);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CTA DESTINATION SAFETY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_card_never_links_to_an_internal_route(): void
    {
        foreach ([
            '/admin/programmes',
            '/student/my-courses',
            '/teacher/course-offerings/12',
            'https://piie.ac.ug/admin/dashboard',
        ] as $index => $link) {
            $this->legacyItem(['title' => "INTERNAL LINK CARD {$index}", 'link' => $link]);
        }

        $html = $this->cataloguePage();

        foreach (['/admin/programmes', '/student/my-courses', '/teacher/course-offerings', '/admin/dashboard'] as $internal) {
            $this->assertStringNotContainsString(
                'href="'.$internal,
                $html,
                "a public programme card must not link to {$internal}"
            );
        }
    }

    public function test_a_dangerous_link_scheme_is_refused(): void
    {
        $this->legacyItem(['title' => 'SCRIPT LINK CARD', 'link' => 'javascript:alert(1)']);

        $html = $this->cataloguePage();

        $this->assertStringNotContainsString('javascript:alert', $html);
        // The card still exists and still offers a working destination.
        $this->assertStringContainsString('SCRIPT LINK CARD', $html);
    }

    public function test_a_safe_external_link_is_honoured(): void
    {
        $this->legacyItem([
            'title' => 'PROSPECTUS CARD',
            'link'  => 'https://piie.ac.ug/website/prospectus',
        ]);

        $html = $this->cataloguePage();

        $this->assertStringContainsString('https://piie.ac.ug/website/prospectus', $html);
    }

    public function test_a_programme_card_offers_the_application_route_by_default(): void
    {
        $this->publish($this->programme(['name' => 'APPLY PROGRAMME', 'code' => 'APP1']));

        $html = $this->cataloguePage();

        // A published Programme has no detail page yet. Rather than link a card to
        // "#" or to an internal route, the visitor is sent to the application form.
        $this->assertStringContainsString('Apply for this programme', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SEARCH, FILTERS, PAGINATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_search_matches_the_title(): void
    {
        $this->publish($this->programme(['name' => 'Bachelor of Science in Accounting', 'code' => 'ACC1']));
        $this->publish($this->programme(['name' => 'Master of Business Administration', 'code' => 'MBA1']));

        $html = $this->cataloguePage(['q' => 'accounting']);

        $this->assertStringContainsString('Bachelor of Science in Accounting', $html);
        $this->assertStringNotContainsString('Master of Business Administration', $html);
    }

    public function test_search_also_matches_the_programme_code(): void
    {
        $this->publish($this->programme(['name' => 'Accounting', 'code' => 'ACC2']));
        $this->publish($this->programme(['name' => 'Biology', 'code' => 'BIO2']));

        $html = $this->cataloguePage(['q' => 'bio2']);

        $this->assertStringContainsString('Biology', $html);
        $this->assertStringNotContainsString('Accounting', $html);
    }

    public function test_the_level_filter_narrows_the_results(): void
    {
        $this->publish($this->programme(['name' => 'BACHELOR PROGRAMME', 'code' => 'LVL1', 'level' => 'Bachelors']));
        $this->publish($this->programme(['name' => 'MASTER PROGRAMME', 'code' => 'LVL2', 'level' => 'Masters']));

        $html = $this->cataloguePage(['level' => 'Masters']);

        $this->assertStringContainsString('MASTER PROGRAMME', $html);
        $this->assertStringNotContainsString('BACHELOR PROGRAMME', $html);
    }

    public function test_pagination_caps_the_page_size_and_offers_a_second_page(): void
    {
        // Zero-padded, because the catalogue orders unpositioned programmes by name:
        // "PROGRAMME 13" would sort before "PROGRAMME 2" and land on page one.
        for ($i = 1; $i <= 14; $i++) {
            $this->publish($this->programme([
                'name' => 'PAGED PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'code' => sprintf('PAG%02d', $i),
            ]));
        }

        $pageOne = $this->cataloguePage();
        $this->assertSame(12, substr_count($pageOne, 'piie-card--programme'));
        $this->assertStringContainsString('page 1 of 2', $pageOne);

        $pageTwo = $this->cataloguePage(['page' => 2]);
        $this->assertSame(2, substr_count($pageTwo, 'piie-card--programme'));
        $this->assertStringContainsString('PAGED PROGRAMME 13', $pageTwo);
        $this->assertStringContainsString('PAGED PROGRAMME 14', $pageTwo);
        // The first page's tail must not leak onto the second.
        $this->assertStringNotContainsString('PAGED PROGRAMME 01', $pageTwo);
    }

    public function test_a_page_number_past_the_end_clamps_rather_than_erroring(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->publish($this->programme([
                'name' => 'CLAMPED PROGRAMME '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'code' => sprintf('CLP%02d', $i),
            ]));
        }

        // A bookmarked or stale link must not 500 or render an empty grid.
        $html = $this->cataloguePage(['page' => 99]);

        $this->assertStringContainsString('page 2 of 2', $html);
        $this->assertSame(2, substr_count($html, 'piie-card--programme'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // LAYOUT CONTRACT
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_grid_is_four_two_one_on_the_shared_breakpoints(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-blocks.css'));

        // Four across at the shared grid's desktop breakpoint.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 992px\)[\s\S]*?piie-catalogue__grid\s*\{[^}]*repeat\(4,\s*minmax\(0,\s*1fr\)\)/',
            $css
        );

        // Two across at the shared grid's tablet breakpoint.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)[\s\S]*?piie-catalogue__grid\s*\{[^}]*repeat\(2,\s*minmax\(0,\s*1fr\)\)/',
            $css
        );

        // One column is the base rule.
        $this->assertMatchesRegularExpression(
            '/\.piie-catalogue__grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/',
            $css
        );

        // The old 576px two-column step must be gone: it put two narrow cards on a
        // large phone in landscape, where the fee basis wrapped to four lines.
        $this->assertDoesNotMatchRegularExpression(
            '/@media \(min-width: 576px\)\s*\{[^}]*piie-catalogue__grid\s*\{[^}]*repeat\(2/',
            $css
        );
    }

    public function test_the_homepage_block_uses_the_four_column_grid(): void
    {
        // At least one programme: with none published the homepage renders its empty
        // state and there is no grid to measure, which would make this assertion
        // pass or fail for the wrong reason.
        $this->publish($this->programme(['name' => 'GRID PROGRAMME', 'code' => 'GRD1']));

        $html = $this->home();

        $this->assertStringContainsString('piie-grid piie-grid--4', $html);
        $this->assertStringContainsString('piie-card--programme', $html);
    }

    public function test_both_pages_use_the_same_card_partial_classes(): void
    {
        $this->publish($this->programme(['name' => 'SHARED CARD PROGRAMME', 'code' => 'SHR1']));
        $this->legacyItem(['title' => 'SHARED CARD LEGACY']);

        $home = $this->home();
        $catalogue = $this->cataloguePage();

        // The same component on both pages, so the two cannot drift apart.
        foreach (['piie-card--programme', 'piie-card__media', 'piie-card__body', 'piie-card__title'] as $class) {
            $this->assertStringContainsString($class, $home);
            $this->assertStringContainsString($class, $catalogue);
        }
    }

    public function test_no_bootstrap_card_classes_leak_into_the_public_catalogue(): void
    {
        $this->publish($this->programme(['name' => 'NO BOOTSTRAP CARD', 'code' => 'NBC1']));

        $html = $this->cataloguePage();

        // The existing PIIE design system must be reused, not replaced with generic
        // Bootstrap cards. Only the filter bar and pagination are Bootstrap-free
        // PIIE components; a `card`/`col-*` inside a programme card would mean the
        // design language was swapped out.
        $this->assertStringNotContainsString('card-item', $html);
        $this->assertStringNotContainsString('col-lg-4', $html);
    }

    public function test_the_price_markup_is_present_and_scoped_to_a_programme_card(): void
    {
        $this->publish($this->programme([
            'name' => 'TYPED PRICE PROGRAMME', 'code' => 'TYP1',
            'tuition_fee' => 1200000, 'tuition_fee_basis' => 'per_semester',
        ]));

        $html = $this->cataloguePage();

        // A priced card renders the amount, its currency and its basis.
        $this->assertStringContainsString('piie-card__price', $html);
        $this->assertStringContainsString('piie-card__price-amount', $html);
        $this->assertStringContainsString('piie-card__price-currency', $html);
        $this->assertStringContainsString('piie-card__price-figure', $html);
        $this->assertStringContainsString('piie-card__price-basis', $html);

        // And it does NOT also render the contact line. The two states are
        // alternatives: showing both would tell a candidate a price is available
        // on request while also printing it.
        $this->assertStringNotContainsString('piie-card__price--contact', $html);
    }

    public function test_an_unpriced_card_renders_the_contact_line_instead_of_a_number(): void
    {
        $this->publish($this->programme(['name' => 'NO PRICE TYPED', 'code' => 'NOP2']));

        $html = $this->cataloguePage();

        // The contact line replaces the amount block entirely.
        $this->assertStringContainsString('piie-card__price--contact', $html);
        $this->assertStringNotContainsString('piie-card__price-figure', $html);
    }
}