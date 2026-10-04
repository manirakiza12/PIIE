{{--
    ===========================================================================
    PUBLIC ENQUIRY FORM
    ===========================================================================

    Posts to `website.enquiry.store`, which validates server-side, throttles, spam-
    filters and stores. It sends no email and notifies nobody — see
    `PublicEnquiryController` for why.

    ── SECURITY, AND WHERE EACH MEASURE LIVES ──────────────────────────────────
      CSRF            `@csrf` below, inside the <form>. Laravel's `VerifyCsrfToken`
                      middleware checks it; it is not optional and not commented out.
      Validation      server-side, in the controller. The `required` attributes here
                      are a convenience for the visitor, never the enforcement.
      Rate limiting   `throttle:6,1` on the route, applied before the controller.
      Spam            a honeypot field, hidden from people and from assistive
                      technology, plus a link-count heuristic in the controller.

    ── THE HONEYPOT ────────────────────────────────────────────────────────────
    A real person cannot see or tab to this field. An automated client that fills in
    every input it finds will set it, and is then stored as `spam` while the visitor
    is shown the same thank-you as everybody else — so the bot learns nothing.

    It carries `aria-hidden="true"`, `tabindex="-1"` and `autocomplete="off"`. The
    `hidden` attribute alone would be enough for the visual hiding but a screen
    reader ignores `hidden` on a focusable element in some modes, so all three are
    used together.

    ── REDISPLAY ───────────────────────────────────────────────────────────────
    After a validation failure the visitor is redirected back by Laravel's default
    POST-redirect-GET only on SUCCESS; a failure re-renders the Contact page with
    the error bag and `old()` intact. That is why the fields below are repopulated
    from `old()`: an enquiry half-typed into a long message must not be lost because
    one field was invalid.

    Only the visitor's own input is ever redisplayed, and it is escaped by `{{ }}`.
--}}

@php
    $piieFormSubjects = $subjects ?? [
        'Admissions',
        'Programmes and Courses',
        'Fees and Finance',
        'Student Records',
        'Something else',
    ];

    // `errors` is shared across the whole page, so the summary is only shown when
    // this form actually failed. Scoped by checking for one of its own field names,
    // which avoids a Contact page that also hosts another form reporting that
    // form's errors here.
    $piieFormFields = ['name', 'email', 'phone', 'subject', 'message'];
    $piieFormHasErrors = collect($piieFormFields)
        ->contains(fn ($f) => $errors->has($f));

    $piieValues = fn (string $field, string $default = '') => old($field, $default);
@endphp

