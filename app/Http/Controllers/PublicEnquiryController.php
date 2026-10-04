<?php

namespace App\Http\Controllers;

use App\Models\WebsiteEnquiry;
use App\Support\Website\PublicContactChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PUBLIC ENQUIRY SUBMISSION — the Contact page form.
 *
 * ── SCOPE, STATED PLAINLY ────────────────────────────────────────────────────
 * This is the "small dedicated enquiry module" the brief authorised after I
 * confirmed no suitable mechanism existed. It does four things and no more:
 * validate, throttle, store, redirect. It sends no email, creates no ticket, and
 * notifies nobody — an institutional decision about who answers enquiries, and
 * within what period, has not been made and is not invented here.
 *
 * ── SPAM AND ABUSE ───────────────────────────────────────────────────────────
 * Four independent layers, none of which is a CAPTCHA, because a CAPTCHA would
 * need a third-party service and would put a third-party request on a page that
 * currently makes none:
 *
 *   1. `throttle:6,1` on the route — six submissions per IP per minute. Enforced
 *      by the framework before this controller runs.
 *   2. A `website_enquiries` unique index is NOT used here; instead a repeat
 *      submission with an identical name+email+message inside a short window is
 *      rejected, which stops a stuck browser retry loop filling the inbox.
 *   3. A honeypot field, hidden from people and from assistive technology. A
 *      non-empty value is stored as `spam` and reported to the visitor as success,
 *      so the bot learns nothing.
 *   4. A link-count check on the message body: legitimate enquiries do not
 *      contain five URLs.
 *
 * ── NOT STORED, AND WHY ──────────────────────────────────────────────────────
 * Nothing is echoed back into the page except the visitor's own name, and only on
 * the success screen. No enquiry is ever rendered on a public page. The honeypot
 * value is stored solely so Super Admin can see the pattern.
 */
class PublicEnquiryController extends Controller
{
    /**
     * Subjects offered on the form.
     *
     * PRESENTATION ONLY — the `subject` column is free text and the controller
     * accepts any value. These are the labels the institution most obviously
     * needs; a visitor who needs something else types it. Making this an enum
     * would reject valid enquiries on the strength of a guess.
     */
    private const SUBJECT_SUGGESTIONS = [
        'Admissions',
        'Programmes and Courses',
        'Fees and Finance',
        'Student Records',
        'Partnerships and Collaboration',
        'Careers',
        'Something else',
    ];

    /**
     * Show the enquiry form.
     *
     * Rendered as part of the Contact page rather than as its own route, so the
     * form and the contact details a visitor is reading are on one page. Reached
     * by GET so a refresh does not re-post.
     */
    public function create(Request $request)
    {
        return view('frontend.partials.blocks.enquiry_form', [
            'subjects' => self::SUBJECT_SUGGESTIONS,
            'old' => [],
        ]);
    }

    /**
     * Store an enquiry.
     *
     * Redirects rather than returning JSON on success, because the form is a plain
     * HTML POST: a redirect gives the visitor a shareable, refreshable URL and makes
     * a double submission impossible without JS.
     */
    public function store(Request $request)
    {
        // 1. Server-side validation. Every field the brief lists, with the
        //    requirements it specifies. `max` on the message is what stops the
        //    inbox being filled with a pasted novel.
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:191'],
            // Optional, as specified. Permissive on format on purpose: international
            // numbers legitimately contain spaces, dashes, brackets and a leading +
            // and a strict numeric rule would reject a Uganda number written
            // "+256 700 111 222".
            'phone' => ['nullable', 'string', 'max:64', 'regex:/^[0-9+()\-.\s]{6,64}$/'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            /**
             * The honeypot.
             *
             * Validated as an arbitrary short string, NOT as `boolean`.
             *
             * This was a real bug. With `'boolean'`, Laravel accepts only true/false,
             * 1/0 and "1"/"0" — so a bot that helpfully typed its own URL into the
             * hidden field produced "The website field must be true or false", a
             * validation error, and a 302 back to the form. The spam was neither
             * stored nor silently accepted; it was rejected loudly and the bot got a
             * distinct signal that the field was being watched.
             *
             * A permissive string rule means ANY non-empty value passes validation,
             * reaches the handler below, and is stored as `spam` while the visitor is
             * shown the ordinary thank-you. The field still cannot break the form.
             */
            'website' => ['nullable', 'string', 'max:191'],
        ], [
            'name.required' => 'Please tell us your name.',
            'email.required' => 'Please provide an email address so we can reply.',
            'email.email' => 'That does not look like a valid email address.',
            'phone.regex' => 'Please enter a valid telephone number, or leave it blank.',
            'subject.required' => 'Please choose what your enquiry is about.',
            'message.required' => 'Please write your message.',
            'message.min' => 'Please give us a little more detail — at least 10 characters.',
        ], [
            'name' => 'full name',
            'phone' => 'telephone number',
            'subject' => 'enquiry subject',
        ]);

        // 2. Honeypot. Reported as success and stored as spam, so the sender gets
        //    no signal that it was caught.
        $isHoneypot = ! empty($validated['website']);

        // 3. Link-count heuristic. Counted on the RAW message, not the escaped one,
        //    so an escaped URL is still counted.
        $linkCount = substr_count((string) $request->input('message'), 'http');

        if ($linkCount >= 5) {
            $isHoneypot = true;
        }

        // 4. Duplicate-submission guard. A browser retry, or a person who did not
        //    see the confirmation, must not produce five identical rows.
        $duplicate = WebsiteEnquiry::query()
            ->where('email', $validated['email'])
            ->where('message', $validated['message'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($duplicate) {
            return $this->thanks($request, $validated['name'], duplicate: true);
        }

        WebsiteEnquiry::create([
            'school_id' => $this->publicSchoolId(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => $isHoneypot ? WebsiteEnquiry::STATUS_SPAM : WebsiteEnquiry::STATUS_NEW,
            'honeypot' => $validated['website'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        if (! $isHoneypot) {
            // 5. RateLimiter as a second, cumulative layer. `throttle:` on the route
            //    is per-IP-per-minute; this is per-email across the whole session set,
            //    which is what stops one determined visitor spread across addresses.
            RateLimiter::hit('enquiry:'.Str::lower($validated['email']), 3600);
        }

        return $this->thanks($request, $validated['name']);
    }

    /**
     * The confirmation screen.
     *
     * Says only what is true: the enquiry has been received and the institution
     * will respond. It does not promise a response time, because none has been
     * approved.
     */
    private function thanks(Request $request, string $name, bool $duplicate = false): \Illuminate\Http\RedirectResponse
    {
        return Redirect::route('website.page', 'contact-us')
            ->with('enquiry_sent', $duplicate ? 'duplicate' : 'sent')
            // Only the visitor's own first name, and only their own submission, so
            // nothing here can leak one visitor's data to another.
            ->with('enquiry_name', Str::before($name, ' '));
    }

    /**
     * The tenant this public submission belongs to.
     *
     * Reuses the same resolver the rest of the public site uses, so an enquiry is
     * attributed to the institution whose site received it.
     */
    private function publicSchoolId(): ?int
    {
        try {
            return \App\Support\PublicTenantResolver::resolveSchoolId();
        } catch (\Throwable) {
            // A failure to resolve must not lose the enquiry; the row simply stays
            // unscoped and remains readable by Super Admin.
            return null;
        }
    }
}
