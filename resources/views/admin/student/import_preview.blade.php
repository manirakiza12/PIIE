@extends('admin.navigation')
@section('content')
<div class="container-fluid">
    <h4 class="mb-3">Import preview &mdash; nothing has been saved yet</h4>

    <div class="alert alert-secondary">
        <strong>{{ $total }}</strong> row(s) read &middot;
        <strong class="text-success">{{ count($importable) }}</strong> ready to import &middot;
        <strong class="text-danger">{{ count($errors) }}</strong> with problems
    </div>

    @if($errors)
    <div class="card mb-3">
        <div class="card-header text-danger">Rows that will be skipped</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Row</th><th>Name</th><th>Email</th><th>Problem</th></tr></thead>
                <tbody>
                @foreach($errors as $e)
                    <tr class="table-danger">
                        <td>{{ $e['line'] }}</td>
                        <td>{{ $e['name'] }}</td>
                        <td>{{ $e['email'] ?: '—' }}</td>
                        <td>{{ implode('; ', $e['reasons']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($ready)
    <div class="card mb-3">
        <div class="card-header text-success">Rows that will be created</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Row</th><th>Name</th><th>Email</th></tr></thead>
                <tbody>
                @foreach($ready as $r)
                    <tr><td>{{ $r['line'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['email'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <form method="post" action="{{ route('admin.student.import.run') }}" onsubmit="return confirm('Create {{ count($importable) }} student account(s)? This cannot be undone.');">
        @csrf
        <button class="btn btn-success"><i class="bi bi-check2-circle"></i>
            Import {{ count($importable) }} student(s)</button>
        <a href="{{ route('admin.student.import.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
    @else
    <a href="{{ route('admin.student.import.index') }}" class="btn btn-outline-secondary">Back</a>
    @endif
</div>
@endsection