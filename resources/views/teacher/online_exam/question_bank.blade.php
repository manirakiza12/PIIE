@extends('teacher.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center flex-wrap gr-15">
    <h4>{{ get_phrase('Question Bank') }}</h4>
    <div class="d-flex gap-2">
        <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('teacher.online_exams.question_bank.import_modal') }}', '{{ get_phrase('Import Questions') }}')">{{ get_phrase('Import Questions') }}</a>
        <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('teacher.online_exams.question_bank.modal') }}', '{{ get_phrase('Add to Bank') }}')">{{ get_phrase('Add Question') }}</a>
        <a class="export_btn" href="{{ route('teacher.online_exams.index') }}">{{ get_phrase('Back') }}</a>
    </div>
</div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if(session('import_warnings') && count(session('import_warnings')))
    <div class="alert alert-warning">
        <strong>{{ get_phrase('Warnings') }}:</strong>
        <ul class="mb-0">
            @foreach(session('import_warnings') as $w)<li>{{ $w }}</li>@endforeach
        </ul>
    </div>
@endif
@if(session('import_errors') && count(session('import_errors')))
    <div class="alert alert-danger">
        <strong>{{ get_phrase('Skipped rows') }}:</strong>
        <ul class="mb-0">
            @foreach(session('import_errors') as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="alert alert-info">
    {{ get_phrase('Teachers can create reusable questions only for subjects assigned to them. Exam publication and release remain controlled by the authorised exam owner.') }}
</div>

@if($canCreateBankQuestion)
<div class="eSection-wrap mb-3">
    <h6>{{ get_phrase('Add Reusable Question') }}</h6>
    @if($subjects->isEmpty())
        <p class="text-warning mb-0">{{ get_phrase('No assigned subjects are available. Ask an administrator to assign a class/subject before adding questions.') }}</p>
    @else
    <form method="POST" action="{{ route('teacher.online_exams.question_bank.store') }}" class="row g-2">
        @csrf
        <div class="col-md-8">
            {{-- A bank entry is authored ONCE and copied into many exams, so rich text here pays for itself at once: the same worked calculation is not retyped for every paper that uses it.

                 The filter is on the QuestionBank model's mutator, which matters more here than anywhere else in this feature: this table is SHARED and long-lived, and it is also rendered by the admin bank screens. An editor on this form with no filter attached would have turned a shared table into an injection surface that outlives any single exam.

                 `old('question')` is carried over from the form it replaces, so a failed save returns the lecturer their wording rather than an empty box. --}}
            <x-academic-editor
                name="question"
                :value="old('question')"
                :placeholder="get_phrase('Question')"
                :required="true"
                :rows="3"
                :height="260"
                help="Formatting, lists, tables and mathematical notation are kept. This question can be reused across assessments."
                testid="bank-question-editor" />
        </div>
        <div class="col-md-4"><select class="form-select eForm-select" name="subject_id" required><option value="">{{ get_phrase('Select assigned subject') }}</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected((string)old('subject_id') === (string)$subject->id)>{{ $subject->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">{{ academic_term('programme', auth()->user()->school_id) }}</label><select class="form-select eForm-select" name="programme_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}">{{ $programme->name }}</option>@endforeach</select></div><div class="col-md-4"><label class="form-label">{{ academic_term('session', auth()->user()->school_id) }}</label><select class="form-select eForm-select" name="session_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($sessions as $session)<option value="{{ $session->id }}">{{ $session->session_title }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">{{ get_phrase('Topic') }}</label><select class="form-select eForm-select" name="topic_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($topics as $topic)<option value="{{ $topic->id }}" data-subject="{{ $topic->subject_id }}">{{ $topic->name }}</option>@endforeach</select></div><div class="col-md-4"><label class="form-label">{{ get_phrase('Subtopic') }}</label><select class="form-select eForm-select" name="subtopic_id"><option value="">{{ get_phrase('Optional') }}</option>@foreach($subtopics as $subtopic)<option value="{{ $subtopic->id }}" data-subject="{{ $subtopic->subject_id }}" data-parent="{{ $subtopic->parent_id }}">{{ $subtopic->name }}</option>@endforeach</select></div><div class="col-md-4"><label class="form-label">{{ get_phrase('Tags') }}</label><select class="form-select eForm-select" name="tag_ids[]" multiple>@foreach($tags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><select class="form-select eForm-select" name="type" id="teacher_bank_type"><option value="mcq">Multiple Choice</option><option value="multiple_select">Multiple Select</option><option value="numeric">Numerical Answer</option><option value="fill_blank">Advanced Fill Blank</option><option value="true_false">True / False</option><option value="short">Short Answer</option><option value="essay">Essay</option></select></div>
        <div class="col-md-2"><input class="form-control eForm-control" type="number" name="marks" min="1" max="127" step="1" value="{{ old('marks', 1) }}" required></div>
        <div class="col-md-3"><select class="form-select eForm-select" name="difficulty"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></div><div class="col-md-3"><select class="form-select eForm-select" name="status"><option value="draft">Draft</option><option value="active" selected>Active</option><option value="retired">Retired</option><option value="archived">Archived</option></select></div>
        <div class="col-md-4" id="teacher_bank_correct_mcq"><select class="form-select eForm-select" name="correct_ans"><option value="">{{ get_phrase('Select correct option') }}</option><option value="a">Option A</option><option value="b">Option B</option><option value="c">Option C</option><option value="d">Option D</option></select></div>
        <div class="col-md-4" id="teacher_bank_correct_tf"><select class="form-select eForm-select" name="correct_answer_tf" disabled><option value="">{{ get_phrase('Select correct answer') }}</option><option value="true">True</option><option value="false">False</option></select></div>
        <div class="col-md-4" id="teacher_bank_correct_text"><input class="form-control eForm-control" name="correct_ans" placeholder="{{ get_phrase('Accepted answer') }}" disabled></div>
        <div class="col-md-3"><input class="form-control eForm-control" name="option_a" placeholder="Option A"></div><div class="col-md-3"><input class="form-control eForm-control" name="option_b" placeholder="Option B"></div><div class="col-md-3"><input class="form-control eForm-control" name="option_c" placeholder="Option C"></div><div class="col-md-3"><input class="form-control eForm-control" name="option_d" placeholder="Option D"></div>
        <div class="col-12" id="teacher_bank_multiple" style="display:none"><div class="row g-2">@foreach(['a','b','c','d','e','f','g','h'] as $opt)<div class="col-md-6"><div class="input-group"><span class="input-group-text"><input type="checkbox" name="correct_option_ids[]" value="{{ $opt }}"></span><input class="form-control structured-bank-id" name="structured_options[{{ $loop->index }}][id]" value="{{ $opt }}" readonly><input class="form-control structured-bank-label" name="structured_options[{{ $loop->index }}][label]" placeholder="Option {{ strtoupper($opt) }}"></div></div>@endforeach</div><small class="text-muted">Select at least two correct options.</small></div>
        <div class="col-md-6" id="teacher_bank_numeric" style="display:none"><input class="form-control eForm-control" name="numeric_target" placeholder="Target numerical answer"><input class="form-control eForm-control mt-1" name="numeric_tolerance" value="0" placeholder="Absolute tolerance"></div>
        <div class="col-12" id="teacher_bank_fill_blank" style="display:none"><small>Use [[blank_1]] placeholders in the question.</small><div class="row g-2">@foreach(range(1,4) as $blankNumber)<div class="col-md-6"><input class="form-control" name="structured_blanks[{{ $blankNumber-1 }}][id]" value="blank_{{ $blankNumber }}" readonly><input class="form-control mt-1" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" placeholder="Accepted answer"><input class="form-control mt-1" name="structured_blanks[{{ $blankNumber-1 }}][accepted_answers][]" placeholder="Alternative"></div>@endforeach</div><label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="case_sensitive" value="1"> Case-sensitive marking</label><input type="hidden" name="trim_whitespace" value="1"></div>
        <div class="col-12"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Save to Bank') }}</button></div>
    </form>
    <script>
    (function(){var t=document.getElementById('teacher_bank_type');function sync(){var v=t.value;document.getElementById('teacher_bank_correct_mcq').style.display=v==='mcq'?'block':'none';document.getElementById('teacher_bank_correct_tf').style.display=v==='true_false'?'block':'none';document.getElementById('teacher_bank_correct_text').style.display=(v==='short'||v==='essay')?'block':'none';document.getElementById('teacher_bank_multiple').style.display=v==='multiple_select'?'block':'none';document.getElementById('teacher_bank_numeric').style.display=v==='numeric'?'block':'none';document.getElementById('teacher_bank_fill_blank').style.display=v==='fill_blank'?'block':'none';document.querySelector('#teacher_bank_correct_mcq select').disabled=v!=='mcq';document.querySelector('#teacher_bank_correct_tf select').disabled=v!=='true_false';document.querySelector('#teacher_bank_correct_text input').disabled=!(v==='short'||v==='essay');}t.addEventListener('change',sync);sync();})();
    </script>
    @endif
</div>
@endif

<div class="eSection-wrap mb-3">
    <form method="GET" action="{{ route('teacher.online_exams.question_bank') }}" class="row g-2">
        <div class="col-md-4"><input class="form-control eForm-control" name="search" value="{{ $search }}" placeholder="{{ get_phrase('Search question') }}"></div>
        <div class="col-md-3">
            <select class="form-select eForm-select" name="subject_id">
                <option value="">{{ get_phrase('All subjects') }}</option>
                @foreach($subjects as $subject)
                    <option value="{{ $subject->id }}" {{ (string) $subjectId === (string) $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button></div><div class="col-md-3"><select class="form-select eForm-select" name="programme_id"><option value="">{{ get_phrase('All programmes') }}</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected($programmeId == $programme->id)>{{ $programme->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select eForm-select" name="session_id"><option value="">{{ get_phrase('All academic periods') }}</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected($sessionId == $session->id)>{{ $session->session_title }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select eForm-select" name="topic_id"><option value="">{{ get_phrase('All topics') }}</option>@foreach($topics as $topic)<option value="{{ $topic->id }}" data-subject="{{ $topic->subject_id }}" @selected($topicId == $topic->id)>{{ $topic->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select eForm-select" name="subtopic_id"><option value="">{{ get_phrase('All subtopics') }}</option>@foreach($subtopics as $subtopic)<option value="{{ $subtopic->id }}" data-subject="{{ $subtopic->subject_id }}" data-parent="{{ $subtopic->parent_id }}" @selected($subtopicId == $subtopic->id)>{{ $subtopic->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select eForm-select" name="tag_id"><option value="">{{ get_phrase('All tags') }}</option>@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected($tagId == $tag->id)>{{ $tag->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select eForm-select" name="status"><option value="">{{ get_phrase('All lifecycle states') }}</option>@foreach(['draft','active','retired','archived'] as $state)<option value="{{ $state }}" @selected($status === $state)>{{ ucfirst($state) }}</option>@endforeach</select></div>
    </form>
</div>

<div class="eSection-wrap">
    <table class="table eTable">
        <thead><tr><th>#</th><th>{{ get_phrase('Question') }}</th><th>{{ academic_term('subject', auth()->user()->school_id) }}</th><th>{{ get_phrase('Type') }}</th><th>{{ get_phrase('Marks') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
        <tbody>
        @forelse($questions as $i => $q)
            <tr>
                <td>{{ $questions->firstItem() + $i }}</td>
                <td class="piie-prose">{!! $q->prosePrompt() !!}</td>
                <td>{{ optional($q->subject)->name ?? '—' }}</td>
                <td>{{ strtoupper(str_replace('_', ' ', $q->normalized_type)) }}</td>
                <td>{{ $q->marks }}</td>
                <td>
                    @if((int) $q->created_by === (int) auth()->id())
                        <a href="{{ route('teacher.online_exams.question_bank.delete', $q->id) }}" class="eBtn eBtn-sm eBtn-danger" onclick="return confirm('{{ get_phrase('Delete?') }}')"><i class="bi bi-trash"></i></a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted">{{ get_phrase('No questions available') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $questions->links() }}
</div>
<script>
(function(){var course=document.querySelector('select[name="subject_id"]'),topic=document.querySelector('select[name="topic_id"]'),subtopic=document.querySelector('select[name="subtopic_id"]'); if(!course||!topic||!subtopic)return; function sync(){var subject=course.value, selectedTopic=topic.value; Array.from(topic.options).forEach(function(o){o.hidden=o.value!==''&&o.dataset.subject&&o.dataset.subject!==subject;}); Array.from(subtopic.options).forEach(function(o){o.hidden=o.value!==''&&((o.dataset.subject&&o.dataset.subject!==subject)||(o.dataset.parent&&o.dataset.parent!==selectedTopic));}); if(topic.selectedOptions[0]&&topic.selectedOptions[0].hidden)topic.value=''; if(subtopic.selectedOptions[0]&&subtopic.selectedOptions[0].hidden)subtopic.value='';} course.addEventListener('change',sync); topic.addEventListener('change',sync); sync();})();
</script>
@endsection
