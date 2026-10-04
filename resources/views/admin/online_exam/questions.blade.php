@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Questions') }}: {{ $exam->title }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.online_exams.index') }}">{{ get_phrase('Online Exams') }}</a></li><li><a href="#">{{ get_phrase('Questions') }}</a></li></ul>
        </div>
        <div class="export-btn-area">
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.online_exams.question_modal', $exam->id) }}', '{{ get_phrase('Add Question') }}')">{{ get_phrase('Add Question') }}</a>
        </div>
    </div>
</div></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="alert {{ $questionMarksTotal === (int) $exam->total_marks ? 'alert-success' : 'alert-warning' }}">
    <strong>{{ get_phrase('Exam Total Marks') }}: {{ $exam->total_marks }}</strong>
    &nbsp;|&nbsp; {{ get_phrase('Question Marks') }}: {{ $questionMarksTotal }}
    &nbsp;|&nbsp; {{ get_phrase('Remaining') }}: {{ (int) $exam->total_marks - $questionMarksTotal }}
    @if($questionMarksTotal < (int) $exam->total_marks)<br>{{ get_phrase('Additional marks/questions are required before publication.') }}
    @elseif($questionMarksTotal > (int) $exam->total_marks)<br>{{ get_phrase('Question marks exceed the exam total before publication.') }}
    @else<br>{{ get_phrase('Mark allocation is complete.') }}@endif
</div>
@if(isset($bank) && $bank->count())
<div class="card mb-3"><div class="card-body">
    <h6>{{ get_phrase('Import From Question Bank') }}</h6>
    <form method="POST" action="{{ route('admin.online_exams.question_bank.import', $exam->id) }}">@csrf
        <div class="row g-2">
            @foreach($bank as $bankQuestion)
            <div class="col-md-6"><label class="border rounded p-2 d-block"><input type="checkbox" name="question_bank_ids[]" value="{{ $bankQuestion->id }}" class="form-check-input me-2">{{ Str::limit($bankQuestion->question, 100) }} <small class="text-muted">({{ $bankQuestion->marks }} {{ get_phrase('marks') }})</small></label></div>
            @endforeach
        </div>
        <button class="eBtn eBtn-sm eBtn-primary mt-3" type="submit">{{ get_phrase('Import selected questions') }}</button>
    </form>
