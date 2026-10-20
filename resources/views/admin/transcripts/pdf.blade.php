<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ get_phrase('Academic Transcript') }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2933;
            font-size: 12px;
            margin: 28px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1a3a6b;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            letter-spacing: 0.5px;
            color: #1a3a6b;
        }
        .header p {
            margin: 4px 0 0;
            color: #52606d;
            font-size: 11px;
        }
        .meta-table,
        .marks-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            vertical-align: top;
            width: 50%;
        }
        .info-block {
            border: 1px solid #d9e2ec;
            padding: 12px;
            border-radius: 4px;
        }
        .info-block table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-block td {
            padding: 3px 0;
            font-size: 11px;
        }
        .label {
            color: #52606d;
            width: 120px;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #1a3a6b;
            border-bottom: 1px solid #bcccdc;
            padding-bottom: 5px;
            margin: 22px 0 10px;
        }
        .marks-table th,
        .marks-table td {
            border: 1px solid #d9e2ec;
            padding: 6px 8px;
        }
        .marks-table th {
            background: #f0f4f8;
            font-size: 11px;
            text-align: left;
        }
        .center {
            text-align: center;
        }
        .summary {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px solid #bcccdc;
        }
        .summary strong {
            color: #1a3a6b;
        }
        .empty-state {
            text-align: center;
            color: #7b8794;
            padding: 24px 0;
            border: 1px dashed #bcccdc;
            border-radius: 4px;
        }
        .footer {
            margin-top: 28px;
            border-top: 1px solid #bcccdc;
            padding-top: 10px;
            font-size: 10px;
            color: #52606d;
        }
    </style>
@include('shared.pdf_layout')
</head>
<body>
    <div class="header">
        <h1>{{ get_settings('system_title') ?: 'Institution' }}</h1>
        <p>{{ get_phrase('Official Academic Transcript') }}</p>
        <p>{{ get_phrase('Issued') }}: {{ date('d F Y') }}</p>
    </div>

    <table class="meta-table">
        <tr>
            <td style="padding-right: 8px;">
                <div class="info-block">
                    <table>
                        <tr><td class="label">{{ get_phrase('Full Name') }}:</td><td><strong>{{ $student->name }}</strong></td></tr>
                        <tr><td class="label">{{ get_phrase('Reg. Number') }}:</td><td>{{ $student->code ?: '-' }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Email') }}:</td><td>{{ $student->email ?: '-' }}</td></tr>
                    </table>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="info-block">
                    <table>
                        <tr><td class="label">{{ get_phrase('Programme') }}:</td><td><strong>{{ $programme?->name ?: '-' }}</strong></td></tr>
                        <tr><td class="label">{{ get_phrase('Level') }}:</td><td>{{ $programme?->level ?: '-' }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Intake') }}:</td><td>{{ $intakeSession?->name ?: '-' }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Class') }}:</td><td>{{ $classRoom?->name ?: '-' }}{{ $section ? ' / ' . $section->name : '' }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Year of Study') }}:</td><td>{{ $yearOfStudy ?: '-' }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Enrolled') }}:</td><td>{{ $enrollment?->created_at?->format('Y') ?: '-' }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    @php
        $hasRecords = false;
    @endphp

    @foreach ($exam_categories as $cat)
        @php
            $cat_marks = $gradebook[$cat->id] ?? null;
            $hasCategoryMarks = is_array($cat_marks) && !empty($cat_marks);
            $hasRecords = $hasRecords || $hasCategoryMarks;
        @endphp

        @if ($hasCategoryMarks)
            <div class="section-title">{{ $cat->name }}</div>
            <table class="marks-table">
                <thead>
                    <tr>
                        <th>{{ get_phrase('Subject') }}</th>
                        <th class="center">{{ get_phrase('Obtained') }}</th>
                        <th class="center">{{ get_phrase('Total') }}</th>
                        <th class="center">{{ get_phrase('%') }}</th>
                        <th class="center">{{ get_phrase('Grade') }}</th>
                        <th class="center">{{ get_phrase('GPA') }}</th>
                        <th class="center">{{ get_phrase('Remarks') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cat_marks as $subject_id => $mark)
                        @php
                            $subject = $subjects->where('id', $subject_id)->first();
                            $obtained = (float) ($mark['obtained'] ?? 0);
                            $total = (float) ($mark['total'] ?? 100);
                            $pct = $total > 0 ? round(($obtained / $total) * 100, 1) : 0;
                            $grade = null;
                            foreach ($grades as $candidate) {
                                if ($pct >= $candidate->mark_from && $pct <= $candidate->mark_upto) {
                                    $grade = $candidate;
                                    break;
                                }
                            }
                            $passed = $grade && $grade->mark_from >= ($subject?->pass_mark ?? 50);
                        @endphp
                        <tr>
                            <td>{{ $subject?->name ?: 'Subject #' . $subject_id }}</td>
                            <td class="center">{{ $obtained }}</td>
                            <td class="center">{{ $total }}</td>
                            <td class="center">{{ $pct }}%</td>
                            <td class="center">{{ $grade?->name ?: '-' }}</td>
                            <td class="center">{{ $grade?->gpa_points ?: '-' }}</td>
                            <td class="center">{{ $passed ? get_phrase('Pass') : get_phrase('Fail') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if (! $hasRecords)
        <div class="empty-state">{{ get_phrase('No academic records found for this student') }}</div>
    @endif

    <div class="summary">
        <strong>{{ get_phrase('Overall Average') }}:</strong> {{ $overall_percent }}%
        @if ($overall_grade)
            <span style="margin-left: 18px;"><strong>{{ get_phrase('Grade') }}:</strong> {{ $overall_grade->name }}</span>
            <span style="margin-left: 18px;"><strong>{{ get_phrase('GPA') }}:</strong> {{ $overall_grade->gpa_points }}</span>
            @if ($overall_grade->classification)
                <span style="margin-left: 18px;"><strong>{{ get_phrase('Classification') }}:</strong> {{ $overall_grade->classification }}</span>
            @endif
        @endif
    </div>

    <div class="footer">
        <div>{{ get_phrase('This is an official document issued by') }} {{ get_settings('system_title') }}</div>
        <div>{{ get_phrase('Generated') }}: {{ now()->format('d M Y H:i') }}</div>
    </div>
</body>
</html>
