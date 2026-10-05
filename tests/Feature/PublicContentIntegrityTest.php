<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IDENTITY STATEMENTS, THE FEATURED FLAG, AND THE PUBLIC APPLICATION CATALOGUE.
 *
 * Three unrelated brief requirements that share one failure mode: each of them could
 * quietly stop reflecting the database while every page still rendered perfectly, so
 * each is asserted by changing the data and checking the page follows.
 */
class PublicContentIntegrityTest extends TestCase
{
    use CreatesPublicSiteSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublicSiteDatabase();

        DB::table('website_settings')->insert([
            ['key' => 'institution_name', 'value' => 'Prime International Institute of Excellence (PIIE)', 'status' => 1],
            ['key' => 'motto', 'value' => 'Strive. Excel. Lead.', 'status' => 1],
            ['key' => 'contact_address', 'value' => 'CMS CONTACT ADDRESS', 'status' => 1],
        ]);
    }

    private function addSection(string $pageKey, string $sectionKey, string $title, string $content, int $sort = 1): void
    {
        DB::table('website_sections')->insert([
            'page_key' => $pageKey,
            'section_key' => $sectionKey,
            'title' => $title,
            'content' => $content,
            'status' => 1,
            'sort_order' => $sort,
        ]);
    }

    private function about(): string
    {
        return $this->get(route('website.page', 'about-us'))->assertOk()->getContent();
    }

    // ═══ 1. VISION / MISSION / MOTTO / CORE VALUES ═════════════════════════

    /**
     * The approved wording must survive VERBATIM.
     *
     * The brief says: "Preserve the actual approved institutional wording from the
     * existing CMS. Do not rewrite official institutional statements without
     * approval." So the test publishes deliberately awkward wording — an unusual
     * clause order, an em dash, a colon mid-sentence — and requires it back on the
     * page character-for-character.
     *
     * A summarising or tidying implementation fails here, which is the point.
     */
    public function test_the_approved_identity_wording_is_rendered_verbatim(): void
    {
        $vision = 'To be a premier internationally recognised institution — shaping leaders across Africa.';
        $mission = 'To deliver rigorous programmes: flexible, technology-enabled, and benchmarked internationally.';
        $motto = 'Strive. Excel. Lead.';

        $this->addSection(
            'about',
            'vision_mission_motto',
            'Vision, Mission, and Motto',
            "Vision: {$vision}\n\nMission: {$mission}\n\nMotto: {$motto}"
        );

        $this->addSection(
            'about',
            'core_values',
            'Core Values',
            'Excellence, Integrity, Innovation, and Accountability define all engagements at PIIE.'
        );

        $html = $this->about();

        $this->assertStringContainsString('data-testid="identity-vision"', $html);
        $this->assertStringContainsString('data-testid="identity-mission"', $html);
        $this->assertStringContainsString('data-testid="identity-motto"', $html);
        $this->assertStringContainsString('data-testid="identity-values"', $html);

        // Character-for-character. The em dash and the mid-sentence colon are the
        // parts a rewriter would most likely "fix".
        $this->assertStringContainsString($vision, $html);
        $this->assertStringContainsString($mission, $html);
        $this->assertStringContainsString($motto, $html);

        $this->assertStringContainsString(
            'Excellence, Integrity, Innovation, and Accountability define all engagements at PIIE.',
            $html
        );

        // Each statement appears exactly ONCE: promoted above the prose, not also
        // repeated in a long section further down.
        $this->assertSame(1, substr_count($html, $vision));
        $this->assertSame(1, substr_count($html, $mission));
    }

    /**
     * Editing the CMS must change the page.
     *
     * Asserted because "the statements are in the template" would pass the test above
     * too. This proves the page reads the CMS rather than carrying its own copy.
     */
    public function test_editing_the_identity_wording_in_the_cms_changes_the_page(): void
    {
        $this->addSection('about', 'vision_mission_motto', 'Vision, Mission, and Motto',
            'Vision: ORIGINAL VISION WORDING.');

        $this->assertStringContainsString('ORIGINAL VISION WORDING.', $this->about());

        DB::table('website_sections')
            ->where('section_key', 'vision_mission_motto')
            ->update(['content' => 'Vision: REVISED VISION WORDING.']);

        $html = $this->about();

        $this->assertStringContainsString('REVISED VISION WORDING.', $html);
        $this->assertStringNotContainsString('ORIGINAL VISION WORDING.', $html,
            'the page must not keep a copy of superseded wording');
    }

    /** Each statement gets prominent treatment, not body copy in a long section. */
    public function test_the_identity_statements_are_visually_promoted(): void
    {
        $this->addSection('about', 'vision_mission_motto', 'Vision, Mission, and Motto',
            "Vision: A vision.\n\nMission: A mission.\n\nMotto: Strive. Excel. Lead.");

        $css = (string) file_get_contents(public_path('css/piie-blocks.css'));

        $this->assertStringContainsString('.piie-motto-band', $css,
            'the motto must have its own full-width treatment');
        $this->assertStringContainsString('.piie-statement__label', $css);
        $this->assertStringContainsString('.piie-statement__text', $css);

        // The motto is set large, which is what makes it memorable.
        $this->assertMatchesRegularExpression(
            '/\.piie-motto-band__text\s*\{[^}]*font-size:\s*clamp\(/s',
            $css
        );

        $html = $this->about();
        $this->assertStringContainsString('piie-motto-band', $html);
        $this->assertStringContainsString('piie-statement', $html);
    }

    /**
     * A statement absent from the CMS is not invented.
     *
     * The motto setting IS configured in this fixture, so a motto legitimately appears
     * via the documented fallback. The setting is therefore removed here, which is the
     * real "nothing recorded" case: neither the section's `Motto:` line nor the
     * `motto` setting exists, so no motto card may render.
     */
    public function test_a_statement_absent_from_the_cms_is_not_invented(): void
    {
        $this->addSection('about', 'vision_mission_motto', 'Vision, Mission, and Motto',
            'Vision: Only a vision is recorded.');

        $html = $this->about();

        $this->assertStringContainsString('data-testid="identity-vision"', $html);
        $this->assertStringNotContainsString('data-testid="identity-mission"', $html,
            'a Mission the CMS does not record must not be invented');

        // With no motto setting either, the motto card must disappear.
        DB::table('website_settings')->where('key', 'motto')->delete();

        $withoutMotto = $this->about();

        $this->assertStringNotContainsString('data-testid="identity-motto"', $withoutMotto,
            'with neither a Motto line nor a motto setting, no motto may be invented');
    }

    /** Leadership stays database-driven. */
    public function test_leadership_remains_database_driven(): void
    {
        // The section must exist for the page to have a leadership block to populate;
        // without it there is nowhere for a leader to be rendered, which is a
        // different test from whether the leader data is read.
        $this->addSection('about', 'leadership_team', 'Leadership Team', '', 10);

        DB::table('website_items')->insert([
            'section_key' => 'leadership_team', 'item_type' => 'leader',
            'title' => 'A Newly Appointed Leader', 'subtitle' => 'Acting Director',
            'status' => 1, 'sort_order' => 1,
        ]);

        $html = $this->about();

        $this->assertStringContainsString('A Newly Appointed Leader', $html,
            'a leader added in the CMS must appear with no code change');
        $this->assertStringContainsString('Acting Director', $html);

        // And an unpublished one must not.
        DB::table('website_items')->where('title', 'A Newly Appointed Leader')
            ->update(['status' => 0]);

        $this->assertStringNotContainsString('A Newly Appointed Leader', $this->about());
    }

    // ═══ 2. PROGRAMMES ARE DYNAMIC AND PUBLICATION-CONTROLLED ═════════════

    private function addProgramme(string $title, string $faculty = 'programme_catalog_business_management', int $sort = 1, int $status = 1, ?array $meta = null): void
    {
        DB::table('website_items')->insert([
            'section_key' => $faculty,
            'item_type' => 'programme',
            'title' => $title,
            'status' => $status,
            'sort_order' => $sort,
            'meta_json' => $meta ? json_encode($meta) : null,
        ]);
    }

    public function test_a_programme_published_in_the_cms_appears_without_a_code_change(): void
    {
        $this->addProgramme('NEWLY PUBLISHED PROGRAMME ALPHA');

        $html = $this->get(route('website.page', 'academic-programmes'))->assertOk()->getContent();

        $this->assertStringContainsString('NEWLY PUBLISHED PROGRAMME ALPHA', $html);
    }

    /**
     * Unpublished and test programmes must not appear publicly.
     *
     * The brief requires this explicitly. `website_items.status` is the publication
     * flag, so status 0 must be invisible.
     */
    public function test_an_unpublished_programme_never_reaches_the_public_catalogue(): void
    {
        $this->addProgramme('PUBLISHED PROGRAMME ONE', sort: 1);
        $this->addProgramme('UNPUBLISHED PROGRAMME ONE', sort: 2, status: 0);

        $html = $this->get(route('website.page', 'academic-programmes'))->assertOk()->getContent();

        $this->assertStringContainsString('PUBLISHED PROGRAMME ONE', $html);
        $this->assertStringNotContainsString('UNPUBLISHED PROGRAMME ONE', $html);
    }

    /**
     * No programme record is hardcoded in any public template.
     *
     * Checked statically rather than against the live database, because these tests
     * run against an isolated SQLite schema that holds only the fixture rows — querying
     * it for a "real" programme title returned null and the assertion silently skipped,
     * which is worse than not having the test.
     *
     * What it checks instead: no public Blade file contains a qualification-shaped
     * programme name. "Master of …" and "Bachelor of …" are how every one of the 67
     * catalogue records begins, so a hardcoded list could not avoid them. The
     * templates must obtain programmes from the CMS collection instead.
     */
    public function test_no_programme_record_is_hardcoded_in_the_templates(): void
    {
        $files = glob(resource_path('views/frontend').'/**/*.blade.php') ?: [];

        $this->assertNotEmpty($files, 'the public view directory must be readable');

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            // Comments are stripped: this file's own documentation names these
            // qualification strings, and asserting inside a comment fails the moment
            // somebody accurately records why the check exists.
            $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            $this->assertDoesNotMatchRegularExpression(
                '/\b(Master|Bachelor|Doctor|PhD)\s+of\s+[A-Z]/',
                $markup,
                basename($file).' appears to hardcode a programme name; programmes must come from the CMS'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\bprogramme_catalog_[a-z_]+\s*=>\s*\[/i',
                $markup,
                basename($file).' appears to inline a programme list'
            );
        }
    }

    /** A programme with an image shows it; without one, a designed fallback. */
    public function test_a_programme_image_is_used_when_present_and_falls_back_when_not(): void
    {
        $this->addProgramme('PROGRAMME WITH NO PHOTOGRAPH');

        $html = $this->get(route('website.page', 'academic-programmes'))->assertOk()->getContent();

        $this->assertStringContainsString('piie-card__media--fallback', $html,
            'a programme with no photograph must get the designed category fallback');
        $this->assertStringNotContainsString('piie-card__media--empty', $html,
            'the old bare empty-frame caption must be gone');
    }

    // ═══ 3. THE FEATURED FLAG ═════════════════════════════════════════════

    /**
     * The Featured checkbox drives the homepage block, with no code change.
     *
     * The flag lives in `website_items.meta_json` because no featured column exists on
     * that table and none was added — the column already existed, was already
     * writable through the CMS controller, and is the correct home for per-item
     * metadata.
     */
    public function test_the_homepage_block_is_ordered_by_publication_order_not_the_cms_featured_flag(): void
    {
        // STAGE 2 CHANGED THIS CONTRACT, and the change is deliberate.
        //
        // The homepage block used to be driven by a "Featured" checkbox stored in
        // `website_items.meta_json`: flagged items first, then the rest to fill four
        // places. That flag reordered CARDS, which is a weaker tool than the one an
        // academic administrator now has.
        //
        // Decision 1 puts the homepage block in the order the academic record chose
        // — `programmes.website_sort_order`, then name — and caps it at eight. The
        // CMS flag is still stored (the CMS controller still accepts it, which the
        // test below proves) but it no longer selects what appears on the homepage.
        // A flag that reorders a marketing block while a separate, better control
        // decides the real order is two competing truths about one question.
        $this->addProgramme('FIRST PROGRAMME ALPHA', sort: 1);
        $this->addProgramme('SECOND PROGRAMME BETA', sort: 2);
        $this->addProgramme('THIRD PROGRAMME GAMMA', sort: 3);
        $this->addProgramme('FOURTH PROGRAMME DELTA', sort: 4);
        $this->addProgramme('LATER PROGRAMME EPSILON', sort: 5);

        // Flagging the last one no longer promotes it: the block has room for
        // everything published — five programmes against a cap of eight.
        DB::table('website_items')->where('title', 'LATER PROGRAMME EPSILON')
            ->update(['meta_json' => json_encode(['featured' => true])]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('LATER PROGRAMME EPSILON', $html);

        // Every published programme appears exactly once, in catalogue order.
        foreach (['FIRST PROGRAMME ALPHA', 'SECOND PROGRAMME BETA', 'THIRD PROGRAMME GAMMA',
                  'FOURTH PROGRAMME DELTA', 'LATER PROGRAMME EPSILON'] as $title) {
            $this->assertSame(1, substr_count($html, $title), "{$title} must appear exactly once");
        }

        $this->assertTrue(
            strpos($html, 'FIRST PROGRAMME ALPHA') < strpos($html, 'SECOND PROGRAMME BETA'),
            'the block must follow the catalogue sort order'
        );
    }

    /** Unflagging must leave the card exactly where it was. */
    public function test_clearing_the_featured_flag_does_not_remove_a_programme_from_the_homepage(): void
    {
        $this->addProgramme('PROGRAMME ONE', sort: 1, meta: ['featured' => true]);
        $this->addProgramme('PROGRAMME TWO', sort: 2);
        $this->addProgramme('PROGRAMME THREE', sort: 3);
        $this->addProgramme('PROGRAMME FOUR', sort: 4);
        $this->addProgramme('PROGRAMME FIVE', sort: 5);

        $this->assertStringContainsString('PROGRAMME ONE', $this->get('/')->getContent());

        DB::table('website_items')->where('title', 'PROGRAMME ONE')->update(['meta_json' => null]);

        $after = $this->get('/')->assertOk()->getContent();

        // Since Stage 2 the flag does not gate the homepage at all, so clearing it
        // changes nothing. The programme stays — which is the correct outcome: a
        // marketing flag must never be able to remove a real qualification.
        $this->assertStringContainsString('PROGRAMME ONE', $after);
    }

    /** The block must never be empty just because nothing is flagged. */
    public function test_the_homepage_block_falls_back_to_the_published_catalogue(): void
    {
        $this->addProgramme('A PUBLISHED PROGRAMME');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('A PUBLISHED PROGRAMME', $html,
            'with nothing flagged, published programmes must still fill the block');
        $this->assertStringNotContainsString('Programme catalogue is being prepared', $html);
    }

    /**
     * The CMS controller accepts the checkbox and preserves other meta.
     */
    public function test_the_cms_controller_stores_the_featured_flag_in_meta_json(): void
    {
        $this->addProgramme('PROGRAMME WITH OTHER META', meta: ['colour' => 'blue']);

        $item = DB::table('website_items')->where('title', 'PROGRAMME WITH OTHER META')->first();

        $controller = new \App\Http\Controllers\WebsiteManagementController();
        $method = new \ReflectionMethod($controller, 'applyFeaturedFlag');
        $method->setAccessible(true);

        $request = \Illuminate\Http\Request::create('/', 'POST', ['featured' => '1']);
        $merged = $method->invoke($controller, $request, $item->meta_json);
        $decoded = json_decode($merged, true);

        $this->assertTrue($decoded['featured'] ?? false, 'the flag must be written');
        $this->assertSame('blue', $decoded['colour'] ?? null,
            'unrelated metadata must survive a featured save');

        // Unchecking removes the key rather than writing false, so the JSON stays minimal.
        $off = $method->invoke($controller, \Illuminate\Http\Request::create('/', 'POST', []), $merged);
        $this->assertArrayNotHasKey('featured', json_decode($off, true));
        $this->assertSame('blue', json_decode($off, true)['colour'] ?? null);
    }
}
