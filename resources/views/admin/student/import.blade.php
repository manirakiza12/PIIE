@extends('admin.navigation')
@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Import existing students</h4>
        <a href="{{ route('admin.student.import.template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Download CSV template
        </a>
    </div>

    <div class="alert alert-info">
        <strong>These students are already studying, so they are added directly as students.</strong>
        They do <em>not</em> go through admissions and are never asked for an application fee.
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post" action="{{ route('admin.student.import.preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="file">CSV file</label>
                    <input type="file" class="form-control @error('file') is-invalid @enderror"
                           id="file" name="file" accept=".csv,text/csv" required>
                    @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">Maximum 4 MB. The first row must be the header.</div>
                </div>
                <button class="btn btn-primary"><i class="bi bi-eye"></i> Preview</button>
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">Expected columns</div>
        <div class="card-body">
            <p class="mb-2">Required: <code>name</code>, <code>email</code>, <code>class_name</code>.</p>
            <p class="mb-2">Optional: <code>section_name</code>, <code>programme_name</code>,
                <code>department_name</code>, <code>year_of_study</code>, <code>nationality</code>,
                <code>national_id_or_passport</code>, <code>next_of_kin_contact</code>,
                <code>next_of_kin_address</code>, <code>status</code>.</p>
            <p class="mb-0 text-muted">Classes, programmes and departments are matched by name exactly as
                they appear in the admin screens. Every student gets a generated portal password.</p>
        </div>
    </div>
</div>
@endsection