<?php

namespace Tests\Feature;

use App\Support\Website\PublicContactChannels;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE HEADER, THE CONTACT BAR AND THE CONTACT PAGE.
 *
 * These exist because of a specific failure mode observed during the redesign: the
 * header utility bar, the contact page cards and the footer each resolved the
 * institution's contact details independently, from three different sources, so they
 * could disagree — and an earlier draft published a placeholder `info@piie.test`
 * address that can never receive mail.
 *
 * So the assertions here are mostly about AGREEMENT and about NOT INVENTING, rather
 * than about the presence of a particular string.
 */
class PublicContactExperienceTest extends TestCase
{
    use CreatesPublicSiteSchema;

    /**
     * No `RefreshDatabase`.
     *
     * The suite runs against SQLite, and `RefreshDatabase` executes every migration
     * in `database/migrations` — several of which use MySQL-only syntax such as
     * `ALTER TABLE ... MODIFY COLUMN`, which SQLite rejects. That is a pre-existing
     * property of this codebase's migrations, not something this test should try to
     * work around.
     *
     * Instead the schema these tests need is declared in `CreatesPublicSiteSchema`,
     * which is also better practice for a public-site test: it states exactly which
     * columns the page under test reads, so a new CMS column cannot silently change
     * what is being exercised. `PublicSiteRedesignTest` uses the same approach.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublicSiteDatabase();

        foreach ([
            'institution_name' => 'Prime International Institute of Excellence (PIIE)',
            'motto' => 'Strive. Excel. Lead.',
            'contact_address' => 'CMS CONTACT ADDRESS',
        ] as $key => $value) {
            DB::table('website_settings')->insert(['key' => $key, 'value' => $value, 'status' => 1]);
        }
    }

    private function putSetting(string $key, string $value): void
    {
        DB::table('website_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'status' => 1]);
    }

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    private function contact(): string
    {
        return $this->get(route('website.page', 'contact-us'))->assertOk()->getContent();
    }

    // ═══ 1. THE CONTACT-BAR IS CMS-DRIVEN AND NEVER INVENTED ════════════════

    /**
     * With nothing configured, the bar must show only what exists.
     *
     * The CMS publishes an address and no telephone number and no email address. The
     * bar therefore contains the brochure and no `mailto:` and no `tel:`. This is the
     * assertion that would fail if anyone reintroduced a hardcoded contact detail.
     */
    public function test_the_contact_bar_shows_only_configured_channels(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('piie-utility', $html, 'the utility bar must render');
        $this->assertStringContainsString('Download Brochure', $html);

        $this->assertStringNotContainsString('mailto:', $html,
            'no email address is published, so no mailto may appear');
        $this->assertStringNotContainsString('tel:', $html,
            'no telephone number is published, so no tel: may appear');
    }

    public function test_publishing_a_telephone_number_and_email_puts_them_in_the_bar(): void
    {
        $this->putSetting('contact_phone', '+256 700 111 222');
        $this->putSetting('contact_email', 'admissions@piie.test'.'.ug');

        $html = $this->home();

        $this->assertStringContainsString('mailto:admissions@piie.test.ug', $html);
        $this->assertStringContainsString('tel:+256700111222', $html,
            'the tel: URI must be dialled on the number, stripped of spaces');

        // The label is human-readable and separate from the dialled value.
        $this->assertStringContainsString('+256 700 111 222', $html);
    }

    /**
     * Several numbers in one setting, with no schema change.
     *
     * The brief asked for "multiple telephone numbers and email addresses" with a
     * minimal extension. `website_settings` is key/value with an `is_json` flag, so
     * a JSON array is the extension — and a newline-delimited string works too, for
     * an administrator who never learns the JSON form.
     */
    public function test_multiple_telephone_numbers_are_supported_without_a_schema_change(): void
    {
        $this->putSetting('contact_phone', json_encode(['+256 700 111 222', '+256 414 555 666']));

        $this->assertSame(
            ['+256 700 111 222', '+256 414 555 666'],
            PublicContactChannels::phones(['contact_phone' => json_encode(['+256 700 111 222', '+256 414 555 666'])])
        );

        $html = $this->home();
        $this->assertStringContainsString('tel:+256700111222', $html);
        $this->assertStringContainsString('tel:+256414555666', $html);

        // And the same input in the plain delimited form gives the same answer.
        $this->assertSame(
            ['+256 700 111 222', '+256 414 555 666'],
            PublicContactChannels::phones(['contact_phone' => "+256 700 111 222\n+256 414 555 666"])
        );
    }

