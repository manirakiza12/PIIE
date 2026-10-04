@extends('teacher.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4>{{ get_phrase('Exam Preview') }}: {{ $exam->title }}</h4>
            <a class="export_btn" href="{{ route('teacher.online_exams.show', $exam->id) }}">{{ get_phrase('Back') }}</a>
        </div>
    </div>
</div>

<div class="eSection-wrap">
    <p class="piie-prose"><strong>{{ get_phrase('Instructions') }}:</strong> {!! $exam->proseInstructions() ?: '—' !!}</p>
    <p><strong>{{ get_phrase('Duration') }}:</strong> {{ $exam->duration_mins }} {{ get_phrase('minutes') }}</p>
    <hr>

    @foreach($questions as $index => $q)
        <div class="card mb-2">
            <div class="card-body">
                <div class="piie-prose"><strong>{{ $index + 1 }}.</strong> {!! $q->prosePrompt() !!}</div>
                <div class="small text-muted">{{ strtoupper($q->type) }} | {{ $q->marks }} {{ get_phrase('marks') }}</div>
                @if($q->type === 'mcq')
                    <ul class="mb-0">
                        @foreach(['option_a', 'option_b', 'option_c', 'option_d'] as $option)
                            @if(!empty($q->{$option}))<li>{{ $q->{$option} }}</li>@endif
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endsection
