@extends('admin.navigation')
@section('content')
<div class="container-fluid">
    <h4 class="mb-3">Import complete</h4>

    <div class="alert alert-success">
        <strong>{{ $created }}</strong> student(s) created.
        @if($failed) <strong class="text-danger">{{ count($failed) }}</strong> failed. @endif
    </div>

    @if($accounts)
    <div class="card mb-3">
        <div class="card-header">Created accounts</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Email</th><th>Student code</th></tr></thead>
                <tbody>
                @foreach($accounts as $a)
                    <tr><td>{{ $a[0] }}</td><td><code>{{ $a[1] }}</code></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            Portal passwords were generated and, if SMTP is configured, emailed to each student.
            They are not shown here or stored in plain text.
        </div>
    </div>
    @endif

    @if($failed)
    <div class="card mb-3">
        <div class="card-header text-danger">Failed rows</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Row</th><th>Problem</th></tr></thead>
                <tbody>
                @foreach($failed as $f)
                    <tr class="table-danger"><td>{{ $f['row'] }}</td><td>{{ $f['message'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <a href="{{ route('admin.student.import.index') }}" class="btn btn-outline-secondary">Import more</a>
</div>
@endsection