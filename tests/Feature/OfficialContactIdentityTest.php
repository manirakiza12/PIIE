<?php

namespace Tests\Feature;

use App\Support\Website\PublicContactChannels;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE OFFICIAL CONTACT DETAILS, THE HEADER BAR AND THE FOOTER IDENTITY.
 *
 * The institution supplied these directly:
 *
 *   OFFICIAL LINE : 0200920918
 *   TELEPHONE     : 0788099193
 *   EMAIL         : primeinternationalinstitute@gmail.com
 *   WEBSITE       : www.primeinternationalinstitute.ac.ug
 *
 * Two failure modes are guarded against here, and both were live before this round:
 *
 *  1. The details appearing on ONE surface and not the others. The header, the
 *     contact page and the footer previously each resolved contact data
 *     independently, from three different sources.
 *  2. A surface showing template or third-party branding. The footer pointed at
 *     `white logo with text.png`, which is a blank white image, and the uploads folder
 *     also contains another institution's crest.
 */
class OfficialContactIdentityTest extends TestCase
{
    use CreatesPublicSiteSchema;

    private const LINE = '0200920918';
    private const MOBILE = '0788099193';
    private const EMAIL = 'primeinternationalinstitute@gmail.com';
    private const HOST = 'www.primeinternationalinstitute.ac.ug';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublicSiteDatabase();

