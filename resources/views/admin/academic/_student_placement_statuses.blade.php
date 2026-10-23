<section class="eSection-wrap mb-4" aria-label="Student placement status">
    <h5>Find every student and their placement</h5>
    <p>Admin &gt; Students lists accounts. A profile programme, intake or fee invoice does not establish academic placement. The placement dropdown includes only same-school students with an institution profile, an enabled account and no active placement.</p>
    <form method="GET" action="{{ url()->current() }}" class="d-flex flex-wrap gap-2 mb-3">
        <label>Find student <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Name, email, registration number or ID"></label>
        <label>Source <select name="source" class="form-select"><option value="">All students</option><option value="existing" @selected(request('source') === 'existing')>Add / Import Existing Student</option></select></label>
        <label>Placement <select name="placement_status" class="form-select"><option value="">All statuses</option><option value="unplaced" @selected(request('placement_status') === 'unplaced')>No active placement</option><option value="assigned" @selected(request('placement_status') === 'assigned')>Active placement</option></select></label>
        <button class="btn btn-primary align-self-end">Find</button><a class="btn btn-outline-secondary align-self-end" href="{{ url()->current() }}">Show all students</a>
    </form>
    <p class="text-muted">The existing-student marker covers Add and Import Existing Student together. Older unmarked accounts remain visible under All students. Intake, fee session and Academic Year are separate records.</p>
    <div class="table-responsive"><table class="table eTable align-middle"><thead><tr><th>Student / source</th><th>Profile details (not placement)</th><th>Academic placement</th><th>Dropdown eligibility / next step</th></tr></thead><tbody>
    @forelse($studentStatuses as $status)
        <tr><td>{{ $status->name }}<br>{{ $status->code }} · ID {{ $status->id }}<br><small>{{ $status->email }}<br>{{ $status->was_existing_at_portal_creation ? 'Added / imported as existing student' : 'Source not marked as existing student' }}</small></td>
        <td>{{ $status->profile_programme ?: 'No profile programme' }}<br>Profile year: {{ $status->profile_year ?: 'Not recorded' }}<br>Intake: {{ $status->intake_name ?: 'Not recorded' }}</td>
        <td>
            @if($status->placement_id)
                <strong>{{ $status->active_placements > 1 ? 'Multiple active placements — review required' : ((!$status->programme_name || !$status->version || !$status->programme_year || !$status->academic_year) ? 'Incomplete active placement — review required' : 'Assigned') }}</strong><br>
                Programme: {{ $status->programme_name ?: 'Missing / invalid link' }}<br>Curriculum: {{ $status->version ?: 'Missing / invalid link' }}<br>Programme Year: {{ $status->programme_year ?: 'Missing / invalid link' }}<br>Entry Academic Year: {{ $status->academic_year ?: 'Missing / invalid link' }}<br>
            @else<strong>No active academic placement</strong><br>@endif
            Group linked to placement: {{ $status->group_name ?: 'None (optional for direct placement)' }} {{ $status->group_status ? '(' . $status->group_status . ')' : '' }}<br>
            Latest legacy enrollment: {{ $status->class_name ?: 'No class' }} / {{ $status->section_name ?: 'No section' }}; session: {{ $status->legacy_session ?: 'Not recorded' }}<br>
            <small>{{ $status->historical_placements }} historical placement(s); {{ $status->confirmed_registrations }} confirmed course registration(s) across all years. Placement alone does not grant a teaching roster place.</small>
        </td>
        <td>{{ app(\App\Support\Academic\StudentPlacementDirectory::class)->reason($status) }}<br>
            @if($status->placement_id)<a href="{{ route('admin.academic.placements.index', ['student_id' => $status->id]) }}">View placement history</a>
            @elseif($status->account_status !== 'disable' && $status->profile_id)<a href="{{ route('admin.academic.direct_placement', ['student_id' => $status->id]) }}">Place this student</a>@endif
        </td></tr>
    @empty<tr><td colspan="4">No students match these filters. Try All students or another registration number.</td></tr>@endforelse
    </tbody></table></div>{{ $studentStatuses->links() }}
</section>
