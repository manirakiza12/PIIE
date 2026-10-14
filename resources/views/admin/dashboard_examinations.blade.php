@extends('admin.navigation')

@section('content')
@php
    $schoolId = auth()->user()->school_id;
    $totalExams = DB::table('exams')->where('school_id', $schoolId)->count();
    $upcomingExams = DB::table('exams')->where('school_id', $schoolId)
        ->where('starting_time', '>=', now()->timestamp)->count();
    $questionBankCount = DB::table('question_banks')->where('school_id', $schoolId)->count();
    $gradedResults = DB::table('online_exam_submissions')->where('school_id', $schoolId)->whereIn('status', [\App\Models\OnlineExamSubmission::STATUS_FINALIZED, \App\Models\OnlineExamSubmission::STATUS_RESULT_PUBLISHED])->count();
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4 class="title">{{ get_phrase('Examinations Officer Dashboard') }}</h4>
                    <ul class="breadcrumb-link">
                        <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Dashboard') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-journal-richtext fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $totalExams }}</div>
                    <div class="text-muted small">{{ get_phrase('Total Exams') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3">
                    <i class="bi bi-calendar-event-fill fs-3 text-success"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $upcomingExams }}</div>
                    <div class="text-muted small">{{ get_phrase('Upcoming Exams') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info bg-opacity-10 p-3">
                    <i class="bi bi-question-circle-fill fs-3 text-info"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $questionBankCount }}</div>
                    <div class="text-muted small">{{ get_phrase('Question Bank') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                    <i class="bi bi-patch-check-fill fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $gradedResults }}</div>
                    <div class="text-muted small">{{ get_phrase('Graded Submissions') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">{{ get_phrase('Quick Actions') }}</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.online_exams.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-laptop me-1"></i>{{ get_phrase('Online Exams') }}
                    </a>
                    <a href="{{ route('admin.offline_exam') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-journal-text me-1"></i>{{ get_phrase('Exams') }}
                    </a>
                    <a href="{{ route('admin.question_bank.index') }}" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-question-square me-1"></i>{{ get_phrase('Question Bank') }}
                    </a>
                    <a href="{{ route('admin.gradebook') }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-card-list me-1"></i>{{ get_phrase('Gradebook') }}
                    </a>
                    <a href="{{ route('admin.transcripts.index') }}" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-file-earmark-text me-1"></i>{{ get_phrase('Transcripts') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