        foreach ([
            'institution_name' => 'Prime International Institute of Excellence (PIIE)',
            'motto' => 'Strive. Excel. Lead.',
            'contact_address' => 'Nansana Municipality, Wakiso District, Uganda',
            'contact_line' => self::LINE,
            'contact_phone' => json_encode([self::MOBILE, self::LINE]),
            'contact_email' => self::EMAIL,
            'contact_website' => self::HOST,
        ] as $key => $value) {
            DB::table('website_settings')->insert(['key' => $key, 'value' => $value, 'status' => 1]);
        }
    }

    private function page(string $slug): string
    {
        return $this->get(route('website.page', $slug))->assertOk()->getContent();
    }

    // ═══ 1. THE RESOLVER ══════════════════════════════════════════════════

    public function test_the_official_details_resolve_with_clickable_links(): void
    {
        $settings = [
            'contact_line' => self::LINE,
            'contact_phone' => json_encode([self::MOBILE, self::LINE]),
            'contact_email' => self::EMAIL,
            'contact_website' => self::HOST,
        ];

        $this->assertSame(self::LINE, PublicContactChannels::officialLine($settings));
        $this->assertSame([self::MOBILE], PublicContactChannels::telephoneNumbers($settings),
            'the official line must not also be listed as a telephone');
        $this->assertSame([self::MOBILE, self::LINE], PublicContactChannels::phones($settings));
        $this->assertSame([self::EMAIL], PublicContactChannels::emails($settings));

        // Dialled form.
        $this->assertSame('tel:'.self::MOBILE, PublicContactChannels::telHref(self::MOBILE));
        $this->assertSame('tel:'.self::LINE, PublicContactChannels::telHref(self::LINE));

        // HTTPS, with the scheme supplied by the resolver because the institution
        // recorded a bare hostname.
        $this->assertSame('https://'.self::HOST, PublicContactChannels::website($settings));
    }

    /**
     * A website value must never become a script URL.
     *
     * `website()` is interpolated into an `href`, so a value of
     * `javascript:alert(1)` in the settings table would otherwise be a stored XSS
     * vector editable by anyone with CMS access.
     */
    public function test_a_non_domain_website_value_is_refused(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'not a domain',
            '//evil.test',
            '',
        ] as $bad) {
            $this->assertNull(PublicContactChannels::website(['contact_website' => $bad]),
                "'{$bad}' must not become a link");
        }

        // An explicit scheme the administrator chose is respected, not rewritten.
        $this->assertSame(
            'http://legacy.test',
            PublicContactChannels::website(['contact_website' => 'http://legacy.test'])
        );
    }

    // ═══ 2. THE HEADER UTILITY BAR ════════════════════════════════════════

    public function test_the_upper_header_shows_the_official_details_on_every_public_page(): void
    {
        $pages = ['/', 'about-us', 'academic-programmes', 'admissions', 'contact-us'];

        foreach ($pages as $slug) {
            $html = $slug === '/' ? $this->get('/')->getContent() : $this->page($slug);

            $this->assertStringContainsString('mailto:'.self::EMAIL, $html,
                "{$slug} must show the official email as a mailto link");
            $this->assertStringContainsString('tel:'.self::MOBILE, $html,
                "{$slug} must show the mobile number as a clickable tel link");
            $this->assertStringContainsString('tel:'.self::LINE, $html,
                "{$slug} must show the official line as a clickable tel link");
            $this->assertStringContainsString('https://'.self::HOST, $html,
                "{$slug} must show the website as an absolute HTTPS link");
        }
    }

    /** The brochure stays on the right of the strip. */
    public function test_the_brochure_is_preserved_and_aligned_to_the_right_of_the_upper_header(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('Download Brochure', $html);
        $this->assertStringContainsString(route('download.brochure'), $html);
        $this->assertStringContainsString('piie-utility__item--end', $html,
            'the brochure must be the right-hand item in the strip');
    }

    // ═══ 3. THE CONTACT PAGE ══════════════════════════════════════════════

    public function test_the_contact_page_shows_no_temporary_not_published_messages(): void
    {
        $html = $this->page('contact-us');

        // With the official details published, none of these may appear.
        $this->assertStringNotContainsString('card-phone-pending', $html);
        $this->assertStringNotContainsString('card-email-pending', $html);
        $this->assertStringNotContainsString('has not yet been published', $html);

        // And the four cards carry the real values.
        $this->assertStringContainsString('>Call Us<', $html);
        $this->assertStringContainsString('>Email Us<', $html);
        $this->assertStringContainsString('>Visit Us<', $html);
        $this->assertStringContainsString('>Office Hours<', $html);

        $this->assertStringContainsString('>Official Line<', $html);
        $this->assertStringContainsString(self::LINE, $html);
        $this->assertStringContainsString('>Telephone<', $html);
        $this->assertStringContainsString(self::MOBILE, $html);
        $this->assertStringContainsString('mailto:'.self::EMAIL, $html);
        $this->assertStringContainsString('Nansana Municipality', $html);
    }

    /**
     * Office hours must NOT be invented.
     *
     * The institution supplied no hours, and the brief says explicitly: "If
     * unavailable, do not invent opening and closing times." So the card states the
     * position and prints no times at all.
     */
    public function test_office_hours_are_not_invented(): void
    {
        $html = $this->page('contact-us');

        $this->assertStringContainsString('card-hours-pending', $html,
            'with no hours configured the card must say so');

        // No clock time anywhere on the page.
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,2}[:.]\d{2}\s*(?:am|pm|-|to|–)/i', $html,
            'no opening or closing time may be fabricated');

        // Publishing hours fills the card in, with no code change.
        DB::table('website_settings')->insert([
            'key' => 'office_hours', 'value' => 'Monday to Friday, 08:00 - 17:00', 'status' => 1,
        ]);

        $after = $this->page('contact-us');

        $this->assertStringContainsString('Monday to Friday', $after);
        $this->assertStringNotContainsString('card-hours-pending', $after);
    }

    /** No map, and therefore no invented pin. */
    public function test_no_map_pin_is_invented(): void
    {
        $html = $this->page('contact-us');

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertDoesNotMatchRegularExpression('/(google|openstreet)\.?[a-z]*\.?com\/maps/i', $html);
        $this->assertStringContainsString('map-pending', $html);
    }

    /** The enquiry form and its protections survive all of this. */
    public function test_the_enquiry_form_is_intact_after_the_contact_page_redesign(): void
    {
        $html = $this->page('contact-us');

        $this->assertStringContainsString('data-testid="enquiry-form"', $html);
        $this->assertStringContainsString('name="_token"', $html, 'CSRF token must remain');
        $this->assertStringContainsString(route('website.enquiry.store'), $html);
        $this->assertStringContainsString('name="website"', $html, 'the honeypot must remain');
    }

    // ═══ 4. THE FOOTER ════════════════════════════════════════════════════

    /**
     * The footer must carry the INSTITUTION's identity, not template branding.
     *
     * It used to render `white logo with text.png`, which is a blank white image, so
     * the footer showed no logo at all. And the uploads folder contains
     * `logo-removebg-preview.png` / `Logo1-removebg-preview.png`, which are a
     * DIFFERENT institution's crest ("Trinity Divine Interpreted Theological
     * Institute") — so those must never be rendered either.
     */
    public function test_the_footer_carries_the_real_piiE_identity(): void
    {
        $html = $this->get('/')->getContent();

        // The genuine crest, which is the same file the header uses.
        $this->assertStringContainsString('assets/uploads/logo/logo.png', $html);
        $this->assertStringContainsString('piie-footer__crest', $html);

        // The blank image is gone.
        $this->assertStringNotContainsString('white logo with text', $html,
            'the blank white image must no longer be used as the footer logo');

        // No other institution's crest.
        $this->assertStringNotContainsString('removebg', $html);
        $this->assertStringNotContainsString('Logo1', $html);

        // Name and motto as real, selectable text.
        $this->assertStringContainsString('piie-footer__name', $html);
        $this->assertStringContainsString('Prime International Institute of Excellence (PIIE)', $html);
        $this->assertStringContainsString('Strive. Excel. Lead.', $html);

        // The crest file itself is the verified PIIE asset, not a generated one.
        $crest = public_path('assets/uploads/logo/logo.png');
        $this->assertFileExists($crest);
        $this->assertGreaterThan(20000, filesize($crest),
            'the crest must be the real artwork, not a placeholder');
    }

    /** The official contact details belong in the footer too. */
    public function test_the_footer_shows_the_official_contact_details(): void
    {
        $html = $this->get('/')->getContent();

        $start = strpos($html, 'piie-footer');
        $end = strrpos($html, '</footer>');
        $footer = substr($html, $start, $end - $start);

        $this->assertStringContainsString('tel:'.self::LINE, $footer);
        $this->assertStringContainsString('tel:'.self::MOBILE, $footer);
        $this->assertStringContainsString('mailto:'.self::EMAIL, $footer);
        $this->assertStringContainsString('https://'.self::HOST, $footer);
        $this->assertStringContainsString('Nansana Municipality', $footer);
    }

    /** The useful footer navigation survives the identity change. */
    public function test_the_footer_navigation_and_links_are_preserved(): void
    {
        $html = $this->get('/')->getContent();

        $start = strpos($html, 'piie-footer');
        $end = strrpos($html, '</footer>');
        $footer = substr($html, $start, $end - $start);

        $this->assertStringContainsString('aria-label="Programmes"', $footer);
        $this->assertStringContainsString('piie-footer__legal', $footer);

        // Programme, admissions, contact and brochure links all still present.
        $this->assertStringContainsString(route('website.page', 'academic-programmes'), $footer);
        $this->assertStringContainsString(route('website.page', 'admissions'), $footer);
        $this->assertStringContainsString(route('website.page', 'contact-us'), $footer);
        $this->assertStringContainsString(route('download.brochure'), $footer);

        // A `href="#"` is a link that goes nowhere. All four CMS social rows carry
        // `link = '#'`, so they must be omitted rather than rendered.
        $this->assertStringNotContainsString('href="#"', $footer,
            'the footer must not render dead links');
    }

    // ═══ 5. HEADER TYPOGRAPHY ════════════════════════════════════════════

    /**
     * The main navigation is 15px at weight 600, and the bar distributes space with a
     * centre track rather than `space-between` — which is what produced the single
     * large void between the logo and Apply Now.
     */
    public function test_the_main_navigation_typography_and_distribution(): void
    {
        $css = (string) file_get_contents(public_path('css/piie-site.css'));

        $this->assertMatchesRegularExpression(
            '/\.piie-nav__link\s*\{[^}]*font-size:\s*\.9375rem[^}]*font-weight:\s*600/s',
            $css,
            'navigation must be 15px at weight 600'
        );

        $this->assertMatchesRegularExpression(
            '/\.piie-header__bar\s*\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*auto\s+1fr\s+auto/s',
            $css,
            'the bar must use a flexible centre track'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-header__bar\s*\{[^}]*space-between/s',
            $css,
            'space-between on three children is what caused the excessive gap'
        );

        // The logo, Apply Now and Student Portal survive.
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('piie-brand__mark', $html);
        $this->assertStringContainsString('>Apply Now<', $html);
        $this->assertStringContainsString('>Student Portal<', $html);
    }

    // ═══ 6. NO PLACEHOLDERS ANYWHERE ══════════════════════════════════════

    /**
     * `/apply` must carry the SAME header as every other public page.
     *
     * It includes the shared header partial, but `PublicApplicationController` used to
     * pass only `programmes` and `intakeSessions`. Because the header's data is all
     * `??`-guarded nothing raised an error — the header simply rendered EMPTY: no
     * contact bar, no numbers, and a navigation with no links in it. Invisible to any
     * test that only asserted the `<header>` element existed.
     *
     * This also proves the Apply page's own behaviour is unchanged: the dummy test
     * programme is still filtered out and a real programme is still offered.
     */
    public function test_the_apply_page_shares_the_same_header_and_footer(): void
    {
        $html = $this->get('/apply')->assertOk()->getContent();

        $this->assertStringContainsString('mailto:'.self::EMAIL, $html);
        $this->assertStringContainsString('tel:'.self::MOBILE, $html);
        $this->assertStringContainsString('tel:'.self::LINE, $html);
        $this->assertStringContainsString('https://'.self::HOST, $html);
        $this->assertStringContainsString('Download Brochure', $html);

        // A populated navigation, not an empty one.
        $this->assertGreaterThanOrEqual(
            6,
            substr_count($html, 'class="piie-nav__link"'),
            'the navigation must contain its links on /apply too'
        );

        $this->assertStringContainsString('>Apply Now<', $html);
        $this->assertStringContainsString('>Student Portal<', $html);

        // Shared footer.
        $this->assertStringContainsString('piie-footer__name', $html);
        $this->assertStringContainsString('assets/uploads/logo/logo.png', $html);

        // The Apply page's own behaviour is untouched.
        $this->assertStringNotContainsString('DUMMY', $html,
            'the dummy test programme must stay off the public application catalogue');
    }

    /**
     * The whole point of seeding real details: no placeholder may survive on any
     * public page. This is the assertion that fails if someone re-introduces a
     * hardcoded `info@piie.test` or a `tel:0`.
     */
    public function test_no_public_page_shows_a_placeholder_contact_detail(): void
    {
        foreach (['/', 'about-us', 'academic-programmes', 'admissions', 'contact-us', 'careers'] as $slug) {
            $html = $slug === '/' ? $this->get('/')->getContent() : $this->page($slug);

            foreach (['@piie.test', '@example.com', 'tel:0"', 'tel:+0">'] as $placeholder) {
                $this->assertStringNotContainsString($placeholder, $html,
                    "{$slug} must not show the placeholder {$placeholder}");
            }
        }
    }
}
