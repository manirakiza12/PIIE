<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ get_phrase('ID Verification') }} — {{ $school->title ?? get_phrase('School') }}</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #f3f5f8; margin: 0; padding: 32px 16px; }
        .verify-card { max-width: 380px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(17,24,39,.1); }
        .verify-header { padding: 20px; text-align: center; color: #fff; }
        .verify-header.is-active { background: linear-gradient(135deg, #0f6e6e, #114f52); }
        .verify-header.is-inactive { background: linear-gradient(135deg, #e5484d, #9c2a2e); }
        .verify-status { font-size: 13px; font-weight: 700; letter-spacing: .06em; }
        .verify-photo-wrap { text-align: center; margin-top: -34px; }
        .verify-photo { width: 90px; height: 90px; border-radius: 50%; border: 4px solid #fff; object-fit: cover; background: #eceef2; }
        .verify-body { padding: 10px 24px 24px 24px; text-align: center; }
        .verify-name { font-size: 18px; font-weight: 700; color: #181c32; margin: 8px 0 2px 0; }
        .verify-code { font-size: 12px; color: #6a7180; margin-bottom: 16px; }
        .verify-fields { text-align: left; font-size: 13px; color: #303546; }
        .verify-field-row { padding: 6px 0; border-bottom: 1px dashed #e5e7eb; }
        .verify-field-label { display: inline-block; width: 100px; color: #8a8f9c; font-weight: 600; }
        .verify-school { margin-top: 16px; font-size: 11px; color: #8a8f9c; }
    </style>
@include('shared.typography')
</head>
<body>
    <div class="verify-card">
        <div class="verify-header {{ $isActive ? 'is-active' : 'is-inactive' }}">
            <div class="verify-status">
                @if($isActive)
                    ✓ {{ get_phrase(($staffCard ?? false) ? 'VALID STAFF ID' : 'VALID STUDENT ID') }}
                @else
                    ✕ {{ get_phrase('THIS ACCOUNT IS NOT ACTIVE') }}
                @endif
            </div>
        </div>
        <div class="verify-photo-wrap">
            <img class="verify-photo" src="{{ $studentDetails['photo'] }}" alt="">
        </div>
        <div class="verify-body">
            <div class="verify-name">{{ $studentDetails['name'] }}</div>
            <div class="verify-code">{{ null_checker($studentDetails['code']) }}</div>

            <div class="verify-fields">
                @if($staffCard ?? false)
                    <div class="verify-field-row"><span class="verify-field-label">{{ get_phrase('Position') }}</span>{{ $staffPosition }}</div>
                    @if($staffDepartment)
                        <div class="verify-field-row"><span class="verify-field-label">{{ get_phrase('Department') }}</span>{{ $staffDepartment }}</div>
                    @endif
                @elseif($programme)
                    <div class="verify-field-row"><span class="verify-field-label">{{ get_phrase('Programme') }}</span>{{ $programme->name }}</div>
                @else
                    <div class="verify-field-row"><span class="verify-field-label">{{ get_phrase('Class') }}</span>{{ empty($studentDetails['class_name']) ? get_phrase('Not assigned') : $studentDetails['class_name'] }}</div>
                @endif
            </div>

            <div class="verify-school">{{ $school->title ?? '' }}</div>
        </div>
    </div>
</body>
</html>
