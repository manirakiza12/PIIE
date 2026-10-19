<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #222; }
        h2 { text-align: center; margin-bottom: 4px; }
        .sub { text-align: center; color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; }
        td.label { color: #666; width: 200px; }
    </style>
</head>
<body>
    <h2>{{ $student_details['school_name'] ?? '' }}</h2>
    <div class="sub">{{ get_phrase('Student Profile') }}</div>

    <table>
        <tr><td class="label">{{ get_phrase('Registration Number') }}</td><td><strong>{{ $student_details['code'] ?? '-' }}</strong></td></tr>
        <tr><td class="label">{{ get_phrase('Name') }}</td><td>{{ $student_details['name'] ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Email') }}</td><td>{{ $student_details['email'] ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Phone') }}</td><td>{{ $student_details['phone'] ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Gender') }}</td><td>{{ $student_details['gender'] ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Blood Group') }}</td><td>{{ strtoupper($student_details['blood_group'] ?? '') ?: '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Address') }}</td><td>{{ $student_details['address'] ?? '-' }}</td></tr>
        @if(academic_education_level(auth()->user()->school_id) !== 'tertiary')
        <tr><td class="label">{{ get_phrase('Class / Section') }}</td><td>{{ ($student_details['class_name'] ?? '-') . ' / ' . ($student_details['section_name'] ?? '-') }}</td></tr>
        @endif
        <tr><td class="label">{{ get_phrase('Programme') }}</td><td>{{ optional($profile?->programme)->name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Intake') }}</td><td>{{ optional($profile?->intakeSession)->name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Year of Study') }}</td><td>{{ $profile->year_of_study ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Affiliated University / School') }}</td><td>{{ $profile->affiliated_institution_name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Student ID at Affiliated Institution') }}</td><td>{{ $profile->affiliated_student_id ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Nationality') }}</td><td>{{ $profile->nationality ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('National ID/Passport') }}</td><td>{{ $profile->national_id_or_passport ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Next of Kin Address') }}</td><td>{{ $profile->next_of_kin_address ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Next of Kin Contact') }}</td><td>{{ $profile->next_of_kin_contact ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Status') }}</td><td>{{ ucfirst($profile->status ?? 'active') }}</td></tr>
    </table>
</body>
</html>
