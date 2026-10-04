<div class="eoff-form">
    <form method="POST" class="d-block ajaxForm" action="{{ route('admin.online_exams.questions.store', $exam_id) }}">
        @csrf
        <div class="form-row">
            <div class="fpb-7">
                {{-- Same component, same filter, same reasons as the lecturer's form: a prompt is a document, not a sentence, and a maths question typed into a plain box is a wall of `df/dx` and `<= 0`. --}}
                <x-academic-editor
                    name="question"
                    :label="get_phrase('Question')"
                    :required="true"
                    :rows="4"
                    :height="280"
                    help="Formatting, lists, tables and mathematical notation are kept."
                    testid="exam-question-editor" />
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Type') }}</label>
                    <select class="form-control eForm-control" name="type" id="q_type" onchange="toggleOptions(this.value)">
                        <option value="mcq">Multiple Choice (one correct)</option>
                        <option value="multiple_select">Multiple Select</option>
                        <option value="numeric">Numerical Answer</option>
                        <option value="matching">Matching</option>
                        <option value="ordering">Ordering / Sequencing</option>
                        <option value="fill_blank">Advanced Fill Blank</option>
                        <option value="true_false">True / False</option>
                        <option value="short_answer">Short Answer</option>
                    </select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Marks') }}</label>
                    <input type="number" class="form-control eForm-control" name="marks" value="1" min="1" max="127" step="1"></div>
            </div>
            <div id="mcq_options">
                @foreach(['a','b','c','d'] as $opt)
                <div class="fpb-7 mt-1"><label class="eForm-label">{{ get_phrase('Option') }} {{ strtoupper($opt) }}</label>
                    <input type="text" class="form-control eForm-control" name="option_{{ $opt }}"></div>
                @endforeach
                <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Correct Answer') }}</label>
                    <select class="form-control eForm-control" name="correct_answer">
                        <option value="a">A</option><option value="b">B</option><option value="c">C</option><option value="d">D</option>
                    </select></div>
            </div>
            <div id="tf_options" style="display:none;">
                <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Correct Answer') }}</label>
                    <select class="form-control eForm-control" name="correct_answer_tf">
                        <option value="true">True</option><option value="false">False</option>
                    </select></div>
            </div>
            <div id="multiple_options" style="display:none;" class="mt-2">
                <small>Select at least two correct options.</small>
                @foreach(['a','b','c','d','e','f','g','h'] as $opt)
                    <div class="input-group mt-1"><span class="input-group-text"><input type="checkbox" name="correct_option_ids[]" value="{{ $opt }}"></span><input class="form-control" name="structured_options[{{ $loop->index }}][id]" value="{{ $opt }}" readonly><input class="form-control structured-option-label" name="structured_options[{{ $loop->index }}][label]" placeholder="Option {{ strtoupper($opt) }}"></div>
                @endforeach
            </div>
            <div id="numeric_options" style="display:none;" class="mt-2">
                <input class="form-control" name="numeric_target" placeholder="Target numerical answer">
                <input class="form-control mt-1" name="numeric_tolerance" value="0" placeholder="Absolute tolerance">
            </div>
            <div id="fill_blank_options" style="display:none;" class="mt-2"><small>Use [[blank_1]] placeholders in the question. Add accepted answers for each configured blank.</small>@foreach(range(1,4) as $blankNumber)<div class="input-group mt-1"><span class="input-group-text">Blank {{ $blankNumber }}</span><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][id]" value="blank_{{ $blankNumber }}" readonly><input class="form-control fill-blank-answer" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" placeholder="Accepted answer"><input class="form-control fill-blank-answer" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" placeholder="Alternative"></div>@endforeach<label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="case_sensitive" value="1"> Case-sensitive marking</label><input type="hidden" name="trim_whitespace" value="1"></div>
            <div id="matching_options" style="display:none" class="mt-2"><div id="admin_matching_rows"></div><button type="button" class="btn btn-sm btn-outline-primary" id="admin_add_pair">Add Pair</button></div>
            <div id="ordering_options" style="display:none" class="mt-2"><div id="admin_ordering_rows"></div><button type="button" class="btn btn-sm btn-outline-primary" id="admin_add_item">Add Item</button></div>
            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ get_phrase('Add Question') }}</button>
            </div>
        </div>
    </form>
</div>
<script>
function toggleOptions(type){
    document.getElementById('mcq_options').style.display = type==='mcq' ? '' : 'none';
    document.getElementById('multiple_options').style.display = type==='multiple_select' ? '' : 'none';
    document.getElementById('numeric_options').style.display = type==='numeric' ? '' : 'none';
    document.getElementById('fill_blank_options').style.display = type==='fill_blank' ? '' : 'none';
    document.getElementById('matching_options').style.display = type==='matching' ? '' : 'none';
    document.getElementById('ordering_options').style.display = type==='ordering' ? '' : 'none';
    document.getElementById('tf_options').style.display = type==='true_false' ? '' : 'none';
    var groups = {mcq_options:type==='mcq', multiple_options:type==='multiple_select', numeric_options:type==='numeric', fill_blank_options:type==='fill_blank', matching_options:type==='matching', ordering_options:type==='ordering', tf_options:type==='true_false'};
    Object.keys(groups).forEach(function(id){ var group=document.getElementById(id); if(group) group.querySelectorAll('input,select,textarea').forEach(function(field){ field.disabled=!groups[id]; }); });
}
function adminPair(){var c=document.getElementById('admin_matching_rows'),i=c.children.length;if(i>=16)return;var d=document.createElement('div');d.className='input-group mt-1';d.innerHTML='<input class="form-control" name="structured_pairs['+i+'][left_id]" value="left_'+(i+1)+'" readonly><input class="form-control" name="structured_pairs['+i+'][left_text]" placeholder="Left item"><input class="form-control" name="structured_pairs['+i+'][right_id]" value="right_'+(i+1)+'" readonly><input class="form-control" name="structured_pairs['+i+'][right_text]" placeholder="Matching item"><button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">×</button>';c.appendChild(d);}
function adminItem(){var c=document.getElementById('admin_ordering_rows'),i=c.children.length;if(i>=16)return;var d=document.createElement('div');d.className='input-group mt-1';d.innerHTML='<span class="input-group-text">'+(i+1)+'</span><input class="form-control" name="structured_order_items['+i+'][id]" value="item_'+(i+1)+'" readonly><input class="form-control" name="structured_order_items['+i+'][text]" placeholder="Sequence item"><button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">×</button>';c.appendChild(d);}
document.getElementById('admin_add_pair').addEventListener('click',function(){adminPair();toggleOptions(document.querySelector('[name=type]').value);});document.getElementById('admin_add_item').addEventListener('click',function(){adminItem();toggleOptions(document.querySelector('[name=type]').value);});adminPair();adminPair();adminItem();adminItem();toggleOptions(document.querySelector('[name=type]').value);
</script>
