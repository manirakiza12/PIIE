@extends('teacher.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4>{{ get_phrase('Question Management') }}: {{ $exam->title }}</h4>
            <div class="d-flex gap-2">
                <a class="export_btn bg-secondary" href="{{ route('teacher.online_exams.preview', $exam->id) }}">{{ get_phrase('Preview') }}</a>
                <a class="export_btn" href="{{ route('teacher.online_exams.edit', $exam->id) }}">{{ get_phrase('Back') }}</a>
            </div>
        </div>
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
    {{ get_phrase('Question marks') }}: <strong>{{ $questionMarksTotal }}</strong> / <strong>{{ $exam->total_marks }}</strong>
</div>

@if(!$structureLocked)
<div class="eSection-wrap mb-3">
    <h6>{{ get_phrase('Add Question') }}</h6>
    @include('teacher.online_exam.question_form')
</div>

<div class="eSection-wrap mb-3">
    <h6>{{ get_phrase('Import From Question Bank') }}</h6>
    <form method="POST" action="{{ route('teacher.online_exams.question_bank.import', $exam->id) }}">
        @csrf
        <div class="table-responsive" style="max-height: 220px; overflow-y:auto;">
            <table class="table eTable">
                <thead><tr><th>{{ get_phrase('Pick') }}</th><th>{{ get_phrase('Question') }}</th><th>{{ get_phrase('Type') }}</th><th>{{ get_phrase('Marks') }}</th></tr></thead>
                <tbody>
                @forelse($bank as $b)
                    <tr>
                        <td><input type="checkbox" name="question_bank_ids[]" value="{{ $b->id }}"></td>
                        <td class="piie-prose">{!! $b->prosePrompt() !!}</td>
                        <td>{{ strtoupper(str_replace('_', ' ', $b->normalized_type)) }}</td>
                        <td>{{ $b->marks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted text-center">{{ get_phrase('No question bank entries available') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <button class="eBtn eBtn-secondary" type="submit">{{ get_phrase('Import Selected') }}</button>
    </form>
</div>
@endif

<div class="eSection-wrap">
    <h6>{{ get_phrase('Exam Questions') }}</h6>
    <form id="reorderQuestionsForm" method="POST" action="{{ route('teacher.online_exams.questions.reorder', $exam->id) }}">@csrf</form>
    <div>
                <div class="table-responsive online-exam-table-wrap">
            <table class="table eTable">
                <thead><tr><th>{{ get_phrase('Order') }}</th><th>{{ get_phrase('Question') }}</th><th>{{ get_phrase('Type') }}</th><th>{{ get_phrase('Marks') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
                <tbody>
                @forelse($questions as $question)
                    <tr>
                        <td>
                            <input form="reorderQuestionsForm" type="hidden" name="question_ids[]" value="{{ $question->id }}">
                            {{ $question->sort_order }}
                        </td>
                        <td class="piie-prose">{!! $question->prosePrompt() !!}</td>
                        <td>{{ strtoupper(str_replace('_', ' ', $question->normalized_type)) }}</td>
                        <td>{{ $question->marks }}</td>
                        <td>
                            @if(!$structureLocked)
                                <button type="button" class="eBtn eBtn-sm eBtn-primary" data-bs-toggle="collapse" data-bs-target="#edit_q_{{ $question->id }}">{{ get_phrase('Edit') }}</button>
                                <form method="POST" action="{{ route('teacher.online_exams.questions.destroy', $question->id) }}" class="d-inline" onsubmit="return confirm('{{ get_phrase('Delete question?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="eBtn eBtn-sm eBtn-danger" type="submit">{{ get_phrase('Delete') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @if(!$structureLocked)
                    <tr class="collapse" id="edit_q_{{ $question->id }}">
                        <td colspan="5">
                            <form method="POST" action="{{ route('teacher.online_exams.questions.update', $question->id) }}" class="row g-2">
                                @csrf
                                @method('PUT')
                                                                {{-- Full width, and on its own row.

                                     This replaced a single-line `<input>`, which became
                                     a data-loss path the moment prompts could contain
                                     markup: opening a question with a table in it showed
                                     the raw tags as text, and saving stored that text -
                                     so the formatting was destroyed and every candidate
                                     would read literal angle brackets.

                                     The type, marks, answer key and option fields keep
                                     their existing columns on the row below, and the
                                     per-row script still finds this form through
                                     `document.currentScript`, because the component
                                     renders inside it. --}}
                                <div class="col-12">
                                    <x-academic-editor
                                        name="question"
                                        :id="'edit-question-'.$question->id"
                                        :value="$question->getAttributes()['question'] ?? ''"
                                        :required="true"
                                        :height="220"
                                        help="Formatting, lists, tables and mathematical notation are kept."
                                        testid="exam-question-edit-editor" />
                                </div>
                                @php($structuredConfig = $question->question_schema_version ? json_decode($question->question_config, true) : [])
                                @php($structuredMarking = $question->question_schema_version ? json_decode($question->marking_config, true) : [])
                                <div class="col-md-2">
                                    <select class="form-select eForm-select" name="type">
                                        @foreach(['multiple_choice' => 'MCQ', 'multiple_select' => 'Multiple Select', 'numeric' => 'Numerical Answer', 'matching' => 'Matching', 'ordering' => 'Ordering / Sequencing', 'true_false' => 'True/False', 'fill_blank' => 'Fill Blank', 'short_answer' => 'Short Answer', 'essay' => 'Essay'] as $typeValue => $label)
                                            <option value="{{ $typeValue }}" {{ $question->normalized_type === str_replace('multiple_choice', 'multiple_choice', $typeValue) ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-1"><input class="form-control eForm-control" name="marks" value="{{ $question->marks }}" type="number" min="1" max="127" step="1" required></div>
                                @php($questionKey = \App\Support\OnlineExams\AnswerKey::forQuestion($question))
                                <div class="col-md-2">
                                    <select class="form-select eForm-select" name="correct_ans" data-edit-correct="mcq">
                                        <option value="">Correct option</option>
                                        @foreach(['a','b','c','d'] as $opt)<option value="{{ $opt }}" @selected($questionKey === $opt)>Option {{ strtoupper($opt) }}</option>@endforeach
                                    </select>
                                    <select class="form-select eForm-select" name="correct_answer_tf" data-edit-correct="true_false">
                                        <option value="">Correct answer</option><option value="true" @selected($questionKey === 'true')>True</option><option value="false" @selected($questionKey === 'false')>False</option>
                                    </select>
                                    <input class="form-control eForm-control" name="correct_ans" data-edit-correct="text" value="{{ $question->correct_ans }}" placeholder="Correct answer">
                                </div>
                                <div class="col-md-1"><input class="form-control eForm-control" name="option_a" value="{{ $question->option_a }}" placeholder="A"></div>
                                <div class="col-md-1"><input class="form-control eForm-control" name="option_b" value="{{ $question->option_b }}" placeholder="B"></div>
                                <div class="col-md-1"><input class="form-control eForm-control" name="option_c" value="{{ $question->option_c }}" placeholder="C"></div>
                                <div class="col-md-1"><input class="form-control eForm-control" name="option_d" value="{{ $question->option_d }}" placeholder="D"></div>
                                <div class="col-md-12" data-edit-structured="multiple_select" style="display:none"><div class="row g-2">@foreach(['a','b','c','d','e','f','g','h'] as $opt)<div class="col-md-6"><div class="input-group"><span class="input-group-text"><input type="checkbox" name="correct_option_ids[]" value="{{ $opt }}" @checked(in_array($opt, (array)($structuredMarking['correct_option_ids'] ?? []), true))></span><input class="form-control" name="structured_options[{{ $loop->index }}][id]" value="{{ data_get($structuredConfig, 'options.'.$loop->index.'.id', $opt) }}" readonly><input class="form-control" name="structured_options[{{ $loop->index }}][label]" value="{{ data_get($structuredConfig, 'options.'.$loop->index.'.label') }}" placeholder="Option {{ strtoupper($opt) }}"></div></div>@endforeach</div><small class="text-muted">Select at least two correct options.</small></div>
                                <div class="col-md-6" data-edit-structured="numeric" style="display:none"><input class="form-control eForm-control" name="numeric_target" value="{{ $structuredMarking['target'] ?? '' }}" placeholder="Target numerical answer"><input class="form-control eForm-control mt-1" name="numeric_tolerance" value="{{ $structuredMarking['tolerance'] ?? 0 }}" placeholder="Absolute tolerance"></div>
                                <div class="col-12" data-edit-structured="fill_blank" style="display:none"><small class="text-muted">Use [[blank_id]] placeholders in the prompt. Accepted answers are comma-separated.</small><div class="row g-2 mt-1">@foreach(range(1,4) as $blankNumber)@php($blankId = data_get($structuredConfig, 'blanks.'.($blankNumber-1).'.id', 'blank_'.$blankNumber))<div class="col-md-6"><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][id]" value="{{ $blankId }}" readonly><input class="form-control mt-1" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" value="{{ implode(',', (array) data_get($structuredMarking, 'blanks.'.$blankId.'.accepted_answers', [])) }}" placeholder="Accepted answers, comma-separated"></div>@endforeach</div><label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="case_sensitive" value="1" @checked(($structuredMarking['case_sensitive'] ?? false))> Case-sensitive marking</label><input type="hidden" name="trim_whitespace" value="1"></div>
                                <div class="col-12" data-edit-structured="matching" style="display:none"><div class="row g-2">@foreach(($structuredConfig['left_items'] ?? []) as $idx => $left)<div class="col-md-6"><div class="input-group"><input class="form-control" name="structured_pairs[{{ $idx }}][left_id]" value="{{ $left['id'] }}" readonly><input class="form-control" name="structured_pairs[{{ $idx }}][left_text]" value="{{ $left['text'] }}"><input class="form-control" name="structured_pairs[{{ $idx }}][right_id]" value="{{ data_get($structuredConfig,'right_items.'.$idx.'.id') }}" readonly><input class="form-control" name="structured_pairs[{{ $idx }}][right_text]" value="{{ data_get($structuredConfig,'right_items.'.$idx.'.text') }}"></div></div>@endforeach</div><small class="text-muted">Existing IDs are preserved while text is edited.</small></div>
                                <div class="col-12" data-edit-structured="ordering" style="display:none"><div class="row g-2">@foreach(($structuredConfig['items'] ?? []) as $idx => $item)<div class="col-md-6"><div class="input-group"><span class="input-group-text">{{ $idx+1 }}</span><input class="form-control" name="structured_order_items[{{ $idx }}][id]" value="{{ $item['id'] }}" readonly><input class="form-control" name="structured_order_items[{{ $idx }}][text]" value="{{ $item['text'] }}"></div></div>@endforeach</div><small class="text-muted">Enter items in the correct sequence; IDs remain stable.</small></div>
                                <div class="col-md-12"><button class="eBtn eBtn-sm eBtn-primary" type="submit">{{ get_phrase('Save') }}</button></div>
                            </form>
                            <script>
                            (function(){var f=document.currentScript.previousElementSibling,t=f.querySelector('[name=type]'),cs=f.querySelectorAll('[data-edit-correct]'),ss=f.querySelectorAll('[data-edit-structured]');function s(){var v=t.value==='multiple_choice'?'mcq':(t.value==='true_false'?'true_false':(t.value==='multiple_select'?'multiple_select':(t.value==='numeric'?'numeric':(t.value==='matching'?'matching':(t.value==='ordering'?'ordering':'text')))));cs.forEach(function(c){var on=c.dataset.editCorrect===v;c.style.display=on?'block':'none';c.disabled=!on;});ss.forEach(function(c){var on=c.dataset.editStructured===v;c.style.display=on?'block':'none';c.querySelectorAll('input').forEach(function(i){i.disabled=!on;});});}t.addEventListener('change',s);s();})();
                            </script>
                        </td>
                    </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="text-muted text-center">{{ get_phrase('No questions yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(!$structureLocked && $questions->count() > 1)
            <button form="reorderQuestionsForm" class="eBtn eBtn-secondary" type="submit">{{ get_phrase('Save Current Order') }}</button>
        @endif
    </div>
</div>
@endsection
