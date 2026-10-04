<div class="eoff-form">
    @php($bankStructuredConfig = $question && $question->question_schema_version ? json_decode($question->question_config, true) : [])
    @php($bankStructuredMarking = $question && $question->question_schema_version ? json_decode($question->marking_config, true) : [])
    <form method="POST" class="d-block ajaxForm" action="{{ $question ? route('admin.question_bank.update', $question->id) : route('admin.question_bank.store') }}">
        @csrf
        @if($question) @method('PUT') @endif
        <div class="form-row">
            <div class="fpb-7">
                {{-- The admin's own Question Bank editor.

                     This table is SHARED and long-lived - an entry authored here is
                     copied into many exams and rendered by every one of them - so the
                     editor is only safe because the filter is on the QuestionBank
                     model's mutator rather than in this form. An editor here with no
                     filter attached would have left an injection surface outliving any
                     single exam.

                     The raw attribute is read rather than the accessor, so the editor
                     is handed the markup that was actually stored. --}}
                <x-academic-editor
                    name="question"
                    :id="'admin-bank-question-'.($question->id ?? 'new')"
                    :value="$question->getAttributes()['question'] ?? ''"
                    :required="true"
                    :rows="4"
                    :height="260"
                    help="Formatting, lists, tables and mathematical notation are kept."
                    testid="bank-question-editor" />
            </div>
            <div class="fpb-7 mt-2"><label class="eForm-label">{{ academic_term('subject', auth()->user()->school_id) }}</label>
                <select class="form-control eForm-control" name="subject_id">
                    <option value="">{{ get_phrase('General') }}</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}" @selected(($question->subject_id ?? null) == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Type') }}</label>
                    <select class="form-control eForm-control" name="type">
                        <option value="mcq" @selected(($question->type ?? '') === 'mcq')>Multiple Choice</option>
                        <option value="multiple_select" @selected(($question->normalized_type ?? '') === 'multiple_select')>Multiple Select</option>
                        <option value="numeric" @selected(($question->normalized_type ?? '') === 'numeric')>Numerical Answer</option>
                        <option value="fill_blank" @selected(($question->normalized_type ?? '') === 'fill_blank')>Advanced Fill Blank</option>
                        <option value="true_false" @selected(($question->type ?? '') === 'true_false')>True / False</option>
                        <option value="short" @selected(($question->type ?? '') === 'short')>Short Answer</option>
                    </select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Lifecycle') }}</label><select class="form-control eForm-control" name="status"><option value="draft" @selected(($question->status ?? 'active') === 'draft')>Draft</option><option value="active" @selected(($question->status ?? 'active') === 'active')>Active</option><option value="retired" @selected(($question->status ?? '') === 'retired')>Retired</option><option value="archived" @selected(($question->status ?? '') === 'archived')>Archived</option></select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Difficulty') }}</label>
                    <select class="form-control eForm-control" name="difficulty">
                        <option value="easy" @selected(($question->difficulty ?? '') === 'easy')>Easy</option>
                        <option value="medium" @selected(($question->difficulty ?? 'medium') === 'medium')>Medium</option>
                        <option value="hard" @selected(($question->difficulty ?? '') === 'hard')>Hard</option>
                    </select></div>
            </div>
            @foreach(['a','b','c','d'] as $opt)
            <div class="fpb-7 mt-1"><label class="eForm-label">{{ get_phrase('Option') }} {{ strtoupper($opt) }}</label>
                    <input type="text" class="form-control eForm-control" name="option_{{ $opt }}" value="{{ $question->{'option_'.$opt} ?? '' }}"></div>
            @endforeach
            @php($bankKey = $question ? \App\Support\OnlineExams\AnswerKey::forQuestion($question) : null)
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ academic_term('programme', auth()->user()->school_id) }}</label><select class="form-control eForm-control" name="programme_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($programmes as $p)<option value="{{ $p->id }}" @selected(($question->programme_id ?? null)==$p->id)>{{ $p->name }}</option>@endforeach</select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ academic_term('session', auth()->user()->school_id) }}</label><select class="form-control eForm-control" name="session_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected(($question->session_id ?? null)==$s->id)>{{ $s->session_title }}</option>@endforeach</select></div>
                <div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Topic') }}</label><select class="form-control eForm-control" name="topic_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($topics as $topic)<option value="{{ $topic->id }}" data-subject="{{ $topic->subject_id }}" @selected(($question->topic_id ?? null)==$topic->id)>{{ $topic->name }}</option>@endforeach</select></div><div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Subtopic') }}</label><select class="form-control eForm-control" name="subtopic_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($subtopics as $subtopic)<option value="{{ $subtopic->id }}" data-subject="{{ $subtopic->subject_id }}" data-parent="{{ $subtopic->parent_id }}" @selected(($question->subtopic_id ?? null)==$subtopic->id)>{{ $subtopic->name }}</option>@endforeach</select></div><div class="col-4 fpb-7"><label class="eForm-label">{{ get_phrase('Tags') }}</label><select class="form-control eForm-control" name="tag_ids[]" multiple>@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected($question && $question->tags->contains($tag->id))>{{ $tag->name }}</option>@endforeach</select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Correct Option') }}</label>
                    <select class="form-control eForm-control" name="correct_ans" data-bank-correct="mcq">
                        <option value="">{{ get_phrase('Select correct option') }}</option>
                        @foreach(['a','b','c','d'] as $opt)<option value="{{ $opt }}" @selected($bankKey === $opt)>Option {{ strtoupper($opt) }}</option>@endforeach
                    </select>
                    <select class="form-control eForm-control mt-1" name="correct_answer_tf" data-bank-correct="true_false">
                        <option value="">{{ get_phrase('Select correct answer') }}</option>
                        <option value="true" @selected($bankKey === 'true')>True</option><option value="false" @selected($bankKey === 'false')>False</option>
                    </select>
                    <input type="text" class="form-control eForm-control mt-1" name="correct_ans" data-bank-correct="text" value="{{ $question->correct_ans ?? '' }}" placeholder="{{ get_phrase('Accepted answer') }}">
                </div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Marks') }}</label>
                    <input type="number" class="form-control eForm-control" name="marks" value="{{ $question->marks ?? 1 }}" min="1" max="127" step="1"></div>
            </div>
            <div class="fpb-7 mt-2" id="bank_multiple_options" style="display:none"><small>Select at least two correct options.</small>@foreach(['a','b','c','d','e','f','g','h'] as $opt)<div class="input-group mt-1"><span class="input-group-text"><input type="checkbox" name="correct_option_ids[]" value="{{ $opt }}"></span><input class="form-control bank-structured-id" name="structured_options[{{ $loop->index }}][id]" value="{{ $opt }}" readonly><input class="form-control bank-structured-label" name="structured_options[{{ $loop->index }}][label]" placeholder="Option {{ strtoupper($opt) }}"></div>@endforeach</div>
            <div class="fpb-7 mt-2" id="bank_numeric_options" style="display:none"><input class="form-control" name="numeric_target" placeholder="Target numerical answer"><input class="form-control mt-1" name="numeric_tolerance" value="0" placeholder="Absolute tolerance"></div>
            <div class="fpb-7 mt-2" id="bank_fill_blank_options" style="display:none"><small>Use [[blank_1]] placeholders in the question.</small>@foreach(range(1,4) as $blankNumber)@php($blankId = data_get($bankStructuredConfig, 'blanks.'.($blankNumber-1).'.id', 'blank_'.$blankNumber))<div class="input-group mt-1"><span class="input-group-text">Blank {{ $blankNumber }}</span><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][id]" value="{{ $blankId }}" readonly><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" value="{{ implode(',', (array) data_get($bankStructuredMarking, 'blanks.'.$blankId.'.accepted_answers', [])) }}" placeholder="Accepted answer"><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" placeholder="Alternative"></div>@endforeach<label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="case_sensitive" value="1" @checked(($bankStructuredMarking['case_sensitive'] ?? false))> Case-sensitive marking</label><input type="hidden" name="trim_whitespace" value="1"></div>
            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ get_phrase('Save to Bank') }}</button>
            </div>
        </div>
    </form>
