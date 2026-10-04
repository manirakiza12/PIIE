@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Question Bank') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.online_exams.index') }}">{{ get_phrase('Online Exams') }}</a></li><li><a href="#">{{ get_phrase('Question Bank') }}</a></li></ul>
        </div>
        <div class="export-btn-area d-flex gap-2">
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.question_bank.import_modal') }}', '{{ get_phrase('Import Questions') }}')">{{ get_phrase('Import Questions') }}</a>
            <a href="{{ route('admin.question_bank.metadata') }}" class="export_btn">{{ get_phrase('Manage Topics & Tags') }}</a>
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.question_bank.modal') }}', '{{ get_phrase('Add to Bank') }}')">{{ get_phrase('Add Question') }}</a>
        </div>
    </div>
</div></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
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
<div class="row"><div class="col-12"><div class="eSection-wrap">
    <form method="GET" class="row g-2 mb-3"><div class="col-md-4"><input class="form-control" name="search" value="{{ $search }}" placeholder="Search question"></div><div class="col-md-3"><select class="form-select" name="subject_id"><option value="">{{ get_phrase('All courses') }}</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected($subjectId==$subject->id)>{{ $subject->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="programme_id"><option value="">{{ get_phrase('All programmes') }}</option>@foreach($programmes as $p)<option value="{{ $p->id }}" @selected($programmeId==$p->id)>{{ $p->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="session_id"><option value="">{{ get_phrase('All academic periods') }}</option>@foreach($sessions as $s)<option value="{{ $s->id }}" @selected($sessionId==$s->id)>{{ $s->session_title }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="topic_id"><option value="">{{ get_phrase('All topics') }}</option>@foreach($topics as $topic)<option value="{{ $topic->id }}" data-subject="{{ $topic->subject_id }}" @selected($topicId==$topic->id)>{{ $topic->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="subtopic_id"><option value="">{{ get_phrase('All subtopics') }}</option>@foreach($subtopics as $subtopic)<option value="{{ $subtopic->id }}" data-subject="{{ $subtopic->subject_id }}" data-parent="{{ $subtopic->parent_id }}" @selected($subtopicId==$subtopic->id)>{{ $subtopic->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="tag_id"><option value="">{{ get_phrase('All tags') }}</option>@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected($tagId==$tag->id)>{{ $tag->name }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="type"><option value="">{{ get_phrase('All types') }}</option>@foreach(['mcq','true_false','short','essay','fill_blank','multiple_select','numeric','matching','ordering'] as $questionType)<option value="{{ $questionType }}" @selected($type===$questionType)>{{ strtoupper(str_replace('_',' ',$questionType)) }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="difficulty"><option value="">{{ get_phrase('All difficulty') }}</option>@foreach(['easy','medium','hard'] as $level)<option value="{{ $level }}" @selected($difficulty===$level)>{{ ucfirst($level) }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select" name="status"><option value="">{{ get_phrase('All lifecycle states') }}</option>@foreach(['draft','active','retired','archived'] as $state)<option value="{{ $state }}" @selected($status===$state)>{{ ucfirst($state) }}</option>@endforeach</select></div><div class="col-md-2"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button></div></form>
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr><th>#</th><th>{{ get_phrase('Question') }}</th><th>{{ academic_term('subject', auth()->user()->school_id) }}</th><th>{{ get_phrase('Type') }}</th><th>{{ get_phrase('Difficulty') }}</th><th>{{ get_phrase('Marks') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
            <tbody>
            @forelse($questions as $i => $q)
            <tr>
                <td>{{ $questions->firstItem() + $i }}</td>
                {{-- Plain text, not markup: this cell TRUNCATES to 60 characters, and
         truncating HTML at an arbitrary offset yields a broken tag and a page
         full of stray angle brackets. `plainPrompt()` is the accessor for a
         truncated cell, and it preserves word boundaries so a cut does not join
         two words together. --}}
                    <td>{{ $q->plainPrompt(60) }}</td>
                <td>{{ optional($q->subject)->name ?? '—' }}</td>
                <td><span class="badge bg-primary">{{ strtoupper(str_replace('_', ' ', $q->normalized_type)) }}</span></td>
                <td><span class="badge bg-{{ $q->difficulty=='hard'?'danger':($q->difficulty=='medium'?'warning':'success') }}">{{ ucfirst($q->difficulty) }}</span></td>
                <td>{{ $q->marks }}</td>
                <td>
                    <a href="javascript:;" class="eBtn eBtn-sm eBtn-primary" onclick="rightModal('{{ route('admin.question_bank.modal', ['id' => $q->id]) }}', '{{ get_phrase('Edit Question') }}')"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="{{ route('admin.question_bank.delete', $q->id) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="eBtn eBtn-sm eBtn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">{{ get_phrase('Question bank is empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $questions->links() }}
</div></div></div>
@endsection