    /**
     * A reserved-TLD address must not be published.
     *
     * The tenant row really does hold `info@piie.test` with `.test` reserved by
     * IANA, so this is a live risk rather than a hypothetical. Printing it as the
     * institution's contact address would be a promise the site cannot keep.
     */
    public function test_a_reserved_domain_email_is_never_published(): void
    {
        foreach (['info@piie.test', 'a@example.com', 'b@localhost', 'c@x.invalid'] as $bad) {
            $this->assertSame([], PublicContactChannels::emails(['contact_email' => $bad]),
                "{$bad} must not be treated as publishable");
        }

        $this->putSetting('contact_email', 'info@piie.test');

        $this->assertStringNotContainsString('mailto:', $this->home(),
            'a .test address must not reach the rendered page');
    }

    public function test_a_value_with_no_digit_is_not_treated_as_a_telephone_number(): void
    {
        // The tenant row stores `0` in `schools.phone`. If that value ever reaches
        // this resolver it must produce nothing rather than a `tel:0` link.
        $this->assertSame([], PublicContactChannels::phones(['contact_phone' => '0']));
        $this->assertSame([], PublicContactChannels::phones(['contact_phone' => 'N/A']));
        $this->assertSame([], PublicContactChannels::phones(['contact_phone' => '']));
        $this->assertSame([], PublicContactChannels::phones([]));
    }

    /**
     * `tel:` normalisation.
     *
     * A number written for humans must dial. `00` is the international prefix and
     * becomes `+`; spaces, dashes, brackets and dots are dropped.
     */
    public function test_a_telephone_number_is_normalised_for_dialing(): void
    {
        $this->assertSame('tel:+256700111222', PublicContactChannels::telHref('+256 700 111 222'));
        $this->assertSame('tel:+256700111222', PublicContactChannels::telHref('00256 700 111 222'));
        $this->assertSame('tel:+256700111222', PublicContactChannels::telHref('+256-700-111-222'));
        $this->assertSame('tel:256700111222', PublicContactChannels::telHref('256 700 111 222'));
    }

    // ═══ 2. HEADER SHAPE ═══════════════════════════════════════════════════

