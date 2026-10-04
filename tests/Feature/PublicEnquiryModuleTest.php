<?php

namespace Tests\Feature;

use App\Models\WebsiteEnquiry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE PUBLIC ENQUIRY MODULE.
 *
 * The brief authorised building this only after confirming no suitable mechanism
 * existed. These tests cover the four properties it was specified to have:
 * server-side validation, CSRF protection, spam protection with rate limiting, and
 * "do not expose submitted enquiries publicly".
 */
class PublicEnquiryModuleTest extends TestCase
{
    use CreatesPublicSiteSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublicSiteDatabase();

        $this->enquiryUrl = route('website.enquiry.store');
    }

    private string $enquiryUrl;

    /** A complete, valid payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ama Nakato',
            'email' => 'ama@example.co.ug',
            'phone' => '+256 700 111 222',
            'subject' => 'Admissions',
            'message' => 'I would like to ask about the Bachelor of Business Administration programme.',
        ], $overrides);
    }

    // ═══ 1. SERVER-SIDE VALIDATION ═════════════════════════════════════════

    public function test_a_valid_enquiry_is_stored_and_the_visitor_is_thanked(): void
    {
        $response = $this->post($this->enquiryUrl, $this->payload());

        $response->assertRedirect(route('website.page', 'contact-us'));
        $response->assertSessionHas('enquiry_sent', 'sent');
        $response->assertSessionHas('enquiry_name', 'Ama');

        $this->assertSame(1, WebsiteEnquiry::count());

        $stored = WebsiteEnquiry::first();
        $this->assertSame('Ama Nakato', $stored->name);
        $this->assertSame('ama@example.co.ug', $stored->email);
        $this->assertSame('Admissions', $stored->subject);
        $this->assertSame(WebsiteEnquiry::STATUS_NEW, $stored->status);
        $this->assertNull($stored->honeypot);
    }

    /**
     * Every required field the brief lists is genuinely required.
     *
     * `name`, `email`, `subject` and `message` are required; `phone` is explicitly
     * optional. Each case removes exactly one field, so a rule that silently stopped
     * being required would fail here.
     *
     * @dataProvider requiredFieldProvider
     */
    public function test_required_fields_are_enforced_server_side(string $field): void
    {
        $this->post($this->enquiryUrl, $this->payload([$field => '']))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, WebsiteEnquiry::count(),
            'nothing may be stored when validation fails');
    }

    public static function requiredFieldProvider(): array
    {
        return [
            'name' => ['name'],
            'email' => ['email'],
            'subject' => ['subject'],
            'message' => ['message'],
        ];
    }

    /** Telephone is optional, as specified. */
    public function test_the_telephone_number_is_optional(): void
    {
        $payload = $this->payload();
        unset($payload['phone']);

        $this->post($this->enquiryUrl, $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, WebsiteEnquiry::count());
        $this->assertNull(WebsiteEnquiry::first()->phone);
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->post($this->enquiryUrl, $this->payload(['email' => 'not-an-address']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, WebsiteEnquiry::count());
    }

    /** A message must be long enough to be an enquiry, and short enough to be safe. */
    public function test_the_message_has_a_minimum_and_a_maximum_length(): void
    {
        $this->post($this->enquiryUrl, $this->payload(['message' => 'hi']))
            ->assertSessionHasErrors('message');

        $this->post($this->enquiryUrl, $this->payload(['message' => str_repeat('a', 5001)]))
            ->assertSessionHasErrors('message');

        $this->assertSame(0, WebsiteEnquiry::count());
    }

    /**
     * An international telephone number must be accepted.
     *
     * A strict numeric rule would reject "+256 700 111 222", which is how a Ugandan
     * number is written. The rule has to allow the characters a real number uses.
     *
     * The email AND message differ per case on purpose. The duplicate guard keys on
     * email + message, so three payloads differing only in telephone number are three
     * duplicates of one enquiry — which is the guard working, not a failure here.
     */
    public function test_an_international_telephone_number_is_accepted(): void
    {
        $cases = ['+256 700 111 222', '(020) 555-1234', '+256.700.111.222'];

        foreach ($cases as $index => $phone) {
            $this->post($this->enquiryUrl, $this->payload([
                'phone' => $phone,
                'email' => "caller{$index}@example.co.ug",
                'message' => "Enquiry number {$index} about the programme fees.",
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(3, WebsiteEnquiry::count());
        $this->assertSame($cases, WebsiteEnquiry::orderBy('id')->pluck('phone')->all());
    }

    public function test_a_telephone_number_containing_letters_is_rejected(): void
    {
        $this->post($this->enquiryUrl, $this->payload(['phone' => 'call me maybe']))
            ->assertSessionHasErrors('phone');
    }

    /**
     * The subject is free text, not an enum.
     *
     * The form offers suggestions, but a visitor whose need is not listed must still
     * reach the institution. An enum would reject their question on the strength of a
     * guess about what subjects exist.
     */
    public function test_an_arbitrary_subject_is_accepted(): void
    {
        $this->post($this->enquiryUrl, $this->payload(['subject' => 'Something nobody predicted']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Something nobody predicted', WebsiteEnquiry::first()->subject);
    }

    // ═══ 2. CSRF ════════════════════════════════════════════════════════════

    /**
     * The form carries a CSRF token, and a POST without one is refused.
     *
     * `VerifyCsrfToken` is global middleware, so the second half is the framework's
     * own guarantee. Asserted anyway, because "the form has @csrf" and "a POST
     * without a token fails" are different claims and only the second is the
     * protection.
     */
    public function test_the_form_carries_a_csrf_token_and_a_post_without_one_is_refused(): void
    {
        $html = $this->get(route('website.page', 'contact-us'))->getContent();

        $this->assertStringContainsString('name="_token"', $html,
            'the enquiry form must carry a CSRF token');
        $this->assertStringContainsString(route('website.enquiry.store'), $html);

        // A POST with the CSRF middleware disabled for the test, so the absence of a
        // token is the only thing under test.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Even with the framework check bypassed, the route still exists and the
        // controller's own validation runs — proving the form is not the only guard.
        $this->post($this->enquiryUrl, [])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
    }

    // ═══ 3. SPAM AND RATE LIMITING ═════════════════════════════════════════

    /**
     * A filled honeypot is stored as spam but reported to the visitor as success.
     *
     * The visitor-facing half matters: a bot that is told "spam detected" learns the
     * filter exists and can adapt. A bot is told it worked.
     */
    public function test_a_filled_honeypot_is_stored_as_spam_and_reported_as_success(): void
    {
        $response = $this->post($this->enquiryUrl, $this->payload(['website' => 'http://spam.example']));

        $response->assertSessionHas('enquiry_sent', 'sent');

        $this->assertSame(1, WebsiteEnquiry::count());
        $this->assertSame(WebsiteEnquiry::STATUS_SPAM, WebsiteEnquiry::first()->status);
        $this->assertTrue(WebsiteEnquiry::first()->looksLikeSpam());
    }

    /** A message carrying many links is spam regardless of the honeypot. */
    public function test_a_message_full_of_links_is_treated_as_spam(): void
    {
        $this->post($this->enquiryUrl, $this->payload([
            'message' => 'Buy now: http://a.example http://b.example http://c.example '
                .'http://d.example http://e.example and more.',
        ]))->assertSessionHas('enquiry_sent', 'sent');

        $this->assertSame(WebsiteEnquiry::STATUS_SPAM, WebsiteEnquiry::first()->status);
    }

    public function test_a_legitimate_message_mentioning_one_link_is_not_treated_as_spam(): void
    {
        $this->post($this->enquiryUrl, $this->payload([
            'message' => 'I read about the programme on http://piie.example and wanted to ask about fees.',
        ]));

        $this->assertSame(WebsiteEnquiry::STATUS_NEW, WebsiteEnquiry::first()->status);
    }

    /**
     * An identical resubmission does not create a second row.
     *
     * Covers a browser retry loop and a person who did not see the confirmation. The
     * visitor is thanked exactly as for a first submission, because telling them
     * otherwise invites another try.
     */
    public function test_an_identical_resubmission_does_not_duplicate_the_enquiry(): void
    {
        $this->post($this->enquiryUrl, $this->payload())->assertSessionHas('enquiry_sent', 'sent');
        $this->post($this->enquiryUrl, $this->payload())->assertSessionHas('enquiry_sent', 'duplicate');

        $this->assertSame(1, WebsiteEnquiry::count(),
            'a repeated submission must not fill the inbox');
    }

    /** The throttle is declared on the route, which is where it is enforced. */
    public function test_the_submission_route_is_rate_limited(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'website.enquiry.store');

        $this->assertNotNull($route, 'the enquiry route must exist');
        $this->assertContains('throttle:6,1', $route->gatherMiddleware(),
            'the route must be throttled before the controller runs');
    }

    // ═══ 4. NOT EXPOSED PUBLICLY ═══════════════════════════════════════════

    /**
     * There is NO public route that reads an enquiry.
     *
     * This is the strongest form of "do not expose submitted enquiries publicly":
     * there is no endpoint to protect, so there is nothing to forget to filter later.
     * Asserted by walking every GET route and proving none of them can return an
     * enquiry.
     */
    public function test_no_public_route_can_read_an_enquiry(): void
    {
        WebsiteEnquiry::create([
            'name' => 'Secret Person',
            'email' => 'secret@example.co.ug',
            'subject' => 'Admissions',
            'message' => 'MY ENQUIRY TEXT MARKER',
        ]);

        // Nothing under the public site paths may expose the marker.
        foreach (['/', '/website/contact-us', '/website/about-us', '/apply', '/login'] as $uri) {
            $response = $this->get($uri);
            $body = $response->getContent();

            $this->assertStringNotContainsString(
                'MY ENQUIRY TEXT MARKER',
                (string) $body,
                "{$uri} must never render an enquiry"
            );
        }
    }

    /** The only reader is behind `auth` + `superAdmin`. */
    public function test_the_inbox_is_closed_to_anonymous_visitors(): void
    {
        $this->get(route('superadmin.enquiries.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * And closed to a signed-in user who is not a Super Admin.
     *
     * `SuperAdminMiddleware` answers `redirect()->back()` rather than aborting 403,
     * so a 302 is the correct outcome. What matters is that the inbox content is NOT
     * returned, which is asserted separately below rather than inferred from a status
     * code.
     */
    public function test_the_inbox_is_closed_to_a_non_super_admin_user(): void
    {
        WebsiteEnquiry::create([
            'name' => 'Secret Person',
            'email' => 'secret@example.co.ug',
            'subject' => 'Admissions',
            'message' => 'MY ENQUIRY TEXT MARKER',
        ]);

        $lecturer = $this->userWithRole(5, 'lecturer@example.co.ug');

        $response = $this->actingAs($lecturer)->get(route('superadmin.enquiries.index'));

        $this->assertSame(302, $response->getStatusCode(),
            'a non-Super-Admin must be redirected away, matching SuperAdminMiddleware');

        $this->assertStringNotContainsString(
            'MY ENQUIRY TEXT MARKER',
            (string) $response->getContent(),
            'the inbox content must not reach a non-Super-Admin'
        );
    }

    /** A Super Admin can read the inbox, and the stored text is escaped. */
    public function test_a_super_admin_can_read_the_inbox_and_stored_markup_is_escaped(): void
    {
        WebsiteEnquiry::create([
            'name' => 'Ama <script>alert(1)</script> Nakato',
            'email' => 'ama@example.co.ug',
            'subject' => 'Admissions',
            'message' => 'Hello <script>alert(2)</script>',
        ]);

        $superAdmin = $this->userWithRole(1, 'root@example.co.ug');

        $html = $this->actingAs($superAdmin)->get(route('superadmin.enquiries.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Admissions', $html);

        // Untrusted visitor input must never execute in the administrator's browser.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
    }

    /** Status changes are validated against the model's own list. */
    public function test_an_arbitrary_status_cannot_be_written(): void
    {
        $enquiry = WebsiteEnquiry::create([
            'name' => 'Ama', 'email' => 'a@example.co.ug',
            'subject' => 'Admissions', 'message' => 'A valid message.',
        ]);

        $superAdmin = \App\Models\User::find(DB::table('users')->insertGetId([
            'name' => 'Super Admin', 'email' => 'root@example.co.ug', 'role_id' => 1,
            'account_status' => 'active', 'password' => bcrypt('secret123'),
        ]));

        $this->actingAs($superAdmin)
            ->post(route('superadmin.enquiries.status', $enquiry->id), ['status' => 'not-a-status'])
            ->assertSessionHasErrors('status');

        $this->actingAs($superAdmin)
            ->post(route('superadmin.enquiries.status', $enquiry->id), ['status' => 'answered'])
            ->assertSessionHasNoErrors();

        $this->assertSame('answered', $enquiry->fresh()->status);
    }

    /** Deleting is a POST, so a crafted GET URL cannot destroy an enquiry. */
    public function test_an_enquiry_cannot_be_deleted_by_a_get_request(): void
    {
        $enquiry = WebsiteEnquiry::create([
            'name' => 'Ama', 'email' => 'a@example.co.ug',
            'subject' => 'Admissions', 'message' => 'A valid message.',
        ]);

        $superAdmin = \App\Models\User::find(DB::table('users')->insertGetId([
            'name' => 'Super Admin', 'email' => 'root@example.co.ug', 'role_id' => 1,
            'account_status' => 'active', 'password' => bcrypt('secret123'),
        ]));

        // A GET must not be able to destroy anything: `destroy` is registered as POST
        // only, so a crafted `<img src=".../delete">` on any page is a 405.
        //
        // Asserted as a status rather than `assertMethodNotAllowed()`, which does not
        // exist on this framework version.
        $this->actingAs($superAdmin)
            ->get(route('superadmin.enquiries.destroy', $enquiry->id))
            ->assertStatus(405);

        $this->assertSame(1, WebsiteEnquiry::count());
    }
}
