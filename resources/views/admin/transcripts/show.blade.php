@extends('admin.navigation')
@section('content')

@if(session('print'))
<script>window.onload = function() { window.printReport('transcript-doc'); }</script>
@endif

<div class="mainSection-title d-print-none"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Academic Transcript') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('admin.transcripts.index') }}">{{ get_phrase('Transcripts') }}</a></li>
                <li><a href="#">{{ $student->name }}</a></li>
            </ul>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.transcripts.pdf', $student->id) }}" class="eBtn eBtn-primary">
                <i class="bi bi-printer"></i> {{ get_phrase('Print / Download') }}
            </a>
            <a href="{{ route('admin.transcripts.index') }}" class="eBtn eBtn-secondary">
                <i class="bi bi-arrow-left"></i> {{ get_phrase('Back') }}
            </a>
        </div>
    </div>
</div></div></div>

{{-- TRANSCRIPT DOCUMENT --}}
<div class="row">
<div class="col-12">
<div class="eSection-wrap" id="transcript-doc" style="max-width:800px;margin:0 auto">
    {{-- Header --}}
    <div class="text-center py-4 border-bottom">
        <div style="font-size:22px;font-weight:800;color:#1a3a6b;letter-spacing:1px">
            {{ get_settings('system_title') ?? 'Institution' }}
        </div>
        <div style="font-size:12px;color:#6c757d;margin-top:2px">{{ get_phrase('Official Academic Transcript') }}</div>
        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Issued') }}: {{ date('d F Y') }}</div>
    </div>

    {{-- Student Info --}}
    <div class="p-4 border-bottom">
        <div class="row">
            <div class="col-md-6">
                <table style="font-size:13px;width:100%">
                    <tr><td style="color:#6c757d;padding:3px 0;width:150px">{{ get_phrase('Full Name') }}:</td><td><strong>{{ $student->name }}</strong></td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Reg. Number') }}:</td><td>{{ $student->code ?? '—' }}</td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Email') }}:</td><td>{{ $student->email }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table style="font-size:13px;width:100%">
                    <tr><td style="color:#6c757d;padding:3px 0;width:150px">{{ get_phrase('Programme') }}:</td><td><strong>{{ $programme?->name ?? '—' }}</strong></td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Level') }}:</td><td>{{ $programme?->level ?? '—' }}</td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Intake') }}:</td><td>{{ $intakeSession?->name ?? '—' }}</td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Class') }}:</td><td>{{ $classRoom?->name ?? '—' }}{{ $section ? ' / ' . $section->name : '' }}</td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Year of Study') }}:</td><td>{{ $yearOfStudy ?? '—' }}</td></tr>
                    <tr><td style="color:#6c757d;padding:3px 0">{{ get_phrase('Enrolled') }}:</td><td>{{ $enrollment?->created_at?->format('Y') ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Academic Records --}}
    <div class="p-4">
        @if($subjects->isEmpty() || $exam_categories->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-journal-x" style="font-size:2rem"></i>
            <div class="mt-2">{{ get_phrase('No academic records found for this student') }}</div>
        </div>
        @else

        @foreach($exam_categories as $cat)
        @php $cat_marks = $gradebook[$cat->id] ?? null; @endphp
        @if($cat_marks)
        <div class="mb-4">
            <div style="font-size:13px;font-weight:700;color:#1a3a6b;padding:6px 0;border-bottom:2px solid #1a3a6b;margin-bottom:10px">
                {{ $cat->name }}
            </div>
            <table class="table table-sm" style="font-size:12px">
                <thead>
                    <tr style="background:#f8f9fa">
                        <th>{{ get_phrase('Subject') }}</th>
                        <th class="text-center">{{ get_phrase('Obtained') }}</th>
                        <th class="text-center">{{ get_phrase('Total') }}</th>
                        <th class="text-center">{{ get_phrase('%') }}</th>
                        <th class="text-center">{{ get_phrase('Grade') }}</th>
                        <th class="text-center">{{ get_phrase('GPA') }}</th>
                        <th class="text-center">{{ get_phrase('Remarks') }}</th>
                    </tr>
                </thead>
                <tbody>
                @if(is_array($cat_marks))
                @foreach($cat_marks as $subject_id => $m)
                @php
                    $subject = $subjects->where('id', $subject_id)->first();
                    $obtained = (float)($m['obtained'] ?? 0);
                    $total    = (float)($m['total'] ?? 100);
                    $pct      = $total > 0 ? round($obtained/$total*100, 1) : 0;
                    $gr = null;
                    foreach($grades as $g) { if($pct >= $g->mark_from && $pct <= $g->mark_upto) { $gr=$g; break; } }
                @endphp
                <tr>
                    <td>{{ $subject?->name ?? "Subject #{$subject_id}" }}</td>
                    <td class="text-center">{{ $obtained }}</td>
                    <td class="text-center">{{ $total }}</td>
                    <td class="text-center">{{ $pct }}%</td>
                    <td class="text-center"><strong>{{ $gr?->name ?? '—' }}</strong></td>
                    <td class="text-center">{{ $gr?->gpa_points ?? '—' }}</td>
                    <td class="text-center">
                        @if($gr && $gr->mark_from >= ($subject?->pass_mark ?? 50))
                        <span class="badge bg-success">{{ get_phrase('Pass') }}</span>
                        @else
                        <span class="badge bg-danger">{{ get_phrase('Fail') }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @else
                <tr><td colspan="7" class="text-center text-muted">—</td></tr>
                @endif
                </tbody>
            </table>
        </div>
        @endif
        @endforeach

        {{-- Overall Summary --}}
        <div class="border-top pt-3 mt-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <span style="font-size:12px;color:#6c757d">{{ get_phrase('Overall Average') }}:</span>
                    <strong style="font-size:18px;color:#1a3a6b;margin-left:8px">{{ $overall_percent }}%</strong>
                </div>
                @if($overall_grade)
                <div class="d-flex gap-3">
                    <div class="text-center">
                        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Grade') }}</div>
                        <div style="font-size:22px;font-weight:800;color:#1a3a6b">{{ $overall_grade->name }}</div>
                    </div>
                    <div class="text-center">
                        <div style="font-size:11px;color:#6c757d">{{ get_phrase('GPA') }}</div>
                        <div style="font-size:22px;font-weight:800;color:#c8860a">{{ $overall_grade->gpa_points }}</div>
                    </div>
                    @if($overall_grade->classification)
                    <div class="text-center">
                        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Classification') }}</div>
                        <div style="font-size:14px;font-weight:700;color:#198754">{{ $overall_grade->classification }}</div>
                    </div>
                    @endif
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Footer --}}
    <div class="border-top p-4 d-flex justify-content-between" style="font-size:11px;color:#6c757d">
        <div>{{ get_phrase('This is an official document issued by') }} {{ get_settings('system_title') }}</div>
        <div>{{ get_phrase('Generated') }}: {{ now()->format('d M Y H:i') }}</div>
    </div>
</div>
</div></div>

<style>
@media print {
    .sidebar, #tb, .mainSection-title, .d-print-none { display: none !important; }
    #main { margin: 0 !important; padding: 0 !important; }
    #transcript-doc { max-width: 100% !important; box-shadow: none !important; border: none !important; }
}
</style>
@endsection
