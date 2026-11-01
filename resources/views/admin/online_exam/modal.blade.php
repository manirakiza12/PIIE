<?php $structureLocked = $exam && method_exists($exam, 'isStructurallyLocked') ? $exam->isStructurallyLocked() : false; ?>
<div class="eoff-form">
    @if($structureLocked)
        <div class="alert alert-warning">{{ get_phrase('This exam structure is locked because attempts already exist. Structural fields are read-only.') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="POST" class="d-block ajaxForm"
          action="{{ $exam ? route('admin.online_exams.update', $exam->id) : route('admin.online_exams.store') }}">
        @csrf
        <div class="form-row">
            <div class="fpb-7"><label class="eForm-label">{{ get_phrase('Exam Title') }} *</label>
                <input type="text" class="form-control eForm-control" name="title" value="{{ $exam->title ?? '' }}" required></div>

            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Exam Type') }} *</label>
                    <select class="form-control eForm-control" name="exam_type" required {{ $structureLocked ? 'disabled' : '' }}>
                        @foreach(\App\Models\OnlineExam::TYPES as $k => $v)
                            <option value="{{ $k }}" {{ ($exam->exam_type ?? 'quiz') === $k ? 'selected' : '' }}>{{ get_phrase($v) }}</option>
                        @endforeach
                    </select>
                    @if($structureLocked)<input type="hidden" name="exam_type" value="{{ $exam->exam_type }}">@endif
                </div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ academic_term('subject', auth()->user()->school_id) }} *</label>
                    <select class="form-control eForm-control" name="subject_id" required {{ $structureLocked ? 'disabled' : '' }}>
                        <option value="">{{ get_phrase('Select subject') }}</option>
                        @foreach($subjects as $s)
                            <option value="{{ $s->id }}" data-programme-id="{{ $s->programme_id ?? '' }}" {{ ($exam->subject_id ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    @if($structureLocked)<input type="hidden" name="subject_id" value="{{ $exam->subject_id }}">@endif
                </div>
            </div>

            @if(academic_education_level((int) auth()->user()->school_id) !== 'tertiary')
<div class="fpb-7 mt-2"><label class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                <select class="form-control eForm-control" name="class_id" {{ $structureLocked ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('All classes') }}</option>
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($exam->class_id ?? '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                @if($structureLocked)<input type="hidden" name="class_id" value="{{ $exam->class_id }}">@endif
            </div>
@endif

            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ academic_term('programme', auth()->user()->school_id) }}</label>
                    <select class="form-control eForm-control" name="programme_id" id="online_exam_programme_id" {{ $structureLocked ? 'disabled' : '' }}>
                        <option value="">{{ get_phrase('Not programme-targeted') }}</option>
                        @foreach(($programmes ?? collect()) as $programme)
                            <option value="{{ $programme->id }}" {{ (string) ($exam->programme_id ?? '') === (string) $programme->id ? 'selected' : '' }}>{{ $programme->name }}{{ $programme->code ? ' ('.$programme->code.')' : '' }}</option>
                        @endforeach
                    </select>
                    @if($structureLocked)<input type="hidden" name="programme_id" value="{{ $exam->programme_id }}">@endif
                </div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ academic_term('session', auth()->user()->school_id) }}</label>
                    <select class="form-control eForm-control" name="session_id" {{ $structureLocked ? 'disabled' : '' }}>
                        <option value="">{{ get_phrase('No academic period selected') }}</option>
                        @foreach(($sessions ?? collect()) as $session)
                            <option value="{{ $session->id }}" {{ (string) ($exam->session_id ?? ($session->status ? $session->id : '')) === (string) $session->id ? 'selected' : '' }}>{{ $session->session_title }}{{ $session->status ? ' — '.get_phrase('Active') : '' }}</option>
                        @endforeach
                    </select>
                    @if($structureLocked)<input type="hidden" name="session_id" value="{{ $exam->session_id }}">@endif
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-3 fpb-7"><label class="eForm-label">{{ get_phrase('Start Date') }} *</label>
                    <input type="date" class="form-control eForm-control" name="start_date"
                           value="{{ $exam ? ($exam->scheduledStartAt() ? $exam->scheduledStartAt()->format('Y-m-d') : '') : old('start_date') }}" required {{ $structureLocked ? 'disabled' : '' }}>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Hour') }} *</label>
                    <select class="form-control eForm-control" name="start_hour" required {{ $structureLocked ? 'disabled' : '' }}>
                        @for ($h = 1; $h <= 12; $h++)
                            <option value="{{ $h }}" {{ ($exam ? ($exam->scheduledStartAt() ? $exam->scheduledStartAt()->format('h') == $h : false) : (old('start_hour') == $h)) ? 'selected' : '' }}>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Minute') }} *</label>
                    <select class="form-control eForm-control" name="start_minute" required {{ $structureLocked ? 'disabled' : '' }}>
                        @for ($m = 0; $m < 60; $m++)
                            <option value="{{ $m }}" {{ ($exam ? ($exam->scheduledStartAt() ? $exam->scheduledStartAt()->format('i') == $m : false) : (old('start_minute') == $m)) ? 'selected' : '' }}>{{ str_pad($m, 2, '0', STR_PAD_LEFT) }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Period') }} *</label>
                    <select class="form-control eForm-control" name="start_period" required {{ $structureLocked ? 'disabled' : '' }}>
                        <option value="AM" {{ ($exam ? ($exam->scheduledStartAt() ? $exam->scheduledStartAt()->format('A') === 'AM' : false) : (old('start_period') === 'AM')) ? 'selected' : '' }}>AM</option>
                        <option value="PM" {{ ($exam ? ($exam->scheduledStartAt() ? $exam->scheduledStartAt()->format('A') === 'PM' : false) : (old('start_period') === 'PM')) ? 'selected' : '' }}>PM</option>
                    </select>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-3 fpb-7"><label class="eForm-label">{{ get_phrase('End Date') }} *</label>
                    <input type="date" class="form-control eForm-control" name="end_date"
                           value="{{ $exam ? ($exam->scheduledEndAt() ? $exam->scheduledEndAt()->format('Y-m-d') : '') : old('end_date') }}" required {{ $structureLocked ? 'disabled' : '' }}>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Hour') }} *</label>
                    <select class="form-control eForm-control" name="end_hour" required {{ $structureLocked ? 'disabled' : '' }}>
                        @for ($h = 1; $h <= 12; $h++)
                            <option value="{{ $h }}" {{ ($exam ? ($exam->scheduledEndAt() ? $exam->scheduledEndAt()->format('h') == $h : false) : (old('end_hour') == $h)) ? 'selected' : '' }}>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Minute') }} *</label>
                    <select class="form-control eForm-control" name="end_minute" required {{ $structureLocked ? 'disabled' : '' }}>
                        @for ($m = 0; $m < 60; $m++)
                            <option value="{{ $m }}" {{ ($exam ? ($exam->scheduledEndAt() ? $exam->scheduledEndAt()->format('i') == $m : false) : (old('end_minute') == $m)) ? 'selected' : '' }}>{{ str_pad($m, 2, '0', STR_PAD_LEFT) }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-2 fpb-7"><label class="eForm-label">{{ get_phrase('Period') }} *</label>
                    <select class="form-control eForm-control" name="end_period" required {{ $structureLocked ? 'disabled' : '' }}>
                        <option value="AM" {{ ($exam ? ($exam->scheduledEndAt() ? $exam->scheduledEndAt()->format('A') === 'AM' : false) : (old('end_period') === 'AM')) ? 'selected' : '' }}>AM</option>
                        <option value="PM" {{ ($exam ? ($exam->scheduledEndAt() ? $exam->scheduledEndAt()->format('A') === 'PM' : false) : (old('end_period') === 'PM')) ? 'selected' : '' }}>PM</option>
                    </select>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-12 fpb-7 alert alert-info">
                    <strong>{{ get_phrase('Schedule Summary') }}:</strong><br>
                    <span id="scheduleSummary">{{ $exam ? $exam->windowSummary() : '' }}</span>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Duration (minutes)') }} *</label>
                    <input type="number" class="form-control eForm-control" name="duration_mins" value="{{ $exam->duration_mins ?? 60 }}" min="1" required {{ $structureLocked ? 'readonly' : '' }}></div>
                <div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Total Marks') }} *</label>
                    <input type="number" class="form-control eForm-control" name="total_marks" value="{{ $exam->total_marks ?? 100 }}" min="1" required {{ $structureLocked ? 'readonly' : '' }}></div>
                <div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Pass Mark') }} *</label>
                    <input type="number" class="form-control eForm-control" name="pass_mark" value="{{ $exam->pass_mark ?? 50 }}" min="0" required {{ $structureLocked ? 'readonly' : '' }}></div>
            </div>

            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Maximum Attempts') }} *</label>
                    <input type="number" class="form-control eForm-control" name="max_attempts" value="{{ $exam->max_attempts ?? 1 }}" min="1" max="20" required {{ $structureLocked ? 'readonly' : '' }}></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Result Release Policy') }} *</label>
                    <select class="form-control eForm-control" name="result_release_policy" required {{ $structureLocked ? 'disabled' : '' }}>
                        @foreach(['immediate' => 'Immediate', 'after_exam_end' => 'After Exam End', 'manual' => 'Manual'] as $k => $v)
                            <option value="{{ $k }}" {{ ($exam->result_release_policy ?? 'immediate') === $k ? 'selected' : '' }}>{{ get_phrase($v) }}</option>
                        @endforeach
                    </select>
                    @if($structureLocked)<input type="hidden" name="result_release_policy" value="{{ $exam->result_release_policy }}">@endif
                </div>
            </div>

            <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Instructions') }}</label>
                <label class="eForm-label">{{ get_phrase('Instructions') }}</label>
                {{-- A candidate's instructions are a document: a list of rules, a table
                     of permitted notation, a worked example. The same component and the
                     same server-side filter as every other piece of course content;
                     the model's mutator filters this on the way in whatever form posts it. --}}
                <x-academic-editor
                    name="instructions"
                    :value="!empty($exam) ? ($exam->getAttributes()['instructions'] ?? '') : ''"
                    :rows="4"
                    :height="260"
                    help="Formatting, lists, tables and mathematical notation are kept."
                    testid="exam-instructions-editor" /></div>

            <div class="row mt-2">
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="shuffle_questions" value="1" id="shuffle_q" {{ ($exam->shuffle_questions ?? false) ? 'checked' : '' }} {{ $structureLocked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="shuffle_q">{{ get_phrase('Shuffle Questions') }}</label>
                </div>
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="shuffle_options" value="1" id="shuffle_o" {{ ($exam->shuffle_options ?? false) ? 'checked' : '' }} {{ $structureLocked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="shuffle_o">{{ get_phrase('Shuffle Options') }}</label>
                </div>
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="allow_previous_navigation" value="1" id="allow_prev" {{ ($exam->allow_previous_navigation ?? true) ? 'checked' : '' }} {{ $structureLocked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="allow_prev">{{ get_phrase('Allow Previous Navigation') }}</label>
                </div>
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="auto_submit" value="1" id="auto_submit" {{ ($exam->auto_submit ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="auto_submit">{{ get_phrase('Auto Submit at Time Up') }}</label>
                </div>
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="webcam_required" value="1" id="webcam_required" {{ ($exam->webcam_required ?? false) ? 'checked' : '' }} {{ $structureLocked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="webcam_required">{{ get_phrase('Webcam Required') }}</label>
                </div>
                <div class="col-6 fpb-7 form-check ms-3">
                    <input type="checkbox" class="form-check-input" name="fullscreen_required" value="1" id="fullscreen_required" {{ ($exam->fullscreen_required ?? false) ? 'checked' : '' }} {{ $structureLocked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="fullscreen_required">{{ get_phrase('Fullscreen Required') }}</label>
                </div>
            </div>

            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ $exam ? get_phrase('Update Exam') : get_phrase('Create Exam') }}</button>
            </div>
        </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const programme = document.getElementById('online_exam_programme_id');
    const subject = document.querySelector('select[name="subject_id"]');
    if (programme && subject) {
        const filterSubjects = function () {
            const selected = programme.value;
            Array.from(subject.options).forEach(function (option) {
                if (!option.value) return;
                option.hidden = !!selected && option.dataset.programmeId !== selected;
                if (option.hidden && option.selected) subject.value = '';
            });
        };
        programme.addEventListener('change', filterSubjects);
        filterSubjects();
    }

    // ── AM/PM time selection: combine date/hour/minute/period into datetime on submit ──
    const form = document.querySelector('form.ajaxForm');
    const combineDateTime = function (dateInput, hourInput, minuteInput, periodSelect, hiddenName) {
        if (!dateInput || !hourInput || !minuteInput || !periodSelect) return;
        const date = dateInput.value;
        const hour = hourInput.value;
        const minute = minuteInput.value;
        const period = periodSelect.value;
        if (!date || !hour || minute === '' || !period) return;

        let hours = parseInt(hour, 10);
        const minutes = parseInt(minute, 10);

        if (period === 'PM' && hours !== 12) hours += 12;
        if (period === 'AM' && hours === 12) hours = 0;

        const hh = String(hours).padStart(2, '0');
        const mm = String(minutes).padStart(2, '0');
        const datetime = date + 'T' + hh + ':' + mm;

        let hidden = document.querySelector('input[name="' + hiddenName + '"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = hiddenName;
            form.appendChild(hidden);
        }
        hidden.value = datetime;
    };

    const fmtTime = function (h, m) {
        const h12 = h % 12 || 12;
        const period = h < 12 ? 'AM' : 'PM';
        return h12 + ':' + String(m).padStart(2, '0') + ' ' + period;
    };

    const updateSummary = function () {
        const startDate = document.querySelector('input[name="start_date"]');
        const startHour = document.querySelector('select[name="start_hour"]');
        const startMinute = document.querySelector('select[name="start_minute"]');
        const startPeriod = document.querySelector('select[name="start_period"]');
        const endDate = document.querySelector('input[name="end_date"]');
        const endHour = document.querySelector('select[name="end_hour"]');
        const endMinute = document.querySelector('select[name="end_minute"]');
        const endPeriod = document.querySelector('select[name="end_period"]');
        const summary = document.getElementById('scheduleSummary');

        if (!startDate || !startHour || !startMinute || !startPeriod || !endDate || !endHour || !endMinute || !endPeriod || !summary) return;
        if (!startDate.value || !startHour.value || !startMinute.value || !endDate.value || !endHour.value || !endMinute.value) return;

        let sh = parseInt(startHour.value, 10);
        const sm = parseInt(startMinute.value, 10);
        sh = startPeriod.value === 'PM' && sh !== 12 ? sh + 12 : sh;
        sh = startPeriod.value === 'AM' && sh === 12 ? 0 : sh;

        let eh = parseInt(endHour.value, 10);
        const em = parseInt(endMinute.value, 10);
        eh = endPeriod.value === 'PM' && eh !== 12 ? eh + 12 : eh;
        eh = endPeriod.value === 'AM' && eh === 12 ? 0 : eh;

        const startLabel = startDate.value + ' at ' + fmtTime(sh, sm);
        const endLabel = endDate.value + ' at ' + fmtTime(eh, em);
        summary.textContent = 'This examination will open on ' + startLabel + ' and close at ' + endLabel + ' (Uganda Time).';
    };

    const startDate = document.querySelector('input[name="start_date"]');
    const startHour = document.querySelector('select[name="start_hour"]');
    const startMinute = document.querySelector('select[name="start_minute"]');
    const startPeriod = document.querySelector('select[name="start_period"]');
    const endDate = document.querySelector('input[name="end_date"]');
    const endHour = document.querySelector('select[name="end_hour"]');
    const endMinute = document.querySelector('select[name="end_minute"]');
    const endPeriod = document.querySelector('select[name="end_period"]');

    [startDate, startHour, startMinute, startPeriod, endDate, endHour, endMinute, endPeriod].forEach(function (el) {
        if (el) el.addEventListener('change', updateSummary);
    });

    if (form) {
        form.addEventListener('submit', function () {
            combineDateTime(startDate, startHour, startMinute, startPeriod, 'start_datetime');
            combineDateTime(endDate, endHour, endMinute, endPeriod, 'end_datetime');
        });
    }

    updateSummary();
});
</script>
</div>
