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
@include('shared.pdf_layout')
</head>
<body>
    <?php
        $info = json_decode($staff->user_information ?? '') ?: (object) [];
        $school = \App\Models\School::find($staff->school_id);
    ?>
    <h2>{{ $school->name ?? '' }}</h2>
    <div class="sub">{{ get_phrase('Staff Profile') }}</div>

    <table>
        <tr><td class="label">{{ get_phrase('Staff Number') }}</td><td><strong>{{ $staff->code ?? '-' }}</strong></td></tr>
        <tr><td class="label">{{ get_phrase('Name') }}</td><td>{{ $staff->name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Email') }}</td><td>{{ $staff->email ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Phone') }}</td><td>{{ $info->phone ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Gender') }}</td><td>{{ $info->gender ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Address') }}</td><td>{{ $info->address ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Department') }}</td><td>{{ optional($staff->department)->name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Designation') }}</td><td>{{ optional($staff->designationRecord)->name ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Employment Type') }}</td><td>{{ $staff->employment_type ?? '-' }}</td></tr>
        <tr><td class="label">{{ get_phrase('Staff Status') }}</td><td>{{ ucfirst($staff->staff_status ?? 'active') }}</td></tr>
        <tr><td class="label">{{ get_phrase('Account Status') }}</td><td>{{ $staff->account_status == 'disable' ? get_phrase('Disabled') : get_phrase('Enabled') }}</td></tr>
    </table>
</body>
</html>