</div>
    <script>
(function(){var form=document.currentScript&&document.currentScript.parentElement.querySelector('form'); if(!form)return; var type=form.querySelector('[name=type]'), controls=form.querySelectorAll('[data-bank-correct]'); function sync(){var v=type.value;var t=v==='true_false'?'true_false':(v==='mcq'?'mcq':((v==='short'||v==='essay')?'text':'none')); controls.forEach(function(c){var on=c.dataset.bankCorrect===t;c.style.display=on?'block':'none';c.disabled=!on;});document.getElementById('bank_multiple_options').style.display=v==='multiple_select'?'block':'none';document.getElementById('bank_numeric_options').style.display=v==='numeric'?'block':'none';document.getElementById('bank_fill_blank_options').style.display=v==='fill_blank'?'block':'none';} type.addEventListener('change',sync); sync();})();
(function(){var course=document.querySelector('form [name="subject_id"]'),topic=document.querySelector('form [name="topic_id"]'),subtopic=document.querySelector('form [name="subtopic_id"]');if(!course||!topic||!subtopic)return;function sync(){var c=course.value,t=topic.value;Array.from(topic.options).forEach(function(o){o.hidden=o.value!==''&&o.dataset.subject&&o.dataset.subject!==c;});Array.from(subtopic.options).forEach(function(o){o.hidden=o.value!==''&&((o.dataset.subject&&o.dataset.subject!==c)||(o.dataset.parent&&o.dataset.parent!==t));});}course.addEventListener('change',sync);topic.addEventListener('change',sync);sync();})();
    </script>