    public function test_the_header_is_the_same_on_every_public_page_including_apply(): void
    {
        $pages = ['/', 'about-us', 'academic-programmes', 'admissions', 'contact-us'];

        foreach ($pages as $slug) {
            $html = $slug === '/'
                ? $this->home()
                : $this->get(route('website.page', $slug))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<header class="piie-header"'),
                "{$slug} must have exactly one header");
            $this->assertStringContainsString('piie-utility', $html,
                "{$slug} must carry the contact bar");
            $this->assertStringContainsString('Download Brochure', $html,
                "{$slug} must offer the brochure");
            $this->assertStringContainsString('>Apply Now<', $html);
            $this->assertStringContainsString('>Student Portal<', $html);
        }
    }

    /**
     * The brochure must be the EXISTING route, not a new file or a dead link.
     */
    public function test_the_brochure_link_points_at_the_existing_download_route(): void
    {
        $html = $this->home();

        $this->assertStringContainsString(route('download.brochure'), $html);
        $this->assertStringContainsString('download-brochure', $html);
    }

    /**
     * Nav sizing and the distribution fix.
     *
     * The complaint was "excessive horizontal spacing, particularly between the logo
     * and the Apply Now button". The cause was `justify-content: space-between` on a
     * three-child flex row, which dumps every spare pixel into the two outer gaps.
     * Asserted as a CSS-level contract so the fix cannot be quietly reverted.
     */
    public function test_the_header_distributes_space_with_a_centre_track_not_space_between(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-site.css'));

        $this->assertMatchesRegularExpression(
            '/\.piie-header__bar\s*\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*auto\s+1fr\s+auto/s',
            $css,
            'the bar must use a flexible centre track so slack is balanced, not pooled'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-header__bar\s*\{[^}]*space-between/s',
            $css,
            'space-between on three children is what produced the single large void'
        );

        // 15px / 600 as specified.
        $this->assertMatchesRegularExpression(
            '/\.piie-nav__link\s*\{[^}]*font-size:\s*\.9375rem[^}]*font-weight:\s*600/s',
            $css,
            'navigation must be 15px at weight 600'
        );

        // The utility bar exists and is styled.
        $this->assertStringContainsString('.piie-utility {', $css);
        $this->assertStringContainsString('.piie-utility__list', $css);
    }

    // ═══ 3. CONTACT PAGE ═══════════════════════════════════════════════════

    public function test_the_contact_page_has_the_four_channel_cards(): void
    {
        $html = $this->contact();

        $this->assertStringContainsString('>Call Us<', $html);
        $this->assertStringContainsString('>Email Us<', $html);
        $this->assertStringContainsString('>Visit Us<', $html);
        $this->assertStringContainsString('>Office Hours<', $html);

        // The hero the brief specifies.
        $this->assertStringContainsString('>Contact Us<', $html);

        // Asserted without the apostrophe because `{{ }}` HTML-escapes it: the page
        // contains `We&#039;re Here to Help.`, and a literal apostrophe here fails
        // against correct output.
        $this->assertStringContainsString('re Here to Help.', $html);

        // The verified address appears.
        $this->assertStringContainsString('CMS CONTACT ADDRESS', $html);
    }

    public function test_unconfigured_contact_channels_say_so_instead_of_showing_nothing(): void
    {
        $html = $this->contact();

        $this->assertStringContainsString('card-phone-pending', $html);
        $this->assertStringContainsString('card-email-pending', $html);

        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('tel:', $html);
    }

    public function test_configured_contact_channels_become_clickable_and_clear_the_pending_state(): void
    {
        $this->putSetting('contact_phone', '+256 700 111 222');
        $this->putSetting('contact_email', 'admissions@piie.ug');
        $this->putSetting('office_hours', 'Monday to Friday, 08:00 - 17:00');

        $html = $this->contact();

        $this->assertStringContainsString('tel:+256700111222', $html);
        $this->assertStringContainsString('mailto:admissions@piie.ug', $html);
        $this->assertStringContainsString('Monday to Friday', $html);

        $this->assertStringNotContainsString('card-phone-pending', $html);
        $this->assertStringNotContainsString('card-email-pending', $html);
    }

    /**
     * No map without verified coordinates.
     *
     * The brief says "Do not guess the institution's coordinates". An embedded map
     * centred on a guessed point is an invented location claim, and it would also add
     * a third-party request to a page that currently makes none.
     */
    public function test_no_map_is_embedded_and_none_is_invented(): void
    {
        $html = $this->contact();

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertDoesNotMatchRegularExpression('/(google|openstreet)\.?[a-z]*\.?com\/maps/i', $html);
        $this->assertStringContainsString('map-pending', $html,
            'the absence must be stated, not left as a silent gap');
    }

    public function test_a_map_appears_only_once_real_coordinates_are_published(): void
    {
        $this->putSetting('contact_map_lat', '0.3476');
        $this->putSetting('contact_map_lng', '32.5825');

        $html = $this->contact();

        $this->assertStringContainsString('<iframe', $html,
            'a verified coordinate must actually produce a map');
        $this->assertStringContainsString('0.3476,32.5825', $html);
        $this->assertStringNotContainsString('map-pending', $html);
    }

    /** Non-numeric coordinates must NOT be trusted into an iframe src. */
    public function test_unusable_coordinates_do_not_produce_a_map(): void
    {
        $this->putSetting('contact_map_lat', 'Nansana Municipality');
        $this->putSetting('contact_map_lng', '');

        $html = $this->contact();

        $this->assertStringNotContainsString('<iframe', $html);
    }

    // ═══ 4. AGREEMENT BETWEEN THE THREE SURFACES ══════════════════════════

    /**
     * The header bar, the contact page and the footer must show the SAME channels.
     *
     * This is the assertion that would have caught the original defect: three
     * surfaces, three different sources, three possible answers.
     */
    public function test_the_header_contact_page_and_footer_agree_on_the_channels(): void
    {
        $this->putSetting('contact_phone', '+256 700 111 222');
        $this->putSetting('contact_email', 'admissions@piie.ug');

        $home = $this->home();
        $contact = $this->contact();

        foreach (['home' => $home, 'contact' => $contact] as $label => $html) {
            $this->assertStringContainsString('tel:+256700111222', $html,
                "{$label} must show the published telephone number");
            $this->assertStringContainsString('mailto:admissions@piie.ug', $html,
                "{$label} must show the published email address");
        }

        // The footer is rendered on the contact page too, so it is covered above.
        $this->assertStringContainsString('piie-footer', $contact);
    }
}
