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

            <div class="fpb-7 mt-2"><label class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                <select class="form-control eForm-control" name="class_id" {{ $structureLocked ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('All classes') }}</option>
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($exam->class_id ?? '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                @if($structureLocked)<input type="hidden" name="class_id" value="{{ $exam->class_id }}">@endif
            </div>

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
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Start Date/Time') }} *</label>
                    <input type="datetime-local" class="form-control eForm-control" name="start_datetime"
                           value="{{ $exam && $exam->start_datetime ? $exam->start_datetime->format('Y-m-d\TH:i') : '' }}" required {{ $structureLocked ? 'disabled' : '' }}>
                    @if($structureLocked && $exam?->start_datetime)<input type="hidden" name="start_datetime" value="{{ $exam->start_datetime->format('Y-m-d H:i:s') }}">@endif
                </div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('End Date/Time') }} *</label>
                    <input type="datetime-local" class="form-control eForm-control" name="end_datetime"
                           value="{{ $exam && $exam->end_datetime ? $exam->end_datetime->format('Y-m-d\TH:i') : '' }}" required {{ $structureLocked ? 'disabled' : '' }}>
                    @if($structureLocked && $exam?->end_datetime)<input type="hidden" name="end_datetime" value="{{ $exam->end_datetime->format('Y-m-d H:i:s') }}">@endif
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
    if (!programme || !subject) return;
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
});
</script>
</div>
