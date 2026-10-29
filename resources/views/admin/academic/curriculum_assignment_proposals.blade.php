@extends('admin.navigation')
@section('content')
@php
    $actor = auth()->user();
    $manage = $actor->hasPermission('academic.curriculum_assignment.manage');
    $approve = $actor->hasPermission('academic.curriculum_assignment.approve');
    $label = function ($table, $id) use ($options) {
        $row = $options[$table]->firstWhere('id', $id);
        if (!$row) return 'Unavailable reference #'.$id;
        $name = $row->name ?? $row->label ?? $row->version ?? '#'.$id;
        if ($table === 'curricula') $name = ($options['programmes']->firstWhere('id', $row->programme_id)->name ?? 'Programme').' — '.$row->version.' ('.$row->status.')';
        if ($table === 'curriculum_stages') $name = ($options['curricula']->firstWhere('id', $row->curriculum_id)->version ?? 'Curriculum').' — '.$name;
        return $name;
    };
@endphp
<div class="mainSection-title"><h4>Curriculum Assignment</h4><p>Prepare an explicit applicability mapping, obtain Registrar approval, then propose and review each student's initial placement. Every placement requires a separate manual Apply action.</p></div>
<div class="alert alert-warning">{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="eSection-wrap mb-4"><h5>1. Applicability mappings</h5><p>Mappings apply to one programme and intake. Drafts never authorize placement. Approved mappings cannot be edited; revoke and replace when academic context changes.</p>
@if($manage)
<details class="mb-3"><summary class="btn btn-outline-primary">Draft mapping</summary>
<form method="POST" action="{{ route('admin.curriculum_assignment.mapping.store') }}" class="row g-3 mt-1">@csrf
@foreach(['programme_id'=>['programmes','Programme'], 'intake_session_id'=>['intake_sessions','Admissions Intake'], 'curriculum_id'=>['curricula','Curriculum version'], 'entry_academic_year_id'=>['academic_years','Entry Academic Year'], 'entry_curriculum_stage_id'=>['curriculum_stages','Entry Programme Year']] as $field => [$table,$caption])
<div class="col-md-6"><label class="form-label" for="mapping-{{ $field }}">{{ $caption }}</label><select required class="form-select" id="mapping-{{ $field }}" name="{{ $field }}"><option value="">Choose {{ $caption }}</option>@foreach($options[$table] as $row)<option value="{{ $row->id }}" @selected(old($field) == $row->id)>{{ $label($table,$row->id) }}</option>@endforeach</select></div>
@endforeach
<div class="col-12"><label class="form-label" for="mapping-reason">Approved policy / Registrar reference and scope</label><textarea required maxlength="2000" class="form-control" id="mapping-reason" name="reason">{{ old('reason') }}</textarea></div>
<div class="col-12"><button class="btn btn-primary">Save draft mapping</button></div></form></details>
@endif
<div class="table-responsive"><table class="table"><thead><tr><th>Programme / intake</th><th>Curriculum / entry</th><th>Authority and status</th><th>Actions</th></tr></thead><tbody>
@forelse($mappings as $mapping)<tr><td>{{ $label('programmes',$mapping->programme_id) }}<br>{{ $label('intake_sessions',$mapping->intake_session_id) }}</td><td>{{ $label('curricula',$mapping->curriculum_id) }}<br>{{ $label('curriculum_stages',$mapping->entry_curriculum_stage_id) }}<br>{{ $label('academic_years',$mapping->entry_academic_year_id) }}</td><td><strong>{{ ucfirst($mapping->status) }}</strong><br>{{ $mapping->reason }}<br><small>Mapping #{{ $mapping->id }} · Drafted by staff #{{ $mapping->created_by }}@if($mapping->approved_at)<br>Approved by staff #{{ $mapping->approved_by }} at {{ $mapping->approved_at }}@endif @if($mapping->revoked_at)<br>Revoked by staff #{{ $mapping->revoked_by }}: {{ $mapping->revocation_reason }}@endif</small></td><td>
@if($approve && $mapping->status === 'draft')<form method="POST" action="{{ route('admin.curriculum_assignment.mapping.approve',$mapping->id) }}">@csrf<button class="btn btn-sm btn-primary">Approve applicability</button></form>@endif
@if($approve && $mapping->status === 'approved')<form method="POST" action="{{ route('admin.curriculum_assignment.mapping.revoke',$mapping->id) }}">@csrf<label class="form-label" for="revoke-{{ $mapping->id }}">Revocation reason</label><textarea required maxlength="2000" class="form-control" id="revoke-{{ $mapping->id }}" name="reason"></textarea><button class="btn btn-sm btn-outline-danger mt-2">Revoke mapping</button></form>@endif
</td></tr>@empty<tr><td colspan="4">No applicability mappings.</td></tr>@endforelse</tbody></table></div>{{ $mappings->links() }}</section>

@if($manage)<section class="eSection-wrap mb-4"><h5>2. Propose initial placement</h5><p>Only linked new admissions are eligible. Review ordinary-entry evidence, including transfer and credit-recognition information. Existing placement or registration history requires the established manual workflow.</p>
<form method="GET" action="{{ route('admin.curriculum_assignment.index') }}" class="d-flex gap-2 mb-3"><label class="visually-hidden" for="admission-search">Find admission</label><input class="form-control" id="admission-search" name="search" value="{{ request('search') }}" placeholder="Student name, registration or application number"><button class="btn btn-outline-secondary">Search admissions</button></form>
@foreach($admissions as $admission)<details class="mb-3"><summary>{{ $admission->name }} · {{ $admission->code }} · {{ $admission->app_number }}</summary><form method="POST" action="{{ route('admin.curriculum_assignment.propose') }}">@csrf<input type="hidden" name="admission_id" value="{{ $admission->id }}"><label class="form-label" for="evidence-{{ $admission->id }}">Ordinary initial-entry evidence reviewed; transfer / advanced-entry / credit-recognition checks</label><textarea required maxlength="2000" class="form-control" id="evidence-{{ $admission->id }}" name="ordinary_entry_evidence"></textarea><button class="btn btn-outline-primary mt-2">Generate proposal for review</button></form></details>@endforeach
{{ $admissions->links() }}</section>@endif

<section class="eSection-wrap mb-4"><h5>3. Registrar proposal review and application</h5>
@forelse($proposals as $proposal)
@php($snapshot = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR))
<article class="border rounded p-3 mb-3"><h6>{{ $proposal->student_name }} · {{ $proposal->student_code }} · {{ ucfirst($proposal->status) }}</h6>
<p>{{ $snapshot['academic']['programme']['name'] }} · {{ $snapshot['academic']['intake']['name'] }} · Curriculum {{ $snapshot['academic']['curriculum']['version'] }} · {{ $snapshot['academic']['stage']['label'] }} · {{ $snapshot['academic']['year']['label'] }}</p>
<p><strong>Reviewed entry evidence:</strong> {{ $proposal->ordinary_entry_evidence }}</p>
<p class="small">Proposal #{{ $proposal->id }} · Mapping #{{ $proposal->mapping_id }} · Proposed by staff #{{ $proposal->proposed_by }}@if($proposal->reviewed_at)<br>Reviewed by staff #{{ $proposal->reviewed_by }} at {{ $proposal->reviewed_at }}: {{ $proposal->review_reason }}@endif @if($proposal->placement_id)<br>Applied by staff #{{ $proposal->applied_by }} at {{ $proposal->applied_at }} · <a href="{{ route('admin.academic.placements.index',['student_id'=>$proposal->student_id]) }}">Placement #{{ $proposal->placement_id }}</a>@endif</p>
@if($approve && $proposal->status === 'pending')<form method="POST" action="{{ route('admin.curriculum_assignment.review',$proposal->id) }}">@csrf<label class="form-label" for="review-reason-{{ $proposal->id }}">Registrar decision reason</label><textarea required maxlength="2000" class="form-control" id="review-reason-{{ $proposal->id }}" name="reason"></textarea><div class="form-check my-2"><input class="form-check-input" type="checkbox" name="acknowledge_enrolment" value="1" id="review-ack-{{ $proposal->id }}"><label class="form-check-label" for="review-ack-{{ $proposal->id }}">{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</label></div><button name="decision" value="approve" class="btn btn-primary">Approve proposal</button> <button name="decision" value="reject" class="btn btn-outline-danger">Reject proposal</button></form>@endif
@if($approve && $actor->hasPermission('academic.programme_cohort.membership') && $proposal->status === 'approved')<form method="POST" action="{{ route('admin.curriculum_assignment.apply',$proposal->id) }}">@csrf<div class="form-check my-2"><input required class="form-check-input" type="checkbox" name="acknowledge_enrolment" value="1" id="apply-ack-{{ $proposal->id }}"><label class="form-check-label" for="apply-ack-{{ $proposal->id }}">{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</label></div><button class="btn btn-primary">Apply approved placement</button></form>@endif
</article>@empty<p>No proposals.</p>@endforelse{{ $proposals->links() }}</section>

<section class="eSection-wrap"><h5>Registrar exceptions</h5>@forelse($exceptions as $exception)<div class="border-bottom py-2"><strong>{{ $exception->code }}</strong>: {{ $exception->reason }}<br><small>{{ $exception->owner }} · {{ $exception->workflow }} #{{ $exception->subject_id }} · Observed {{ $exception->occurrences }} time(s)</small></div>@empty<p>No unresolved curriculum exceptions.</p>@endforelse{{ $exceptions->links() }}</section>
@endsection