</div></div>
@endif
<div class="row"><div class="col-12"><div class="eSection-wrap">
    @forelse($questions as $i => $q)
    <div class="card mb-3 online-exam-question-card">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <div class="piie-prose"><strong>Q{{ $i+1 }}.</strong> {!! $q->prosePrompt() !!}
                    <span class="badge bg-{{ in_array($q->normalized_type, ['multiple_choice','multiple_select']) ? 'primary' : ($q->normalized_type=='true_false' ? 'warning' : 'info') }} ms-2">{{ strtoupper(str_replace('_', ' ', $q->normalized_type)) }}</span>
                    <span class="badge bg-secondary ms-1">{{ $q->marks }} mark(s)</span>
                </div>
                <form method="POST" action="{{ route('admin.online_exams.questions.destroy', $q->id) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="eBtn eBtn-sm eBtn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
            </div>
            @if(in_array($q->normalized_type, ['multiple_choice','multiple_select']))
            <div class="row mt-2">
                @if($q->normalized_type === 'multiple_select')
                    @foreach(\App\Support\OnlineExams\QuestionContract::publicProjection($q)['options'] as $option)
                        <div class="col-6"><small>{{ strtoupper($option['id']) }}: {{ $option['label'] }}</small></div>
                    @endforeach
                @else
                    @foreach(['a','b','c','d'] as $opt)
                        @if($q->{'option_'.$opt})
                        <div class="col-6"><small class="text-{{ $q->correct_answer==$opt ? 'success fw-bold' : 'muted' }}">{{ strtoupper($opt) }}: {{ $q->{'option_'.$opt} }}</small></div>
                        @endif
                    @endforeach
                @endif
            </div>
            @elseif($q->type=='true_false')
            <div class="mt-1"><small class="text-success">{{ get_phrase('Answer') }}: {{ ucfirst($q->correct_answer) }}</small></div>
            @elseif($q->normalized_type === 'fill_blank' && $q->question_schema_version !== null)
            <div class="mt-1"><small class="text-muted">{{ get_phrase('Advanced Fill Blank') }}: {{ implode(', ', array_column(\App\Support\OnlineExams\QuestionContract::publicProjection($q)['blanks'], 'id')) }}</small></div>
            @endif
            @if(!$exam->isStructurallyLocked())
            <details class="mt-3"><summary class="btn btn-sm btn-outline-secondary">{{ get_phrase('Edit question') }}</summary>
                <form method="POST" action="{{ route('admin.online_exams.questions.update', $q->id) }}" class="row g-2 mt-2">
                    @csrf
                    <input type="hidden" name="question_id" value="{{ $q->id }}">
                                    <div class="col-12">
                    {{-- The same reason as the lecturer's inline edit: an `<input>`
                         cannot hold markup, so re-saving a formatted question through
                         it would replace the formatting with escaped tag text. --}}
                    <x-academic-editor
                        name="question"
                        :id="'admin-edit-question-'.$q->id"
                        :value="$q->getAttributes()['question'] ?? ''"
                        :required="true"
                        :height="220"
                        help="Formatting, lists, tables and mathematical notation are kept."
                        testid="exam-question-edit-editor" />
                </div>
                    @php($structuredConfig = $q->question_schema_version ? json_decode($q->question_config, true) : [])
                    @php($structuredMarking = $q->question_schema_version ? json_decode($q->marking_config, true) : [])
                    <div class="col-md-2"><select class="form-select" name="type"><option value="mcq" @selected($q->type === 'mcq' && !in_array(($structuredConfig['type'] ?? null), ['multiple_select','matching','ordering'], true))>MCQ</option><option value="multiple_select" @selected(($structuredConfig['type'] ?? null) === 'multiple_select')>Multiple Select</option><option value="numeric" @selected(($structuredConfig['type'] ?? null) === 'numeric')>Numerical Answer</option><option value="matching" @selected(($structuredConfig['type'] ?? null) === 'matching')>Matching</option><option value="ordering" @selected(($structuredConfig['type'] ?? null) === 'ordering')>Ordering / Sequencing</option><option value="true_false" @selected($q->type === 'true_false')>True/False</option><option value="short" @selected($q->type === 'short' && ($structuredConfig['type'] ?? null) !== 'numeric')>Short</option><option value="essay" @selected($q->type === 'essay')>Essay</option></select></div>
                    <div class="col-md-1"><input class="form-control" type="number" name="marks" min="1" max="127" step="1" value="{{ $q->marks }}" required></div>
                    @php($adminQuestionKey = \App\Support\OnlineExams\AnswerKey::forQuestion($q))
                    <div class="col-md-3">
                        <select class="form-select" name="correct_ans" data-admin-correct="mcq">
                            <option value="">Correct option</option>@foreach(['a','b','c','d'] as $opt)<option value="{{ $opt }}" @selected($adminQuestionKey === $opt)>Option {{ strtoupper($opt) }}</option>@endforeach
                        </select>
                        <select class="form-select" name="correct_ans" data-admin-correct="true_false">
                            <option value="true" @selected($adminQuestionKey === 'true')>True</option><option value="false" @selected($adminQuestionKey === 'false')>False</option>
                        </select>
                        <input class="form-control" name="correct_ans" data-admin-correct="text" value="{{ $q->correct_ans }}" placeholder="Correct answer">
                    </div>
                    <div class="col-12" data-admin-structured="multiple_select" style="display:none"><div class="row g-2">@foreach(['a','b','c','d','e','f','g','h'] as $opt)<div class="col-md-6"><div class="input-group"><span class="input-group-text"><input type="checkbox" name="correct_option_ids[]" value="{{ $opt }}" @checked(in_array($opt, (array)($structuredMarking['correct_option_ids'] ?? []), true))></span><input class="form-control" name="structured_options[{{ $loop->index }}][id]" value="{{ data_get($structuredConfig, 'options.'.$loop->index.'.id', $opt) }}" readonly><input class="form-control" name="structured_options[{{ $loop->index }}][label]" value="{{ data_get($structuredConfig, 'options.'.$loop->index.'.label') }}" placeholder="Option {{ strtoupper($opt) }}"></div></div>@endforeach</div><small class="text-muted">Select at least two correct options.</small></div>
                    <div class="col-md-6" data-admin-structured="numeric" style="display:none"><input class="form-control" name="numeric_target" value="{{ $structuredMarking['target'] ?? '' }}" placeholder="Target numerical answer"><input class="form-control mt-1" name="numeric_tolerance" value="{{ $structuredMarking['tolerance'] ?? 0 }}" placeholder="Absolute tolerance"></div>
                    <div class="col-12" data-admin-structured="fill_blank" style="display:none"><small class="text-muted">Use [[blank_id]] placeholders in the prompt. Accepted answers are comma-separated.</small><div class="row g-2 mt-1">@foreach(range(1,4) as $blankNumber)@php($blankId = data_get($structuredConfig, 'blanks.'.($blankNumber-1).'.id', 'blank_'.$blankNumber))<div class="col-md-6"><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][id]" value="{{ $blankId }}" readonly><input class="form-control mt-1" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" value="{{ implode(',', (array) data_get($structuredMarking, 'blanks.'.$blankId.'.accepted_answers', [])) }}" placeholder="Accepted answers"></div>@endforeach</div><label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="case_sensitive" value="1" @checked(($structuredMarking['case_sensitive'] ?? false))> Case-sensitive marking</label><input type="hidden" name="trim_whitespace" value="1"></div>
                    <div class="col-md-12"><button class="eBtn eBtn-sm eBtn-primary" type="submit">{{ get_phrase('Save') }}</button></div>
                </form>
                <script>
                (function(){var f=document.currentScript.previousElementSibling,t=f.querySelector('[name=type]'),cs=f.querySelectorAll('[data-admin-correct]'),ss=f.querySelectorAll('[data-admin-structured]');function s(){var v=t.value==='mcq'?'mcq':(t.value==='true_false'?'true_false':(t.value==='multiple_select'?'multiple_select':(t.value==='numeric'?'numeric':'text')));cs.forEach(function(c){var on=c.dataset.adminCorrect===v;c.style.display=on?'block':'none';c.disabled=!on;});ss.forEach(function(c){var on=c.dataset.adminStructured===v;c.style.display=on?'block':'none';c.querySelectorAll('input').forEach(function(i){i.disabled=!on;});});}t.addEventListener('change',s);s();})();
                </script>
            </details>
            @endif
        </div>
    </div>
    @empty
    <div class="text-center text-muted py-4">{{ get_phrase('No questions added yet') }}</div>
    @endforelse
</div></div></div>
@endsection
