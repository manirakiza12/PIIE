<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        h2 { text-align: center; margin-bottom: 4px; }
        .sub { text-align: center; color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; text-align: left; }
        th { background: #f5f5f5; }
    </style>
@include('shared.pdf_layout')
</head>
<body>
    <h2>{{ $title }}</h2>
    <div class="sub">{{ get_phrase('Staff List') }}</div>

    <table>
        <thead>
            <tr>
                <th>{{ get_phrase('Staff Number') }}</th>
                <th>{{ get_phrase('Name') }}</th>
                <th>{{ get_phrase('Email') }}</th>
                <th>{{ get_phrase('Department') }}</th>
                <th>{{ get_phrase('Designation') }}</th>
                <th>{{ get_phrase('Employment Type') }}</th>
                <th>{{ get_phrase('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($staffs as $staff)
            <tr>
                <td>{{ $staff->code ?? '-' }}</td>
                <td>{{ $staff->name }}</td>
                <td>{{ $staff->email }}</td>
                <td>{{ optional($staff->department)->name ?? '-' }}</td>
                <td>{{ optional($staff->designationRecord)->name ?? '-' }}</td>
                <td>{{ $staff->employment_type ?? '-' }}</td>
                <td>{{ ucfirst($staff->staff_status ?? 'active') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
