<div class="eForm-layouts">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('superadmin.school.update', ['id' => $school->id]) }}">
         @csrf 
        <div class="form-row">
            <div class="fpb-7">
                <label for="title" class="eForm-label">{{ get_phrase('Title') }}</label>
                <input type="text" class="form-control eForm-control" value="{{ $school->title }}" id="title" name = "title" required>
            </div>
            <div class="fpb-7">
                <label for="address" class="eForm-label">{{ get_phrase('School address') }}</label>
                <textarea class="form-control eForm-control" id="address" name = "address" rows="2" required>{{ $school->address }}</textarea>
            </div>
            <div class="fpb-7">
                <label for="title" class="eForm-label">{{ get_phrase('School phone') }}</label>
                <input type="number" min="0" class="form-control eForm-control" value="{{ $school->phone }}" id="phone" name = "phone" required>
            </div>
            <div class="fpb-7">
                <label for="school_info" class="eForm-label">{{ get_phrase('School info') }}</label>
                <textarea class="form-control eForm-control" id="school_info" name = "school_info" rows="2" required>{{ $school->school_info }}</textarea>
            </div>
            <div class="fpb-7">
                <label for="education_level" class="eForm-label">{{ get_phrase('Education Level') }}</label>
                <select name="education_level" id="education_level" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="">{{ get_phrase('Select an education level') }}</option>
                    <option value="primary" {{ $school->education_level == 'primary' ? 'selected' : '' }}>{{ get_phrase('Primary School') }}</option>
                    <option value="secondary" {{ $school->education_level == 'secondary' ? 'selected' : '' }}>{{ get_phrase('Secondary School') }}</option>
                    <option value="tertiary" {{ $school->education_level == 'tertiary' ? 'selected' : '' }}>{{ get_phrase('Tertiary Institution / University') }}</option>
                    <option value="vocational" {{ $school->education_level == 'vocational' ? 'selected' : '' }}>{{ get_phrase('Vocational / Technical Institution') }}</option>
                    <option value="mixed" {{ $school->education_level == 'mixed' ? 'selected' : '' }}>{{ get_phrase('Mixed / Multi-Level Institution') }}</option>
                </select>
                <small class="text-muted">{{ get_phrase('Descriptive only — does not change application behavior.') }}</small>
            </div>
            <div class="fpb-7">
                <label for="school_type" class="eForm-label">{{ get_phrase('Institution Type') }}</label>
                <select name="school_type" id="school_type" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="k12" {{ $school->school_type == 'k12' ? 'selected' : '' }}>{{ get_phrase('Class-Based (K-12)') }}</option>
                    <option value="higher_ed" {{ $school->school_type == 'higher_ed' ? 'selected' : '' }}>{{ get_phrase('Programme-Based (Higher Education)') }}</option>
                    <option value="mixed" {{ $school->school_type == 'mixed' ? 'selected' : '' }}>{{ get_phrase('Mixed (both structures)') }}</option>
                </select>
                <small class="text-muted">{{ get_phrase('Controls which academic modules (Classes vs Programmes/Courses) this school sees.') }}</small>
            </div>
            <div class="fpb-7">
                <h6 class="eForm-label text-uppercase mb-2">{{ get_phrase('Regional Settings') }}</h6>
                <p class="text-muted small">{{ get_phrase('These settings apply only to this institution. Every other institution keeps its own.') }}</p>
            </div>
            <div class="fpb-7">
                <label for="timezone" class="eForm-label">{{ get_phrase('Timezone') }} *</label>
                {{-- Searchable dropdown, never a free-text box. Asking an
                     administrator to type a raw IANA identifier meant this field
                     was effectively unset in practice, which is why Live Classes
                     silently fell back to the platform default. Each option reads
                     "Kampala (UTC+3) - Africa" while storing "Africa/Kampala":
                     a real IANA identifier, so a region with daylight saving
                     stays correct all year. --}}
                <select name="timezone" id="timezone" class="form-select eForm-select" required
                        data-placeholder="{{ get_phrase('Search for your city or region') }}">
                    <option value="">{{ get_phrase('Select a timezone') }}</option>
                    @foreach($timezoneOptions as $area => $options)
                        <optgroup label="{{ $area }}">
                            @foreach($options as $identifier => $option)
                                <option value="{{ $identifier }}"
                                    {{ $school->timezone === $identifier ? 'selected' : '' }}>
                                    {{ $option['label'] }} — {{ $identifier }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <small class="text-muted d-block mt-1">
                    {{ get_phrase('All class times, schedules and notifications are shown in this timezone.') }}
                </small>
                @error('timezone')<span class="text-danger d-block mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="fpb-7">
                <label for="primary_locale" class="eForm-label">{{ get_phrase('Primary Language') }}</label>
                <select name="primary_locale" id="primary_locale" class="form-select eForm-select">
                    <option value="">{{ get_phrase('Use platform default') }}</option>
                    @foreach(['en' => 'English', 'fr' => 'Français', 'sw' => 'Kiswahili'] as $locale => $label)
                        <option value="{{ $locale }}" {{ $school->primary_locale === $locale ? 'selected' : '' }}>{{ $label }} ({{ $locale }})</option>
                    @endforeach
                </select>
            </div>
            <div class="fpb-7">
                <label for="country_code" class="eForm-label">{{ get_phrase('Country') }}</label>
                <select name="country_code" id="country_code" class="form-select eForm-select">
                    <option value="">{{ get_phrase('Select country') }}</option>
                    @foreach($countryCodes as $countryCode)<option value="{{ $countryCode }}" {{ $school->country_code === $countryCode ? 'selected' : '' }}>{{ $countryCode }}</option>@endforeach
                </select>
            </div>
            <div class="fpb-7">
                <label for="school_currency" class="eForm-label">{{ get_phrase('Currency') }}</label>
                <select name="school_currency" id="school_currency" class="form-select eForm-select">
                    <option value="">{{ get_phrase('Use existing/default currency') }}</option>
                    @foreach($currencies as $currency)<option value="{{ $currency->code }}" {{ $school->school_currency === $currency->code ? 'selected' : '' }}>{{ $currency->name }} ({{ $currency->code }} — {{ $currency->symbol }})</option>@endforeach
                </select>
            </div>
            <div class="fpb-7">
                <label for="currency_position" class="eForm-label">{{ get_phrase('Currency Position') }}</label>
                <select name="currency_position" id="currency_position" class="form-select eForm-select">
                    <option value="" {{ $school->currency_position ? '' : 'selected' }}>{{ get_phrase('Use existing/default position') }}</option>
                    @foreach(['left' => 'Left', 'right' => 'Right', 'left-space' => 'Left with a space', 'right-space' => 'Right with a space'] as $position => $label)
                        <option value="{{ $position }}" {{ $school->currency_position === $position ? 'selected' : '' }}>{{ get_phrase($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="fpb-7">
                <label for="academic_calendar_pattern" class="eForm-label">{{ get_phrase('Academic Calendar Pattern') }}</label>
                <select name="academic_calendar_pattern" id="academic_calendar_pattern" class="form-select eForm-select">
                    <option value="term" {{ ($school->academic_calendar_pattern ?: 'term') === 'term' ? 'selected' : '' }}>{{ get_phrase('Term') }}</option>
                    <option value="semester" {{ ($school->academic_calendar_pattern ?: 'term') === 'semester' ? 'selected' : '' }}>{{ get_phrase('Semester') }}</option>
                </select>
            </div>
            <div class="fpb-7 pt-2">
                <button class="btn-form" type="submit">{{ get_phrase('Update school') }}</button>
            </div>
        </div>
    </form>
</div>

<link rel="stylesheet" href="{{ asset('assets/css/select2.min.css') }}" />

<script type="text/javascript">

    "use strict";

    $(document).ready(function () {
      $(".eChoice-multiple-with-remove").select2();

      // Searchable timezone picker. ~420 zones is too many to scroll, and an
      // administrator must never have to know the IANA spelling.
      $("#timezone").select2({
        width: "100%",
        placeholder: "{{ get_phrase('Search for your city or region') }}",
        matcher: function (params, data) {
          if ($.trim(params.term) === "") {
            return null;
          }
          if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
            return data;
          }
          return null;
        }
      });
    });
</script>

<script src="{{ asset('assets/js/select2.min.js') }}"></script>
