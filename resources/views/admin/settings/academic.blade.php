@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Grading Scales') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                <li>{{ get_phrase('Academics') }}</li>
                <li><a href="#">{{ get_phrase('Grading Scales') }}</a></li>
            </ul>
        </div>
    </div>
</div></div></div>



@if(session('success'))<div class="alert alert-success"><i class="bi bi-check-circle"></i> {{ session('success') }}</div>@endif

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="p-3 border-bottom"><strong>{{ get_phrase('Grading Scale') }}</strong>
        <span class="text-muted ms-2" style="font-size:11px">{{ get_phrase('Configure grade letter, marks range, GPA points and degree classification') }}</span>
    </div>
    <div class="p-4">
    <form action="{{ route('admin.settings.academic.save') }}" method="POST" id="gradeForm">
        @csrf
        <input type="hidden" name="_settings_section" value="grading">
        <div class="table-responsive">
            <table class="table eTable" style="font-size:12px" id="gradeTable">
                <thead>
                    <tr>
                        <th>{{ get_phrase('Grade') }}</th>
                        <th>{{ get_phrase('Mark From') }} (%)</th>
                        <th>{{ get_phrase('Mark Upto') }} (%)</th>
                        <th>{{ get_phrase('Grade Point') }}</th>
                        <th>{{ get_phrase('GPA Points') }}</th>
                        <th>{{ get_phrase('Classification') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="gradeRows">
                @foreach($grades as $i => $g)
                <tr>
                    <td><input type="text" name="grade[{{ $i }}][name]" class="form-control eForm-control" value="{{ $g->name }}" placeholder="A+" required></td>
                    <td><input type="number" name="grade[{{ $i }}][mark_from]" class="form-control eForm-control" value="{{ $g->mark_from }}" min="0" max="100" step="0.1"></td>
                    <td><input type="number" name="grade[{{ $i }}][mark_upto]" class="form-control eForm-control" value="{{ $g->mark_upto }}" min="0" max="100" step="0.1"></td>
                    <td><input type="number" name="grade[{{ $i }}][grade_point]" class="form-control eForm-control" value="{{ $g->grade_point }}" step="0.01" min="0" max="5"></td>
                    <td><input type="number" name="grade[{{ $i }}][gpa_points]" class="form-control eForm-control" value="{{ $g->gpa_points }}" step="0.01" min="0" max="5"></td>
                    <td><input type="text" name="grade[{{ $i }}][classification]" class="form-control eForm-control" value="{{ $g->classification }}" placeholder="e.g. First Class Honours"></td>
                    <td><button type="button" class="eBtn eBtn-sm eBtn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-3 align-items-center">
            <button type="button" class="eBtn eBtn-secondary" id="addGradeRow"><i class="bi bi-plus"></i> {{ get_phrase('Add Grade') }}</button>
            <button type="submit" class="eBtn eBtn-primary"><i class="bi bi-save"></i> {{ get_phrase('Save Grading Scale') }}</button>
        </div>

    </form>
    </div>
</div></div></div>

<script>
let gradeCount = {{ $grades->count() }};
document.getElementById('addGradeRow').addEventListener('click', function() {
    const tbody = document.getElementById('gradeRows');
    const i = gradeCount++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="grade[${i}][name]" class="form-control eForm-control" placeholder="A+" required></td>
        <td><input type="number" name="grade[${i}][mark_from]" class="form-control eForm-control" min="0" max="100" step="0.1"></td>
        <td><input type="number" name="grade[${i}][mark_upto]" class="form-control eForm-control" min="0" max="100" step="0.1"></td>
        <td><input type="number" name="grade[${i}][grade_point]" class="form-control eForm-control" step="0.01" min="0" max="5"></td>
        <td><input type="number" name="grade[${i}][gpa_points]" class="form-control eForm-control" step="0.01" min="0" max="5"></td>
        <td><input type="text" name="grade[${i}][classification]" class="form-control eForm-control" placeholder="e.g. First Class Honours"></td>
        <td><button type="button" class="eBtn eBtn-sm eBtn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>`;
    tbody.appendChild(tr);
});
</script>
@endsection
