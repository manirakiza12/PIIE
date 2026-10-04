<form method="POST" action="{{ route('teacher.online_exams.questions.store', $exam->id) }}" class="row g-2">
    @csrf
    <div class="col-md-12">
        {{-- A question is a document: a worked calculation, a table of values, a
             list of conditions. A primitive textarea turns all of that into
             `df/dx`, `<= 0` and a column of figures the candidate has to
             re-typeset in their head - and then they are marked against it. The
             stored value is filtered server-side by the model's mutator, so this
             field is the ONLY way markup enters the column, and it is the same
             component used for lesson bodies, assignment instructions and student
             answers. --}}
        <x-academic-editor
            name="question"
            :label="get_phrase('Question')"
            :required="true"
            :height="300"
            :help="$help ?? 'Formatting, lists, tables and mathematical notation are kept.'"
            testid="exam-question-editor" />
    </div>
    <div class="col-md-3">
        <label class="eForm-label">{{ get_phrase('Type') }}</label>
        <select class="form-select eForm-select" name="type" id="question_type" onchange="toggleQuestionTypeFields(this.value)">
            <option value="multiple_choice">Multiple Choice (one correct)</option>
            <option value="multiple_select">Multiple Select (select all that apply)</option>
            <option value="numeric">Numerical Answer</option>
            <option value="matching">Matching</option>
            <option value="ordering">Ordering / Sequencing</option>
            <option value="true_false">True / False</option>
            <option value="fill_blank">Fill Blank</option>
            <option value="short_answer">Short Answer</option>
            <option value="essay">Essay</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="eForm-label">{{ get_phrase('Marks') }}</label>
        <input type="number" min="1" max="127" step="1" class="form-control eForm-control" name="marks" required>
    </div>

    <div class="col-md-7" id="mcq_fields">
        <div class="row g-2">
            <div class="col-md-6"><input class="form-control eForm-control" name="option_a" placeholder="Option A"></div>
            <div class="col-md-6"><input class="form-control eForm-control" name="option_b" placeholder="Option B"></div>
            <div class="col-md-6"><input class="form-control eForm-control" name="option_c" placeholder="Option C"></div>
            <div class="col-md-6"><input class="form-control eForm-control" name="option_d" placeholder="Option D"></div>
        </div>
    </div>

    <div class="col-md-4" id="mcq_correct_answer">
        <label class="eForm-label">{{ get_phrase('Correct Option') }}</label>
        <select class="form-select eForm-select" name="correct_ans" id="mcq_correct_select">
            <option value="">{{ get_phrase('Select the correct option') }}</option>
            <option value="a">Option A</option><option value="b">Option B</option>
            <option value="c">Option C</option><option value="d">Option D</option>
        </select>
    </div>
    <div class="col-md-4" id="tf_correct_answer">
        <label class="eForm-label">{{ get_phrase('Correct Answer') }}</label>
        <select class="form-select eForm-select" name="correct_answer_tf" id="tf_correct_select">
            <option value="">{{ get_phrase('Select the correct answer') }}</option>
            <option value="true">True</option><option value="false">False</option>
        </select>
    </div>
    <div class="col-md-4" id="text_correct_answer">
        <label class="eForm-label">{{ get_phrase('Accepted Answer') }}</label>
        <input type="text" class="form-control eForm-control" name="correct_ans" placeholder="{{ get_phrase('Accepted answer') }}">
    </div>

    <div class="col-12" id="multiple_select_fields" style="display:none;">
        <label class="eForm-label">Options and correct answers</label>
        <div class="row g-2">
            @foreach(['a','b','c','d','e','f','g','h'] as $opt)
                <div class="col-md-6"><div class="input-group"><span class="input-group-text"><input type="checkbox" class="multiple-option-correct" name="correct_option_ids[]" value="{{ $opt }}" aria-label="Correct option {{ strtoupper($opt) }}"></span><input class="form-control eForm-control multiple-option-id" name="structured_options[{{ $loop->index }}][id]" value="{{ $opt }}" readonly><input class="form-control eForm-control multiple-option-label" name="structured_options[{{ $loop->index }}][label]" placeholder="Option {{ strtoupper($opt) }}"></div></div>
            @endforeach
        </div>
        <small class="text-muted">Select at least two correct options. Up to eight options are supported.</small>
    </div>
    <div class="col-md-6" id="numeric_fields" style="display:none;">
        <label class="eForm-label">Target numerical answer</label>
        <input type="text" class="form-control eForm-control" name="numeric_target" inputmode="decimal" placeholder="e.g. 10.5">
        <label class="eForm-label mt-2">Absolute tolerance (optional)</label>
        <input type="text" class="form-control eForm-control" name="numeric_tolerance" inputmode="decimal" value="0" placeholder="e.g. 0.5">
    </div>
    <div class="col-12" id="fill_blank_fields" style="display:none;">
        <label class="eForm-label">Advanced Fill-in-the-Blank</label>
        <small class="text-muted d-block mb-2">Use placeholders such as [[blank_1]] in the question. Add accepted answers for each blank, separated into rows.</small>
        <div class="row g-2" id="fill_blank_rows">
            @foreach(range(1, 1) as $blankNumber)
                <div class="col-md-6 fill-blank-row"><div class="input-group"><span class="input-group-text">Blank {{ $blankNumber }}</span><input class="form-control fill-blank-id" name="structured_blanks[{{ $blankNumber - 1 }}][id]" value="blank_{{ $blankNumber }}" readonly><input class="form-control fill-blank-answer" name="structured_blanks[{{ $blankNumber - 1 }}][accepted_answers][]" placeholder="Accepted answer"></div><input class="form-control mt-1 fill-blank-answer" name="structured_blanks[{{ $blankNumber - 1 }}][accepted_answers][]" placeholder="Alternative answer (optional)"></div>
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add_fill_blank">Add Blank</button>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="case_sensitive" value="1" id="fill_case_sensitive"><label class="form-check-label" for="fill_case_sensitive">Case-sensitive marking</label></div>
        <input type="hidden" name="trim_whitespace" value="1">
    </div>
    <div class="col-12" id="matching_fields" style="display:none;"><label class="eForm-label">Matching pairs</label><small class="text-muted d-block">Add at least two pairs; each left item matches one right item.</small>
        <div class="row g-2" id="matching_rows"></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add_matching_pair">Add Pair</button>
    </div>
    <div class="col-12" id="ordering_fields" style="display:none;"><label class="eForm-label">Correct sequence</label><small class="text-muted d-block">Enter items in the correct order; students will receive a shuffled presentation.</small>
        <div class="row g-2" id="ordering_rows"></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add_ordering_item">Add Item</button>
    </div>

    <div class="col-12">
        <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Add Question') }}</button>
    </div>
