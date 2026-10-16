@php
    $contactSection = $sections->where('page_key', 'contact')->where('section_key', 'contact_page')->last();
    $contactFields = [
        'contact_eyebrow' => ['Section label', 'Get in touch'],
        'contact_heading' => ['Section heading', 'How to reach the Institute'],
        'contact_line' => ['Official telephone line', ''],
        'contact_phone' => ['Other telephone numbers', ''],
        'contact_email' => ['Public email addresses', ''],
        'contact_website' => ['Website address', ''],
        'contact_address' => ['Visit Us address', ''],
        'office_hours' => ['Office Hours', ''],
    ];
@endphp
<div class="col-12 mb-4" id="contact-us-editor">
    <div class="eSection-wrap text-start">
        <h4>{{ get_phrase('Contact Us') }}</h4>
        <p class="text-muted">{{ get_phrase('Edit the details shown on the public Contact Us page. Telephone, email, website and address are also used in the website header and footer.') }}</p>
        <form method="POST" action="{{ route($routePrefix.'.contact.save') }}">
            @csrf
            <div class="row">
                @foreach($contactFields as $key => [$label, $default])
                    <div class="col-12 col-md-6 fpb-7">
                        <label class="eForm-label" for="contact-editor-{{ $key }}">{{ get_phrase($label) }}</label>
                        @if(in_array($key, ['contact_phone', 'contact_email', 'contact_address', 'office_hours'], true))
                            <textarea id="contact-editor-{{ $key }}" name="{{ $key }}" class="form-control eForm-control" rows="3">{{ old($key, $settings[$key]->value ?? $default) }}</textarea>
                        @else
                            <input id="contact-editor-{{ $key }}" name="{{ $key }}" type="{{ $key === 'contact_website' ? 'url' : 'text' }}" class="form-control eForm-control" value="{{ old($key, $settings[$key]->value ?? $default) }}">
                        @endif
                        @if(in_array($key, ['contact_phone', 'contact_email'], true))
                            <small class="text-muted">{{ get_phrase('Enter one per line. Leave blank if not published.') }}</small>
                        @elseif($key === 'office_hours')
                            <small class="text-muted">{{ get_phrase('Enter the official opening days and hours. Leave blank to show the existing enquiry message.') }}</small>
                        @endif
                        @error($key)<div class="text-danger">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="col-12 fpb-7">
                    <label class="eForm-label" for="contact-editor-introduction">{{ get_phrase('Contact introduction') }}</label>
                    <textarea id="contact-editor-introduction" name="introduction" class="form-control eForm-control" rows="5">{{ old('introduction', strip_tags($contactSection->content ?? '')) }}</textarea>
                    <small class="text-muted">{{ get_phrase('This text appears below the section heading. The full introduction is displayed on the Contact Us page.') }}</small>
                    @error('introduction')<div class="text-danger">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="d-flex gap-3 flex-wrap align-items-center">
                <button type="submit" class="btn-form">{{ get_phrase('Save Contact Us') }}</button>
                <a href="{{ route('website.page', 'contact-us') }}" target="_blank" rel="noopener">{{ get_phrase('View Contact Us page') }}</a>
            </div>
        </form>
    </div>
</div>
