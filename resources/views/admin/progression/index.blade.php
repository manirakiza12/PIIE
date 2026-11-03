@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('Student Academic Progression') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li aria-current="page">{{ get_phrase('Progression') }}</li>
            </ul>
        </div>
    </div>
</div>

<div class="eSection-wrap mt-3">
    <div class="table-responsive">
        <table class="table eTable align-middle">
            <thead>
                <tr>
                    <th>{{ get_phrase('Student') }}</th>
                    <th>{{ get_phrase('Registration No.') }}</th>
                    <th class="text-end">{{ get_phrase('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($students as $student)
                    <tr>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->code }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.progression.show', $student->id) }}" class="eBtn eBtn-black eBtn-sm">{{ get_phrase('Review') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {!! $students->links() !!}
</div>
@endsection