</form>

<script>
function toggleQuestionTypeFields(type) {
    var showMcq = type === 'multiple_choice';
    var showMulti = type === 'multiple_select';
    var showNumeric = type === 'numeric';
    var showFill = type === 'fill_blank';
    var showMatching = type === 'matching';
    var showOrdering = type === 'ordering';
    var showCorrect = type === 'multiple_choice' || type === 'true_false';
    document.getElementById('mcq_fields').style.display = showMcq ? 'block' : 'none';
    document.getElementById('multiple_select_fields').style.display = showMulti ? 'block' : 'none';
    document.getElementById('numeric_fields').style.display = showNumeric ? 'block' : 'none';
    document.getElementById('fill_blank_fields').style.display = showFill ? 'block' : 'none';
    document.getElementById('matching_fields').style.display = showMatching ? 'block' : 'none';
    document.getElementById('ordering_fields').style.display = showOrdering ? 'block' : 'none';
    document.getElementById('mcq_correct_answer').style.display = showMcq ? 'block' : 'none';
    document.getElementById('tf_correct_answer').style.display = type === 'true_false' ? 'block' : 'none';
    document.getElementById('text_correct_answer').style.display = 'none';
    function enableGroup(id, enabled) {
        var group = document.getElementById(id);
        if (!group) return;
        group.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !enabled; });
    }
    enableGroup('mcq_fields', showMcq);
    enableGroup('multiple_select_fields', showMulti);
    enableGroup('numeric_fields', showNumeric);
    enableGroup('fill_blank_fields', showFill);
    enableGroup('matching_fields', showMatching);
    enableGroup('ordering_fields', showOrdering);
    enableGroup('mcq_correct_answer', showMcq);
    enableGroup('tf_correct_answer', type === 'true_false');
    enableGroup('text_correct_answer', false);
    document.getElementById('mcq_correct_select').disabled = !showMcq;
    document.getElementById('tf_correct_select').disabled = type !== 'true_false';
    document.querySelector('#text_correct_answer input').disabled = true;
}
function refreshCorrectOptionLabels() {
    var select = document.getElementById('mcq_correct_select');
    if (!select) return;
    ['a','b','c','d'].forEach(function(key) {
        var input = document.querySelector('[name="option_' + key + '"]');
        var option = select.querySelector('option[value="' + key + '"]');
        if (option) option.textContent = 'Option ' + key.toUpperCase() + (input && input.value.trim() ? ' — ' + input.value.trim() : '');
        if (input) input.addEventListener('input', refreshCorrectOptionLabels);
    });
}
toggleQuestionTypeFields(document.getElementById('question_type').value);
refreshCorrectOptionLabels();
function addMatchingRow(){var c=document.getElementById('matching_rows'),i=c.children.length;if(i>=16)return;var d=document.createElement('div');d.className='col-md-6 matching-row';d.innerHTML='<div class="input-group"><span class="input-group-text">'+(i+1)+'</span><input class="form-control" name="structured_pairs['+i+'][left_id]" value="left_'+(i+1)+'" readonly><input class="form-control" name="structured_pairs['+i+'][left_text]" placeholder="Left item"><input class="form-control" name="structured_pairs['+i+'][right_id]" value="right_'+(i+1)+'" readonly><input class="form-control" name="structured_pairs['+i+'][right_text]" placeholder="Matching item"><button type="button" class="btn btn-outline-danger remove-row">×</button></div>';c.appendChild(d);}
function addOrderingRow(){var c=document.getElementById('ordering_rows'),i=c.children.length;if(i>=16)return;var d=document.createElement('div');d.className='col-md-6 ordering-row';d.innerHTML='<div class="input-group"><span class="input-group-text">'+(i+1)+'</span><input class="form-control" name="structured_order_items['+i+'][id]" value="item_'+(i+1)+'" readonly><input class="form-control" name="structured_order_items['+i+'][text]" placeholder="Sequence item"><button type="button" class="btn btn-outline-danger remove-row">×</button></div>';c.appendChild(d);}
document.getElementById('add_fill_blank').addEventListener('click',function(){var c=document.getElementById('fill_blank_rows'),i=c.children.length;if(i>=16)return;var n=i+1,d=document.createElement('div');d.className='col-md-6 fill-blank-row';d.innerHTML='<div class="input-group"><span class="input-group-text">Blank '+n+'</span><input class="form-control fill-blank-id" name="structured_blanks['+i+'][id]" value="blank_'+n+'" readonly><input class="form-control fill-blank-answer" name="structured_blanks['+i+'][accepted_answers][]" placeholder="Accepted answer"></div><input class="form-control mt-1 fill-blank-answer" name="structured_blanks['+i+'][accepted_answers][]" placeholder="Alternative answer (optional)"><button type="button" class="btn btn-sm btn-outline-danger mt-1 remove-row">Remove</button>';c.appendChild(d);d.querySelectorAll('.fill-blank-answer').forEach(function(input){input.addEventListener('input',function(){var row=input.closest('.fill-blank-row,.col-md-6'),answers=row.querySelectorAll('.fill-blank-answer'),active=Array.from(answers).some(function(item){return item.value.trim()!=='';});row.querySelector('.fill-blank-id').disabled=!active;answers.forEach(function(item){item.disabled=!active;});});});toggleQuestionTypeFields(document.getElementById('question_type').value);});document.getElementById('add_matching_pair').addEventListener('click',function(){addMatchingRow();toggleQuestionTypeFields(document.getElementById('question_type').value);});document.getElementById('add_ordering_item').addEventListener('click',function(){addOrderingRow();toggleQuestionTypeFields(document.getElementById('question_type').value);});document.addEventListener('click',function(e){if(!e.target.classList.contains('remove-row'))return;var row=e.target.closest('.matching-row,.ordering-row,.fill-blank-row'),parent=row.parentElement;if(parent.children.length>2)row.remove();Array.from(parent.children).forEach(function(x,n){x.querySelector('.input-group-text').textContent=n+1;});});addMatchingRow();addMatchingRow();addOrderingRow();addOrderingRow();toggleQuestionTypeFields(document.getElementById('question_type').value);
document.querySelectorAll('.multiple-option-label').forEach(function(input){ input.addEventListener('input', function(){
    var row=input.closest('.input-group'); var enabled=input.value.trim()!=='';
    row.querySelector('.multiple-option-id').disabled=!enabled;
    row.querySelector('.multiple-option-correct').disabled=!enabled;
}); });
document.querySelector('form').addEventListener('submit', function(){ document.querySelectorAll('.multiple-option-label').forEach(function(input){ input.dispatchEvent(new Event('input')); }); });
document.querySelectorAll('.fill-blank-answer').forEach(function(input){ input.addEventListener('input', function(){ var group=input.closest('.col-md-6'); var answers=group.querySelectorAll('.fill-blank-answer'); var active=Array.from(answers).some(function(item){return item.value.trim()!=='';}); group.querySelector('.fill-blank-id').disabled=!active; answers.forEach(function(item){item.disabled=!active;}); }); input.dispatchEvent(new Event('input')); });
document.querySelector('form').addEventListener('submit', function(){ document.querySelectorAll('.fill-blank-answer').forEach(function(input){ input.dispatchEvent(new Event('input')); }); });
</script>