<form class="piie-form piie-enquiry"
      method="POST"
      action="{{ route('website.enquiry.store') }}"
      novalidate
      data-testid="enquiry-form">

    @csrf

    {{-- Honeypot. Sits inside the form so a bot finds it, but is not reachable by
         a person. Not a CAPTCHA and not a third-party request. --}}
    <div class="piie-hp" aria-hidden="true">
        <label for="piie-enq-website">Website</label>
        <input type="text"
               id="piie-enq-website"
               name="website"
               value=""
               tabindex="-1"
               autocomplete="off">
    </div>

    {{-- Success. Reached by redirect after a successful POST, so a refresh cannot
         resubmit. --}}
    @if(session('enquiry_sent'))
        <div class="piie-alert piie-alert--ok" role="status" data-testid="enquiry-success">
            <h3 class="piie-alert__title">
                @if(session('enquiry_name'))
                    Thank you, {{ session('enquiry_name') }}.
                @else
                    Thank you.
                @endif
            </h3>
            <p>
                Your enquiry has been received by the Institute and a member of staff
                will respond to the email address you gave.
            </p>
        </div>
    @endif

    {{-- Error summary. `role="alert"` and `aria-live` so it is announced, and the
         list links to each field so keyboard focus can be moved straight there. --}}
    @if($piieFormHasErrors)
        <div class="piie-alert piie-alert--error" role="alert" data-testid="enquiry-errors">
            <h3 class="piie-alert__title">Please check the following</h3>
            <ul>
                @foreach($piieFormFields as $piieField)
                    @error($piieField)
                        <li><a href="#piie-enq-{{ $piieField }}">{{ $message }}</a></li>
                    @enderror
                @endforeach
            </ul>
        </div>
    @endif

    <div class="piie-form__grid">
        <div class="piie-field">
            <label for="piie-enq-name">
                Full name <span class="piie-req" aria-hidden="true">*</span>
                <span class="visually-hidden-piie">(required)</span>
            </label>
            <input class="piie-form-control"
                   type="text"
                   id="piie-enq-name"
                   name="name"
                   value="{{ $piieValues('name') }}"
                   maxlength="191"
                   required
                   autocomplete="name"
                   @error('name') aria-invalid="true" aria-describedby="piie-enq-name-err" @enderror>
            @error('name')
                <p class="piie-field__err" id="piie-enq-name-err">{{ $message }}</p>
            @enderror
        </div>

        <div class="piie-field">
            <label for="piie-enq-email">
                Email address <span class="piie-req" aria-hidden="true">*</span>
                <span class="visually-hidden-piie">(required)</span>
            </label>
            <input class="piie-form-control"
                   type="email"
                   id="piie-enq-email"
                   name="email"
                   value="{{ $piieValues('email') }}"
                   maxlength="191"
                   required
                   autocomplete="email"
                   @error('email') aria-invalid="true" aria-describedby="piie-enq-email-err" @enderror>
            @error('email')
                <p class="piie-field__err" id="piie-enq-email-err">{{ $message }}</p>
            @enderror
        </div>

        <div class="piie-field">
            {{-- Optional, exactly as the brief specifies. Deliberately not
                 `type="tel"` alone: `type="tel"` gives no mobile keyboard on every
                 browser, and the server-side rule is permissive about the characters
                 an international number legitimately uses. --}}
            <label for="piie-enq-phone">Telephone <span class="piie-hint">(optional)</span></label>
            <input class="piie-form-control"
                   type="tel"
                   id="piie-enq-phone"
                   name="phone"
                   value="{{ $piieValues('phone') }}"
                   maxlength="64"
                   autocomplete="tel"
                   @error('phone') aria-invalid="true" aria-describedby="piie-enq-phone-err" @enderror>
            @error('phone')
                <p class="piie-field__err" id="piie-enq-phone-err">{{ $message }}</p>
            @enderror
        </div>

        <div class="piie-field">
            <label for="piie-enq-subject">
                Enquiry subject <span class="piie-req" aria-hidden="true">*</span>
                <span class="visually-hidden-piie">(required)</span>
            </label>
            {{-- A <select> of suggestions, but NOT an enum: the controller accepts
                 any subject, so a visitor whose need is not listed can still reach
                 the institution rather than being told their question is invalid. --}}
            <select class="piie-form-control"
                    id="piie-enq-subject"
                    name="subject"
                    required
                    @error('subject') aria-invalid="true" aria-describedby="piie-enq-subject-err" @enderror>
                <option value="">Please choose…</option>
                @foreach($piieFormSubjects as $piieSubject)
                    <option value="{{ $piieSubject }}" @selected(old('subject') === $piieSubject)>
                        {{ $piieSubject }}
                    </option>
                @endforeach
            </select>
            @error('subject')
                <p class="piie-field__err" id="piie-enq-subject-err">{{ $message }}</p>
            @enderror
        </div>

        <div class="piie-field piie-field--full">
            <label for="piie-enq-message">
                Message <span class="piie-req" aria-hidden="true">*</span>
                <span class="visually-hidden-piie">(required)</span>
            </label>
            <textarea class="piie-form-control piie-form-control--area"
                      id="piie-enq-message"
                      name="message"
                      rows="6"
                      maxlength="5000"
                      required
                      @error('message') aria-invalid="true" aria-describedby="piie-enq-message-err hint-piie-enq-message" @enderror>{{ $piieValues('message') }}</textarea>
            <p class="piie-field__hint" id="hint-piie-enq-message">
                Tell us which programme you are asking about, if your enquiry relates to one.
            </p>
            @error('message')
                <p class="piie-field__err" id="piie-enq-message-err">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="piie-btn-row" style="margin-top:1.5rem;">
        <button class="piie-btn piie-btn--primary" type="submit">Send enquiry</button>
    </div>

    <p class="piie-form__note">
        Fields marked <span class="piie-req" aria-hidden="true">*</span> are required.
        Your enquiry is seen only by the Institute&rsquo;s administrative staff.
    </p>
</form>
