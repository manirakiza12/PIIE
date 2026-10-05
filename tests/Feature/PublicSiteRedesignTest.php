<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE PUBLIC WEBSITE REDESIGN.
 *
 * Two things are under test, and they are different in kind:
 *
 *   1. THE CMS CONTRACT. The redesign must not have replaced database content with
 *      hardcoded copy. Every claim here is about content that exists in the CMS and
 *      must appear, or must NOT appear, on the rendered page.
 *
 *   2. THE MARKUP CONTRACT. The accessibility and responsive requirements are
 *      asserted on the OUTPUT, because asserting them on the source would pass on a
 *      template that never renders.
 *
 * The hero video, the reduced-motion behaviour and the visual weight of the design
 * cannot be asserted here at all - they need a browser. Those are listed in the
 * handover as manual checks rather than claimed as verified.
 */
class PublicSiteRedesignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->buildSchema();
        $this->seedContent();
    }

    private function buildSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->unsignedInteger('role_id')->nullable(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('account_status')->default('active'); $t->string('password')->nullable();
            $t->timestamps();
        });

        Schema::create('schools', function (Blueprint $t) {
            $t->id(); $t->string('title')->nullable(); $t->string('school_type')->nullable(); $t->timestamps();
        });

        foreach (['classes', 'sessions', 'enrollment', 'daily_attendances', 'frontend_events',
                  'frontend_features', 'faq', 'packages'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('school_id')->nullable();
                $t->string('status')->nullable();
                $t->string('title')->nullable();
                $t->string('name')->nullable();
                $t->string('class_name')->nullable();
                $t->string('session_title')->nullable();
                $t->integer('session_id')->nullable();
                $t->integer('class_id')->nullable();
                $t->integer('timestamp')->nullable();
                $t->timestamps();
            });
        }

        Schema::create('website_pages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable(); $t->string('title')->nullable();
            $t->string('subtitle')->nullable(); $t->string('page_image')->nullable();
            $t->string('cta_button_text')->nullable(); $t->string('cta_button_link')->nullable();
            $t->boolean('show_in_navigation')->nullable(); $t->unsignedInteger('display_order')->nullable();
            $t->string('nav_title')->nullable(); $t->string('slug')->nullable();
            $t->boolean('status')->default(1); $t->unsignedInteger('sort_order')->nullable();
            $t->timestamps();
        });

        Schema::create('website_sections', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable(); $t->string('section_key')->nullable();
            $t->string('title')->nullable(); $t->string('subtitle')->nullable();
            $t->text('content')->nullable(); $t->text('extra_json')->nullable();
            $t->string('image')->nullable(); $t->boolean('status')->default(1);
            $t->unsignedInteger('sort_order')->nullable(); $t->timestamps();
        });

        Schema::create('website_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('section_key')->nullable(); $t->string('item_type')->nullable();
            $t->string('title')->nullable(); $t->string('subtitle')->nullable();
            $t->text('description')->nullable(); $t->longText('content')->nullable();
            $t->string('image')->nullable(); $t->string('link')->nullable();
            $t->string('button_text')->nullable(); $t->boolean('status')->default(1);
            $t->unsignedInteger('sort_order')->nullable(); $t->text('meta_json')->nullable();
            $t->timestamps();
        });

        Schema::create('website_settings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key')->nullable(); $t->text('value')->nullable();
            $t->boolean('is_json')->default(false); $t->boolean('status')->default(1);
            $t->timestamps();
        });

        Schema::create('website_seo_settings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable(); $t->string('meta_title')->nullable();
            $t->text('meta_description')->nullable(); $t->string('meta_keywords')->nullable();
            $t->string('canonical_url')->nullable(); $t->boolean('status')->default(1);
            $t->timestamps();
        });
    }

    private function seedContent(): void
    {
        // `get_settings()` reads `global_settings`. `frontend_view` gates the whole
        // public site: both controllers redirect to /login when it is not '1', so
        // without this row every request would 302 and assert nothing.
        Schema::create('global_settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->nullable(); $t->text('value')->nullable();
        });

        DB::table('global_settings')->insert(['key' => 'frontend_view', 'value' => '1']);

        foreach ([
            ['home', 'Home', 'home'],
            ['about', 'About Us', 'about-us'],
            ['programs', 'Academic Programmes', 'academic-programmes'],
            ['admissions', 'Admissions', 'admissions'],
            ['research', 'Research & Innovation', 'research-and-innovation'],
            ['contact', 'Contact Us', 'contact-us'],
            ['privacy', 'Privacy Policy', 'privacy-terms'],
        ] as [$key, $title, $slug]) {
            DB::table('website_pages')->insert([
                'page_key' => $key, 'title' => $title, 'slug' => $slug,
                'status' => 1, 'display_order' => 0, 'sort_order' => 0,
            ]);
        }

        // Every row carries the SAME keys. A multi-row INSERT requires it, and an
        // omitted `subtitle` on one row is a "VALUES must have the same number of
        // terms" error rather than a null.
        DB::table('website_sections')->insert([
            ['page_key' => 'home', 'section_key' => 'hero_slider', 'status' => 1, 'sort_order' => 1,
             'title' => 'Prime International Institute of Excellence (PIIE)',
             'subtitle' => 'CMS HERO SUBTITLE', 'image' => null, 'extra_json' => null,
             'content' => 'CMS HERO BODY COPY about ODeL.', 'created_at' => now(), 'updated_at' => now()],

            ['page_key' => 'about', 'section_key' => 'why_choose_us', 'status' => 1, 'sort_order' => 2,
             'title' => 'Why Choose PIIE', 'subtitle' => null, 'image' => null, 'extra_json' => null,
             'content' => 'CMS WHY CHOOSE BODY.', 'created_at' => now(), 'updated_at' => now()],

            ['page_key' => 'about', 'section_key' => 'about_institution', 'status' => 1, 'sort_order' => 3,
             'title' => 'About Us', 'subtitle' => 'CMS ABOUT SUBTITLE', 'image' => null, 'extra_json' => null,
             'content' => 'CMS ABOUT BODY.', 'created_at' => now(), 'updated_at' => now()],

            ['page_key' => 'programs', 'section_key' => 'online_learning_odel', 'status' => 1, 'sort_order' => 4,
             'title' => 'Online and Distance Learning', 'subtitle' => null, 'image' => null, 'extra_json' => null,
             'content' => 'CMS ODEL BODY.', 'created_at' => now(), 'updated_at' => now()],

            ['page_key' => 'programs', 'section_key' => 'student_support_services', 'status' => 1, 'sort_order' => 5,
             'title' => 'Student Support Services', 'subtitle' => null, 'image' => null, 'extra_json' => null,
             'content' => 'CMS SUPPORT BODY.', 'created_at' => now(), 'updated_at' => now()],

            ['page_key' => 'admissions', 'section_key' => 'faqs', 'status' => 1, 'sort_order' => 6,
             'title' => 'Frequently Asked Questions', 'subtitle' => 'CMS FAQ SUBTITLE', 'image' => null,
             'extra_json' => null, 'content' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([
            ['admissions', 'step', 'Select your programme', 1],
            ['admissions', 'step', 'Complete the application form', 2],
            ['admissions', 'step', 'Complete your registration', 3],
            ['entry_requirements', 'requirement', "Master's Degree & Postgraduate Diploma", 1],
            ['entry_requirements', 'requirement', "Bachelor's Degree", 2],
            ['entry_requirements', 'requirement', 'Diploma', 3],
            ['leadership_team', 'leader', 'Twinamatsiko Naboth, PhD.c', 1],
            ['programme_catalog_graduate_school', 'programme', 'Master of Business Administration', 1],
            ['programme_catalog_graduate_school', 'programme', 'PUBLISHED PROGRAMME TWO', 2],
            ['programme_catalog_business_management', 'programme', 'SECRET UNPUBLISHED PROGRAMME', 3],
            ['news_events', 'news', 'CMS NEWS ITEM ONE', 1],
            ['faqs', 'faq', 'CMS FAQ QUESTION ONE', 1],
            ['faqs', 'faq', 'CMS FAQ QUESTION TWO', 2],
        ] as [$section, $type, $title, $order]) {
            DB::table('website_items')->insert([
                'section_key' => $section, 'item_type' => $type, 'title' => $title,
                // The UNPUBLISHED programme is marked so its absence is provable.
                'status' => $title === 'SECRET UNPUBLISHED PROGRAMME' ? 0 : 1,
                'sort_order' => $order,
                'content' => 'CMS FAQ ANSWER BODY.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            'institution_name' => 'Prime International Institute of Excellence (PIIE)',
            'tagline' => 'CMS TAGLINE VALUE',
            'motto' => 'CMS MOTTO VALUE',
            'hero_badge' => 'CMS HERO BADGE',
            'footer_copyright' => 'CMS COPYRIGHT LINE',
            // The ONE contact detail the live CMS actually holds. Present here so the
            // contact page's verified-channel path is exercised rather than only its
            // empty state.
            'contact_address' => 'CMS CONTACT ADDRESS',
        ] as $key => $value) {
            DB::table('website_settings')->insert(['key' => $key, 'value' => $value, 'status' => 1]);
        }
    }

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    // ═══ 1. THE CMS CONTRACT ════════════════════════════════════════════════

    public function test_the_existing_welcome_wording_is_still_rendered_from_the_cms(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('CMS HERO SUBTITLE', $html,
            'the hero subtitle must come from website_sections, not from the template');
        $this->assertStringContainsString('CMS HERO BODY COPY', $html);
        $this->assertStringContainsString('CMS HERO BADGE', $html);
        $this->assertStringContainsString('CMS WHY CHOOSE BODY', $html);
        $this->assertStringContainsString('CMS ABOUT BODY', $html);
        $this->assertStringContainsString('CMS ODEL BODY', $html);
        $this->assertStringContainsString('CMS SUPPORT BODY', $html);
        $this->assertStringContainsString('CMS MOTTO VALUE', $html);
        $this->assertStringContainsString('CMS TAGLINE VALUE', $html);
        $this->assertStringContainsString('CMS COPYRIGHT LINE', $html);
    }

    public function test_programme_records_come_from_the_cms_and_unpublished_ones_are_hidden(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('PUBLISHED PROGRAMME TWO', $html);
        $this->assertStringNotContainsString('SECRET UNPUBLISHED PROGRAMME', $html,
            'an unpublished programme must never reach the public page');
    }

    public function test_faq_and_news_content_come_from_the_cms(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('CMS FAQ QUESTION ONE', $html);
        $this->assertStringContainsString('CMS FAQ QUESTION TWO', $html);
        $this->assertStringContainsString('CMS NEWS ITEM ONE', $html);
    }

    public function test_editing_a_cms_record_is_reflected_on_the_public_page(): void
    {
        // This is the Super Admin requirement: change the row, see the change.
        DB::table('website_sections')
            ->where('section_key', 'hero_slider')
            ->update(['subtitle' => 'EDITED BY SUPER ADMIN SUBTITLE']);

        $this->assertStringContainsString('EDITED BY SUPER ADMIN SUBTITLE', $this->home());
    }

    public function test_unpublishing_a_section_removes_it_from_the_public_page(): void
    {
        DB::table('website_items')->where('title', 'CMS FAQ QUESTION ONE')->update(['status' => 0]);

        $this->assertStringNotContainsString('CMS FAQ QUESTION ONE', $this->home());
    }

    // ═══ 2. THE HERO ════════════════════════════════════════════════════════

    public function test_the_hero_uses_a_full_width_background_video_with_the_correct_source(): void
    {
        $html = $this->home();

        // The LANDSCAPE file. The supplied folder also contains a 1080x1920
        // PORTRAIT video, which is not a desktop hero; naming the landscape file
        // here is what pins that decision down.
        $this->assertStringContainsString('8196798-hd_1920_1080_25fps.mp4', $html,
            'the hero must use the 1920x1080 landscape source');
    }

    public function test_the_video_is_muted_autoplaying_looping_and_inline(): void
    {
        $html = $this->home();

        $this->assertMatchesRegularExpression('/<video[^>]*\bmuted\b/', $html,
            'an unmuted background video is unusable and hostile');
        $this->assertMatchesRegularExpression('/<video[^>]*\bautoplay\b/', $html);
        $this->assertMatchesRegularExpression('/<video[^>]*\bloop\b/', $html);
        $this->assertMatchesRegularExpression('/<video[^>]*\bplaysinline\b/', $html,
            'without playsinline, iOS takes the video fullscreen and the banner is gone');
    }

    public function test_a_poster_and_an_image_fallback_are_both_present(): void
    {
        $html = $this->home();

        $this->assertMatchesRegularExpression('/<video[^>]*\bposter="[^"]+"/', $html,
            'a poster is what the visitor sees before the video decodes');

        // A separate <img> inside the hero: this is the fallback for a browser that
        // cannot play the file, for save-data, and for reduced motion.
        $this->assertStringContainsString('piie-hero__poster', $html);
    }

    public function test_the_video_is_prepared_for_autoplay_without_preloading_the_whole_file(): void
    {
        $html = $this->home();

        /**
         * preload="metadata", not preload="none".
         *
         * `none` asks the browser to fetch nothing. Autoplay often overrides it, but
         * not reliably, and with nothing buffered the element can sit at readyState 0
         * and never fire `canplay` - which is the event the hero script uses to ask
         * for playback. That was the second cause of "autoplay does not work".
         *
         * `metadata` fetches the index and first frame only: the poster still paints
         * immediately and is still the LCP, and the file is playable as soon as the
         * hero is on screen.
         */
        $this->assertMatchesRegularExpression(
            '/<video[^>]*\bpreload="metadata"/',
            $html,
            'the hero must request metadata, not nothing, or playback may never start'
        );

        $this->assertStringNotContainsString('preload="none"', $html);

        // `auto` would pull all 5 MB during load and compete with the page for
        // bandwidth, which is the other half of the same trade-off.
        $this->assertDoesNotMatchRegularExpression(
            '/<video[^>]*\bpreload="auto"/',
            $html,
            'preload="auto" would fetch the whole clip ahead of the content'
        );

        // The poster is what the visitor sees while any of that happens.
        $this->assertMatchesRegularExpression('/<video[^>]*\bposter="[^"]+"/', $html);
    }

    public function test_playback_state_is_driven_by_the_media_element_not_by_our_own_intent(): void
    {
        $js = (string) file_get_contents(public_path('js/piie-hero.js'));

        /**
         * THE THIRD CAUSE OF "AUTOPLAY DOES NOT WORK".
         *
         * `.piie-hero__poster` is an <img> layered ABOVE the video at z-index 1, and
         * it is hidden by the `.is-playing` class on the hero — not by autoplay.
         * In the first version `setState(true)` was reachable only from the Pause
         * button: every call site passed `false`. The browser's own autoplay never
         * called it, so `is-playing` was never added and a perfectly healthy,
         * autoplaying video stayed invisible behind a still photograph.
         *
         * Asserted because the bug is invisible in a source read and obvious on
         * screen, and because the next person to "simplify" this will put it back.
         */
        $this->assertStringContainsString(
            "video.addEventListener('playing'",
            $js,
            'playback state must come from the media element, so autoplay reports itself'
        );

        $this->assertStringContainsString(
            "video.addEventListener('play'",
            $js,
            'a manual play() must also be reflected in the state'
        );

        $this->assertStringContainsString(
            "video.addEventListener('pause'",
            $js,
            'a pause must remove the playing state'
        );

        // The class that lifts the poster off the video.
        $this->assertStringContainsString("hero.classList.toggle('is-playing'", $js);

        // Playback is requested once the browser says it CAN play.
        $this->assertStringContainsString("'canplay'", $js,
            'playback must be requested from the canplay event, not at parse time');

        // And the error is reported, never swallowed.
        $this->assertStringContainsString("video.addEventListener('error'", $js);
        $this->assertStringContainsString('Background video unavailable', $js,
            'a load failure must be stated in the control, not hidden behind the poster');
    }

    public function test_a_visible_pause_and_play_control_is_present(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('piie-hero__toggle', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html,
            'the control must expose its state, or it cannot be operated non-visually');
    }

    public function test_the_reduced_motion_and_data_saving_rules_are_declared_in_css(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-site.css'));

        $this->assertStringContainsString('prefers-reduced-motion', $css,
            'reduced motion must be honoured in CSS as well as in the video script');
        $this->assertStringContainsString('.piie-hero::after', $css,
            'the hero needs its readability overlay in CSS, not as an inline style');
    }

    // ═══ 3. STRUCTURE, ACCESSIBILITY, RESPONSIVENESS ══════════════════════════

    public function test_the_page_has_exactly_one_h1_and_a_sensible_heading_order(): void
    {
        $html = $this->home();

        $this->assertSame(1, substr_count($html, '<h1'),
            'exactly one h1 per page is a hard requirement, not a preference');

        // Every h2 precedes its section, and no section skips a level.
        $this->assertGreaterThanOrEqual(10, substr_count($html, '<h2'),
            'the redesign defines thirteen sections and most of them are headed');
    }

    public function test_all_thirteen_sections_are_present_and_ordered(): void
    {
        $html = $this->home();

        $expected = [
            'data-pii-hero',                       // 01
            'id="levels"',                         // 02
            'piie-why-title',                      // 03
            'piie-programmes-title',               // 04
            'piie-odel-title',                     // 05
            'piie-about-title',                    // 06
            'piie-admissions-title',               // 07
            'piie-support-title',                  // 08
            'piie-leadership-title',               // 09
            'piie-news-title',                     // 10
            'piie-faqs-title',                     // 11
            'piie-cta-title',                      // 12
            'piie-footer',                         // 13
        ];

        $cursor = 0;

        foreach ($expected as $marker) {
            $at = strpos($html, $marker);

            $this->assertNotFalse($at, "the section marker {$marker} must be rendered");
            $this->assertGreaterThan($cursor, $at, "section {$marker} is out of order");
            $cursor = $at;
        }
    }

    public function test_navigation_is_accessible_and_operable_from_a_keyboard(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('aria-label="Primary"', $html);
        $this->assertStringContainsString('aria-label="Mobile"', $html);

        // A skip link, first thing in the document body.
        $this->assertStringContainsString('piie-skip-link', $html);

        // The burger toggles a panel with aria-expanded / aria-controls.
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-controls="piie-mobile-nav"', $html);

        // Dropdowns open from a BUTTON, not from :hover. A hover-only menu cannot be
        // operated at all from a keyboard.
        $this->assertMatchesRegularExpression('/<button[^>]*aria-haspopup="true"/', $html);
    }

    public function test_the_faq_accordion_uses_native_details_and_needs_no_javascript(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('<details class="piie-faq__item">', $html);
        $this->assertStringContainsString('<summary class="piie-faq__q">', $html,
            'a native summary is keyboard-operable and correctly announced with no JS');
    }

    public function test_content_below_the_fold_is_lazy_loaded_and_hero_media_is_not(): void
    {
        $html = $this->home();

        // The LCP media must NOT be lazy: lazy-loading it is a classic way to
        // make the largest contentful paint worse, not better.
        $this->assertDoesNotMatchRegularExpression(
            '/<img[^>]*class="piie-hero__poster"[^>]*loading="lazy"/',
            $html
        );

        $this->assertGreaterThan(
            5,
            substr_count($html, 'loading="lazy"'),
            'images below the fold must be lazy loaded'
        );
    }

    public function test_every_image_carries_alt_text(): void
    {
        $html = $this->home();

        preg_match_all('/<img\b[^>]*>/i', $html, $m);

        $this->assertGreaterThan(5, count($m[0]), 'the redesign is photography-led; images must be present');

        foreach ($m[0] as $tag) {
            $this->assertMatchesRegularExpression(
                '/\balt="[^"]*"/',
                $tag,
                "an image without alt text is invisible to a screen reader: {$tag}"
            );
        }
    }

    public function test_no_fixed_pixel_width_can_force_horizontal_overflow(): void
    {
        // CSS comments are stripped first. They are documentation, not rules, and a
        // test that matches inside a comment is a test that breaks when someone
        // accurately documents WHY a property is absent - which is exactly what
        // happened to the `overflow-x` assertion below.
        $css = (string) file_get_contents(public_path('css/piie-site.css'));
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        // `overflow-x: hidden` on the site root is the concealment the brief
        // forbids; it hides a layout fault instead of fixing it.
        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-site\s*\{[^}]*overflow-x\s*:\s*hidden/i',
            $rules,
            'overflow-x:hidden conceals overflow rather than preventing it'
        );

        // A max-width in px is fine (that is the content measure). A fixed `width`
        // on a section or card is what pushes a 375px viewport sideways.
        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-(section|hero|card|grid|split)\s*\{[^}]*\bwidth\s*:\s*\d+px/i',
            $rules,
            'a fixed width on a layout block can force horizontal scrolling'
        );

        // Every grid must be able to collapse to one column.
        $this->assertStringContainsString('minmax(0, 1fr)', $rules,
            'grid columns without minmax(0,1fr) refuse to shrink below their content');

        // The 1280px measure the brief asks for, expressed as a max-width.
        $this->assertStringContainsString('--piie-max: 1280px', $rules);
    }

    public function test_overflow_hiding_is_never_used_to_conceal_a_layout_fault(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-site.css'));
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        /**
         * ZERO `overflow-x` on the page. This is the concealment the brief forbids:
         * a document that scrolls sideways is a layout fault, and hiding the scrollbar
         * hides the fault along with the evidence.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/overflow-x\s*:\s*(hidden|clip|auto|scroll)/i',
            $rules,
            'the redesign must never set overflow-x on the document'
        );

        /**
         * `overflow: hidden` IS allowed, but only for genuine containment of
         * decorative or replaced content inside a box it belongs in:
         *
         *   - cropping a photograph to a fixed aspect-ratio frame;
         *   - clipping an image to its card's rounded corners;
         *   - the `.visually-hidden-piie` utility, which MUST clip or the label it
         *     hides from sight becomes visible to everyone.
         *
         * Enumerated by selector rather than banned wholesale, because a blanket ban
         * would either fail on those three legitimate uses or force one of them to be
         * done badly. What it prevents is a NEW rule quietly hiding page content.
         */
        $allowed = [
            '.piie-hero' => 'clips the absolute video/poster layer to the banner box',
            '.piie-card' => 'clips a card image to the card corners',
            '.piie-card__media' => 'crops the photograph to the fixed 4:3 frame',
            '.piie-split__media' => 'clips a split-section image to its corners',
            '.piie-faq__item' => 'clips accordion content to the item corners',
            '.visually-hidden-piie' => 'the screen-reader-only utility; clipping is required',
        ];

        // The pattern matches any class whose name CONTAINS "piie", so it catches
        // `.visually-hidden-piie` as well as the `.piie-*` selectors. Two details
        // matter and both were got wrong first: the character class must include
        // `-` (otherwise `visually-hidden-piie` cannot be reached through its
        // hyphen), and the trailing quantifier must be `*` (the name ENDS in "piie",
        // so `+` would demand a further character that is not there).
        preg_match_all(
            '/\.[a-z0-9_-]*piie[a-z0-9_-]*\s*\{[^}]*overflow\s*:\s*hidden/i',
            $rules,
            $found
        );

        foreach ($found[0] as $rule) {
            preg_match('/(\.[a-z0-9_-]*piie[a-z0-9_-]*)/i', $rule, $selector);

            $name = $selector[1] ?? '(unknown)';

            $this->assertArrayHasKey($name, $allowed,
                "{$name} uses overflow:hidden. If that is legitimate containment, list it "
                .'in the allow-list with its reason; otherwise it is concealing a layout fault.');
        }

        // Exactly the documented set, so a rule cannot be quietly removed either.
        $this->assertCount(count($allowed), $found[0],
            'the set of overflow:hidden rules changed; update the allow-list and its reasons');
    }

    public function test_the_breakpoints_the_brief_names_are_all_covered(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-site.css'));

        foreach ([320, 375, 430, 768, 1024, 1366, 1920] as $width) {
            // 320 and 430 are covered by the 480 and 576 breakpoints respectively,
            // which is the correct behaviour: a 320px phone must get the narrowest
            // layout without a bespoke rule at its exact width.
            $covered = $width <= 320 ? str_contains($css, 'max-width: 575.98px')
                : ($width <= 480 ? str_contains($css, 'min-width: 480px')
                : ($width <= 576 ? str_contains($css, 'min-width: 576px')
                : (str_contains($css, 'min-width: '.$width) || str_contains($css, 'max-width: '.$width.'.98px')
                   || $width === 375 || $width === 430)));

            $this->assertTrue($covered, "the {$width}px width must be covered by the responsive rules");
        }

        $this->assertStringContainsString('min-width: 1366px', $css);
        $this->assertStringContainsString('min-width: 1920px', $css);
    }

    // ═══ 4. LINKS AND SEO ════════════════════════════════════════════════════

    public function test_every_call_to_action_targets_a_route_that_exists(): void
    {
        $html = $this->home();

        // Explore Programmes, Apply Now and Student Portal must all be present.
        $this->assertStringContainsString('>Explore Programmes<', $html);
        $this->assertStringContainsString('>Apply Now<', $html);
        $this->assertStringContainsString('>Student Portal<', $html);

        // Apply Now must point at the REAL application route, not a second system.
        $this->assertStringContainsString(route('apply.form'), $html);
        $this->assertStringContainsString(route('login'), $html);
    }

    public function test_seo_metadata_is_present_and_canonical_is_always_emitted(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('<link rel="canonical"', $html,
            'a canonical is emitted even when Super Admin has not set one');
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);

        // Titles and descriptions must be per-page and non-empty.
        $this->assertMatchesRegularExpression('/<title>.+?<\/title>/s', $html);
    }

    public function test_the_shared_layout_survives_pages_that_pass_no_seo_row(): void
    {
        /**
         * `frontend.index` is shared by FOUR views and only two of them pass
         * $websiteSeo: `apply.blade.php` and `errors/404.blade.php` extend this
         * layout without it.
         *
         * Reading it unguarded took the Apply Now page to a 500 — and that is the
         * class of bug this assertion exists to prevent, because the redesign
         * touched this layout and the page that broke was not one it renders.
         *
         * The 404 page is the proof that works in isolation: it extends the same
         * layout, passes no CMS variables at all, and needs no applicant or intake
         * schema. The real `/apply` page additionally needs those tables and is
         * covered by the HTTP audit in `scripts/audit-public-site.php`, which walks
         * the running site rather than a hand-built schema.
         */
        $this->get('/a-page-that-does-not-exist-xyz')->assertNotFound();

        // The layout must not have acquired a hard dependency on the CMS.
        $layout = (string) file_get_contents(
            resource_path('views/frontend/index.blade.php')
        );

        $this->assertMatchesRegularExpression(
            '/\$websiteSeo\s*\?\?\s*null/',
            $layout,
            'the layout must read $websiteSeo through a ?? null guard, because two of '
            .'its four child views do not pass it'
        );
    }

    public function test_the_existing_public_pages_still_render(): void
    {
        foreach (['about-us', 'academic-programmes', 'admissions', 'contact-us',
                  'research-and-innovation', 'privacy-terms'] as $slug) {
            $this->get(route('website.page', $slug))
                ->assertOk()
                ->assertSee('<title>', false);
        }
    }

    // ═══ 5. THE INNER-PAGE REDESIGN ═════════════════════════════════════════

    /**
     * ONE header, on every public page.
     *
     * The inner pages used to carry their own `<nav>` - a graduation-cap icon and the
     * institution name in inline styles - alongside the shared one, which is why the
     * homepage and the inner pages read as two different websites.
     */
    public function test_every_public_page_has_exactly_one_shared_header_and_footer(): void
    {
        foreach (['/', 'about-us', 'academic-programmes', 'admissions', 'contact-us'] as $target) {
            $html = $target === '/'
                ? $this->home()
                : $this->get(route('website.page', $target))->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, '<header class="piie-header"'),
                "{$target} must render exactly one header"
            );

            $this->assertSame(
                1,
                substr_count($html, 'aria-label="Primary"'),
                "{$target} must have exactly one primary navigation"
            );

            $this->assertGreaterThanOrEqual(
                1,
                substr_count($html, 'class="piie-footer"'),
                "{$target} must render the shared footer"
            );

            // The competing header's icon is gone from the whole document.
            $this->assertStringNotContainsString('graduation-cap', $html,
                "{$target} must not carry the old CMS header icon");
        }
    }

    public function test_the_header_shows_the_logo_without_repeating_the_name_or_the_motto(): void
    {
        $html = $this->home();

        $start = strpos($html, '<header class="piie-header"');
        $end = strpos($html, '</header>', $start);
        $header = substr($html, $start, $end - $start);

        $this->assertStringContainsString('piie-brand__mark', $header,
            'the official logo must be in the header');

        // The lockup already carries the name; repeating it beside the logo is the
        // duplication the review rejected.
        $this->assertStringNotContainsString('piie-brand__name', $header);
        $this->assertStringNotContainsString('piie-brand__motto', $header);

        // The name is still reachable for assistive technology, which is different
        // from displaying it.
        $this->assertStringContainsString('alt="Prime International Institute of Excellence (PIIE)"', $header);

        // ...and the motto is NOT gone from the site. It moved to the About hero,
        // where there is room for it. Asserted against that page because this
        // fixture overrides the `motto` setting, so the literal default is absent.
        $aboutHtml = $this->get(route('website.page', 'about-us'))->assertOk()->getContent();
        $this->assertStringContainsString('CMS MOTTO VALUE', $aboutHtml,
            'the motto must still be rendered, on the About hero');
    }

    public function test_careers_appears_in_the_navigation_only_when_the_page_is_published(): void
    {
        // Published in this fixture? Not by default, so the link must be absent
        // rather than pointing at a 404.
        $this->assertStringNotContainsString('>Careers<', $this->home());

        DB::table('website_pages')->insert([
            'page_key' => 'careers', 'title' => 'Careers', 'nav_title' => 'Careers',
            'slug' => 'careers', 'status' => 1, 'display_order' => 7, 'sort_order' => 7,
        ]);

        $html = $this->home();
        $this->assertStringContainsString('>Careers<', $html,
            'a published Careers page must appear in the navigation');

        $this->get(route('website.page', 'careers'))->assertOk();

        // Unpublishing it must remove the link again, so it can never 404.
        DB::table('website_pages')->where('slug', 'careers')->update(['status' => 0]);
        $this->assertStringNotContainsString('>Careers<', $this->home());
    }

    public function test_careers_shows_a_professional_empty_state_rather_than_invented_positions(): void
    {
        DB::table('website_pages')->insert([
            'page_key' => 'careers', 'title' => 'Careers', 'nav_title' => 'Careers',
            'slug' => 'careers', 'status' => 1, 'display_order' => 7, 'sort_order' => 7,
        ]);
        DB::table('website_sections')->insert([
            'page_key' => 'careers', 'section_key' => 'careers_vacancies',
            'title' => 'Current vacancies', 'content' => '', 'status' => 1, 'sort_order' => 42,
        ]);

        $html = $this->get(route('website.page', 'careers'))->assertOk()->getContent();

        $this->assertStringContainsString('vacancies-empty', $html,
            'with no vacancies published, an honest empty state must be shown');
        $this->assertStringNotContainsString('vacancies-list', $html);

        // A vacancy published as a CMS item then appears - Super Admin-manageable.
        DB::table('website_items')->insert([
            'section_key' => 'careers_vacancies', 'item_type' => 'vacancy',
            'title' => 'LECTURER IN BUSINESS ADMINISTRATION', 'status' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = $this->get(route('website.page', 'careers'))->assertOk()->getContent();
        $this->assertStringContainsString('LECTURER IN BUSINESS ADMINISTRATION', $after);
        $this->assertStringNotContainsString('vacancies-empty', $after);
    }

    public function test_the_catalogue_is_a_four_column_grid_with_search_and_filters(): void
    {
        // Enough programmes to need a second page, so the pagination control is
        // actually rendered rather than short-circuited away by a single page.
        for ($i = 1; $i <= 14; $i++) {
            DB::table('website_items')->insert([
                'section_key' => 'programme_catalog_graduate_school', 'item_type' => 'programme',
                'title' => "Filler Programme {$i}", 'subtitle' => "Level {$i}",
                'status' => 1, 'sort_order' => 100 + $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $html = $this->get(route('website.page', 'academic-programmes'))->assertOk()->getContent();

        // FOUR across on a desktop, two on a tablet, one on a phone.
        //
        // This was three across. Decision 1 changed it so the catalogue matches the
        // homepage block exactly: the same programme was four-across on one page and
        // three-across on the other, and the site contradicted itself. The assertion
        // is on the whole media block rather than a bare `repeat(4, ...)`, because
        // the breakpoint pairing is the actual contract — a `repeat(4, ...)` that
        // appeared at the wrong width would satisfy a naive check and still be wrong.
        $css = (string) file_get_contents(public_path('css/piie-blocks.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 992px\)[\s\S]*?piie-catalogue__grid\s*\{[^}]*repeat\(4,\s*minmax\(0,\s*1fr\)\)/',
            $css,
            'the catalogue must be four columns on a desktop'
        );

        // 768px, not the old 576px: the tablet step must match the shared grid's,
        // or the catalogue and the homepage break at different widths.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)[\s\S]*?piie-catalogue__grid\s*\{[^}]*repeat\(2,\s*minmax\(0,\s*1fr\)\)/',
            $css,
            'the catalogue must be two columns on a tablet, at the shared grid breakpoint'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/@media \(min-width: 576px\)\s*\{[^}]*piie-catalogue__grid\s*\{[^}]*repeat\(2/',
            $css,
            'the 576px block must not set the catalogue to two columns'
        );

        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $css,
            'the catalogue must collapse to one column on a phone');

        // Search and both filters, as a GET form so it works without JavaScript.
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('name="level"', $html);
        $this->assertStringContainsString('name="faculty"', $html);
        $this->assertStringContainsString('role="search"', $html);

        // Real programme records, and a pagination control because there are many.
        $this->assertStringContainsString('PUBLISHED PROGRAMME TWO', $html);
        $this->assertStringNotContainsString('SECRET UNPUBLISHED PROGRAMME', $html);
        $this->assertStringContainsString('piie-pagination', $html);
    }

    public function test_a_programme_without_a_photo_gets_a_designed_fallback_not_an_empty_frame(): void
    {
        $html = $this->get(route('website.page', 'academic-programmes'))->assertOk()->getContent();

        // The fixture sets no programme images, so every card must render the
        // designed category fallback rather than an empty 4:3 rectangle.
        $this->assertStringContainsString('piie-card__media--fallback', $html);
        $this->assertStringContainsString('piie-card__fallback-label', $html);

        $this->assertDoesNotMatchRegularExpression(
            '/piie-card__media">\s*<\/div>/',
            $html,
            'an empty image frame must never be rendered where a photograph is absent'
        );
    }

    public function test_the_orange_content_section_badges_are_gone_from_the_public_site(): void
    {
        foreach (['/', 'about-us', 'academic-programmes', 'admissions', 'contact-us',
                  'research-and-innovation'] as $target) {
            $html = $target === '/'
                ? $this->home()
                : $this->get(route('website.page', $target))->assertOk()->getContent();

            $this->assertStringNotContainsString('Content Section', $html,
                "{$target} must not carry the generic CMS section badge");
        }

        // The component that produced them is no longer on the rendering path.
        //
        // Blade comments are stripped before this check. They are documentation, not
        // markup, and an assertion that reads inside a comment fails the moment
        // somebody accurately records WHY the badge was removed - which is what
        // happened here, twice, on this file and on the stylesheet.
        $generic = (string) file_get_contents(
            resource_path('views/frontend/components/generic_section.blade.php')
        );
        $genericMarkup = preg_replace('/\{\{--.*?--\}\}/s', '', $generic);

        $this->assertStringNotContainsString('Content Section', $genericMarkup,
            'the orange badge must be removed from the component itself, not only bypassed');
        $this->assertStringNotContainsString('section-badge', $genericMarkup,
            'the badge element itself must be gone, not only its label');
    }

    public function test_admissions_uses_a_timeline_and_an_entry_requirements_grid(): void
    {
        $html = $this->get(route('website.page', 'admissions'))->assertOk()->getContent();

        // A real timeline, not seven stacked text boxes.
        $this->assertStringContainsString('piie-timeline', $html);
        $this->assertStringContainsString('Select your programme', $html);
        $this->assertStringContainsString('Complete your registration', $html);

        // Entry requirements in a responsive grid.
        $this->assertStringContainsString('piie-grid piie-grid--3', $html);
        $this->assertStringContainsString("Entry Requirements", $html);

        // Apply Now points at the existing application route.
        $this->assertStringContainsString(route('apply.form'), $html);
    }

    public function test_research_shows_partnerships_in_a_grid_without_fabricated_logos(): void
    {
        DB::table('website_items')->insert([
            'section_key' => 'partnerships_affiliations', 'item_type' => 'partner',
            'title' => 'Test Partner Institution', 'status' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get(route('website.page', 'research-and-innovation'))->assertOk()->getContent();

        $this->assertStringContainsString('Test Partner Institution', $html);
        $this->assertStringContainsString('piie-grid piie-grid--3', $html,
            'partnerships must be a grid, not a single column');

        // No partner logo may be invented for a record that has no image.
        $this->assertStringNotContainsString('partner-logo', $html);
    }

    public function test_contact_shows_only_verified_channels_and_says_when_the_rest_are_unpublished(): void
    {
        $html = $this->get(route('website.page', 'contact-us'))->assertOk()->getContent();

        // The one contact detail the CMS actually holds.
        $this->assertStringContainsString('CMS CONTACT ADDRESS', $html);

        // Nothing else is published, so nothing else may appear. A guessed telephone
        // number or a made-up inbox is a fabricated institutional claim, and a mailto
        // or tel: link to one would be worse than omitting it.
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('tel:', $html);

        // And the omission is stated, not hidden: without this the page looks finished
        // while offering a visitor no way to make contact.
        //
        // The markers moved from a single `contact-channels-pending` block to one per
        // card (`card-phone-pending`, `card-email-pending`) when the page was rebuilt
        // as four channel cards. The assertion follows the markup; the intent — that
        // an unconfigured channel says so — is unchanged. `PublicContactExperienceTest`
        // covers the same property in more detail.
        $this->assertStringContainsString('card-phone-pending', $html);
        $this->assertStringContainsString('card-email-pending', $html);

        // No map: there are no verified coordinates in the CMS, so an embedded map
        // would be an invented location claim plus a third-party request per page.
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertDoesNotMatchRegularExpression('/(google|openstreet)\.?[a-z]*\.?com\/maps/i', $html);

        // The existing, validated submission mechanism is offered instead.
        $this->assertStringContainsString(route('apply.form'), $html);
    }

    public function test_publishing_a_contact_channel_in_the_cms_makes_it_appear(): void
    {
        DB::table('website_items')->insert([
            'section_key' => 'contact_page', 'item_type' => 'email',
            'title' => 'Admissions enquiries', 'link' => 'admissions@piie.test',
            'status' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get(route('website.page', 'contact-us'))->assertOk()->getContent();

        $this->assertStringContainsString('mailto:admissions@piie.test', $html,
            'a channel published by Super Admin must appear without a code change');

        // With a telephone number published too, the pending state is no longer shown.
        DB::table('website_items')->insert([
            'section_key' => 'contact_page', 'item_type' => 'phone',
            'title' => 'Main line', 'link' => '+256 700 000 000',
            'status' => 1, 'sort_order' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = $this->get(route('website.page', 'contact-us'))->assertOk()->getContent();

        $this->assertStringContainsString('tel:+256700000000', $after);
        $this->assertStringNotContainsString('contact-channels-pending', $after,
            'the pending state must disappear once the channels are published');
    }

    public function test_the_navigation_does_not_present_top_level_pages_as_dropdown_children(): void
    {
        // This fixture publishes home, about-us, academic-programmes, admissions,
        // research-and-innovation, contact-us and privacy-terms.
        //
        // Six of those are top-level destinations and `privacy-terms` is a genuine
        // sub-page, so a dropdown IS expected here - containing Privacy Policy and
        // nothing else. The rule being asserted is the invariant, not the presence or
        // absence of the dropdown, because the live CMS has no sub-pages at all and
        // renders a flat bar.
        $html = $this->home();

        if (preg_match('/<ul class="piie-nav__dropdown">(.*?)<\/ul>/s', $html, $m)) {
            $children = $m[1];

            // "About PIIE" is deliberately NOT in this list. Its dropdown trigger is a
            // <button>, not a link, so without the self-link at the top of the menu
            // there would be no way to reach the About page from a desktop browser.
            foreach (['Admissions', 'Contact Us', 'Research', 'Careers',
                      'Programmes', 'Home'] as $topLevel) {
                $this->assertStringNotContainsString(
                    '>'.$topLevel.'<',
                    $children,
                    "{$topLevel} is a top-level destination and must not also appear ".
                    'as a child of the About menu, which misrepresents the hierarchy'
                );
            }

            // The one genuine sub-page is present.
            $this->assertStringContainsString('Privacy Policy', $children);
        }

        // No link in the navigation may point at nothing.
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*\bhref=""\b/', $html);

        // About is always reachable.
        $this->assertStringContainsString('>About PIIE<', $html);

        // And the menu is data-driven rather than merely absent: publishing a genuine
        // sub-page produces a dropdown containing that page and no top-level page.
        DB::table('website_pages')->insert([
            'page_key' => 'quality_assurance', 'title' => 'Quality Assurance',
            'nav_title' => 'Quality Assurance', 'slug' => 'quality-assurance',
            'status' => 1, 'display_order' => 8, 'sort_order' => 8,
        ]);

        $after = $this->home();

        $this->assertStringContainsString('data-pii-dropdown', $after,
            'a real sub-page must produce a dropdown');

        $this->assertMatchesRegularExpression(
            '/<ul class="piie-nav__dropdown">(?:(?!Admissions|Contact Us|Research|Careers).)*?Quality Assurance/s',
            $after,
            'the dropdown must contain only genuine children'
        );
    }

    public function test_the_shared_blocks_are_reused_rather_than_duplicated(): void
    {
        foreach ([
            'page_hero', 'section_heading', 'split', 'card', 'breadcrumbs', 'cta',
        ] as $block) {
            $path = resource_path('views/frontend/partials/blocks/'.$block.'.blade.php');

            $this->assertFileExists($path, "the {$block} block must exist and be shared");
        }

        // The inner page includes the shared header and footer rather than
        // carrying its own.
        $inner = (string) file_get_contents(resource_path('views/frontend/website_page.blade.php'));
        $this->assertStringContainsString("include('frontend.partials.site_header')", $inner);
        $this->assertStringContainsString("include('frontend.partials.site_footer')", $inner);

        // The old bespoke header markup is gone. Comments are stripped first, because
        // this file explains what it replaced and that explanation names the very
        // markup the assertion is looking for.
        $innerMarkup = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);

        $this->assertStringNotContainsString('<nav>', $innerMarkup,
            'the competing inline <nav> header must be removed, not merely restyled');
        $this->assertStringNotContainsString('graduation-cap', $innerMarkup,
            'the old CMS header icon must be gone from the inner page');
    }
}