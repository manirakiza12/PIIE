@extends('admin.navigation')
@section('page_styles')
<link rel="stylesheet" href="{{ asset('assets/css/academic-ui.css') }}?v=20261112-1">
@endsection
@section('content')
@php
    $actor = auth()->user();
    $canManage = $actor->hasPermission('academic.curriculum_assignment.manage');
    $canApprove = $actor->hasPermission('academic.curriculum_assignment.approve');
    $canView = $actor->hasPermission('academic.curriculum_assignment.view');
    $canPlace = $actor->hasPermission('academic.programme_cohort.membership');

    $label = function ($table, $id) use ($options) {
        $row = $options[$table]->firstWhere('id', $id);
        if (!$row) return 'Unavailable reference #'.$id;
        $name = $row->name ?? $row->label ?? $row->version ?? '#'.$id;
        if ($table === 'curricula') $name = ($options['programmes']->firstWhere('id', $row->programme_id)->name ?? 'Programme').' — '.$row->version.' ('.$row->status.')';
        if ($table === 'curriculum_stages') $name = ($options['curricula']->firstWhere('id', $row->curriculum_id)->version ?? 'Curriculum').' — '.$name;
        return $name;
    };

    $statusBadge = function ($status) {
        return match ($status) {
            'draft' => '<span class="badge badge-compact bg-secondary">Draft</span>',
            'approved' => '<span class="badge badge-compact bg-success">Approved</span>',
            'revoked' => '<span class="badge badge-compact bg-danger">Revoked</span>',
            default => '<span class="badge badge-compact bg-light text-dark">'.ucfirst($status).'</span>',
        };
    };

    $proposalStatusBadge = function ($status) {
        return match ($status) {
            'pending' => '<span class="badge badge-compact bg-warning text-dark">Pending Review</span>',
            'approved' => '<span class="badge badge-compact bg-primary">Approved</span>',
            'rejected' => '<span class="badge badge-compact bg-danger">Rejected</span>',
            'applied' => '<span class="badge badge-compact bg-success">Applied</span>',
            default => '<span class="badge badge-compact bg-light text-dark">'.ucfirst($status).'</span>',
        };
    };
@endphp

<div class="mainSection-title">
    <h4>Curriculum Assignment</h4>
    <p>Define curriculum applicability for programmes and intakes, obtain Registrar approval, then propose and review each student's initial placement. Every placement requires a separate manual Apply action.</p>
</div>

<div class="alert alert-warning">{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0">
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<!-- ============================================================
     STAGE 1: CURRICULUM ASSIGNMENTS (Applicability Mappings)
     ============================================================ -->
<section class="curriculum-section">
    <header class="curriculum-section-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5>1. Curriculum Assignments</h5>
            <p>Assignments link a programme and intake to a specific curriculum version, entry year, and programme year. Draft assignments never authorize placement. Approved assignments are immutable; revoke and replace when academic context changes.</p>
        </div>
        @if($canManage)
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
            <i class="bi bi-plus-lg me-1"></i> Create Assignment
        </button>
        @endif
    </header>

    <div class="curriculum-section-body">
        <div class="table-responsive table-responsive-curriculum">
            <table class="table curriculum-assignment-table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="col-programme">Programme / Intake</th>
                        <th class="col-curriculum">Curriculum / Entry</th>
                        <th class="col-year">Academic Year</th>
                        <th class="col-status">Status</th>
                        <th class="col-approved">Approved By</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mappings as $mapping)
                    <tr>
                        <td class="col-programme">
                            <div class="truncate-cell" title="{{ $label('programmes',$mapping->programme_id) }} · {{ $label('intake_sessions',$mapping->intake_session_id) }}">
                                <strong>{{ $label('programmes',$mapping->programme_id) }}</strong>
                                <div class="text-muted small">{{ $label('intake_sessions',$mapping->intake_session_id) }}</div>
                            </div>
                        </td>
                        <td class="col-curriculum">
                            <div class="truncate-cell" title="{{ $label('curricula',$mapping->curriculum_id) }} · {{ $label('curriculum_stages',$mapping->entry_curriculum_stage_id) }} · {{ $label('academic_years',$mapping->entry_academic_year_id) }}">
                                <strong>{{ $label('curricula',$mapping->curriculum_id) }}</strong>
                                <div class="text-muted small">
                                    {{ $label('curriculum_stages',$mapping->entry_curriculum_stage_id) }} ·
                                    {{ $label('academic_years',$mapping->entry_academic_year_id) }}
                                </div>
                            </div>
                        </td>
                        <td class="col-year">
                            <div class="truncate-cell" title="{{ $label('academic_years',$mapping->entry_academic_year_id) }}">
                                {{ $label('academic_years',$mapping->entry_academic_year_id) }}
                            </div>
                        </td>
                        <td class="col-status">
                            {!! $statusBadge($mapping->status) !!}
                        </td>
                        <td class="col-approved">
                            @if($mapping->approved_by)
                                <div class="truncate-cell" title="Staff #{{ $mapping->approved_by }} at {{ $mapping->approved_at }}">
                                    Staff #{{ $mapping->approved_by }}
                                    <div class="text-muted small">{{ \Carbon\Carbon::parse($mapping->approved_at)->format('d M Y H:i') }}</div>
                                </div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="col-actions">
                            <div class="dropdown adminTable-action">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle table-action-btn-2"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        data-bs-display="static"
                                        aria-expanded="false"
                                        aria-label="Actions for assignment #{{ $mapping->id }}">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <!-- View Details - always available for users with view permission -->
                                    @if($canView)
                                    <li>
                                        <button class="dropdown-item" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#viewAssignmentModal"
                                                data-assignment-id="{{ $mapping->id }}"
                                                data-assignment-data="{{ json_encode([
                                                    'id' => $mapping->id,
                                                    'programme' => $label('programmes',$mapping->programme_id),
                                                    'intake' => $label('intake_sessions',$mapping->intake_session_id),
                                                    'curriculum' => $label('curricula',$mapping->curriculum_id),
                                                    'stage' => $label('curriculum_stages',$mapping->entry_curriculum_stage_id),
                                                    'year' => $label('academic_years',$mapping->entry_academic_year_id),
                                                    'status' => $mapping->status,
                                                    'reason' => $mapping->reason,
                                                    'created_by' => $mapping->created_by,
                                                    'approved_by' => $mapping->approved_by,
                                                    'approved_at' => $mapping->approved_at,
                                                    'revoked_by' => $mapping->revoked_by,
                                                    'revoked_at' => $mapping->revoked_at,
                                                    'revocation_reason' => $mapping->revocation_reason,
                                                    'created_at' => $mapping->created_at,
                                                    'updated_at' => $mapping->updated_at,
                                                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                            <i class="bi bi-eye me-2"></i> View Details
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    @endif

                                    <!-- Edit Assignment - only for draft, only for manage permission -->
                                    @if($canManage && $mapping->status === 'draft')
                                    <li>
                                        <button class="dropdown-item" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editAssignmentModal"
                                                data-assignment-id="{{ $mapping->id }}"
                                                data-assignment-data="{{ json_encode([
                                                    'id' => $mapping->id,
                                                    'programme_id' => $mapping->programme_id,
                                                    'intake_session_id' => $mapping->intake_session_id,
                                                    'curriculum_id' => $mapping->curriculum_id,
                                                    'entry_academic_year_id' => $mapping->entry_academic_year_id,
                                                    'entry_curriculum_stage_id' => $mapping->entry_curriculum_stage_id,
                                                    'reason' => $mapping->reason,
                                                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                            <i class="bi bi-pencil me-2"></i> Edit Assignment
                                        </button>
                                    </li>
                                    @endif

                                    <!-- Approve Assignment - only for draft, only for approve permission -->
                                    @if($canApprove && $mapping->status === 'draft')
                                    <li>
                                        <form class="dropdown-item-form" method="POST" action="{{ route('admin.curriculum_assignment.mapping.approve', $mapping->id) }}">
                                            @csrf
                                            <button type="submit" class="dropdown-item text-success"
                                                    onclick="return confirm('Approve this assignment? This action cannot be undone; approved assignments are immutable.')">
                                                <i class="bi bi-check-circle me-2"></i> Approve Assignment
                                            </button>
                                        </form>
                                    </li>
                                    @endif

                                    <!-- Revoke Assignment - only for approved, only for approve permission -->
                                    @if($canApprove && $mapping->status === 'approved')
                                    <li>
                                        <button class="dropdown-item text-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#revokeAssignmentModal"
                                                data-assignment-id="{{ $mapping->id }}">
                                            <i class="bi bi-x-circle me-2"></i> Revoke Assignment
                                        </button>
                                    </li>
                                    @endif

                                    <!-- Delete Assignment - only for draft, only for manage permission -->
                                    @if($canManage && $mapping->status === 'draft')
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form class="dropdown-item-form" method="POST" action="{{ route('admin.curriculum_assignment.mapping.store') }}">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="mapping_id" value="{{ $mapping->id }}">
                                            <button type="submit" class="dropdown-item text-danger"
                                                    onclick="return confirm('Permanently delete this draft assignment? This cannot be undone.')">
                                                <i class="bi bi-trash me-2"></i> Delete Assignment
                                            </button>
                                        </form>
                                    </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6">
                            <div class="curriculum-empty">
                                <i class="bi bi-person-check"></i>
                                <h6>No curriculum assignments</h6>
                                <p class="mb-0">Create your first assignment to define curriculum applicability for a programme and intake.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $mappings->links() }}
    </div>
</section>

<!-- ============================================================
     STAGE 2: PROPOSE INITIAL PLACEMENT
     ============================================================ -->
@if($canManage)
<section class="curriculum-section">
    <header class="curriculum-section-header">
        <h5>2. Propose Initial Placement</h5>
        <p>Search for linked new admissions (accepted/enrolled) to generate placement proposals. Only admissions with exactly one approved curriculum assignment are eligible. Existing placement or registration history requires the established manual workflow.</p>
    </header>

    <div class="curriculum-section-body">
        <form method="GET" action="{{ route('admin.curriculum_assignment.index') }}" class="row g-2 mb-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label visually-hidden" for="admission-search">Find admission</label>
                <input type="text" class="form-control form-control-sm" id="admission-search" name="search"
                       value="{{ request('search') }}" placeholder="Student name, registration or application number">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-search me-1"></i> Search Admissions
                </button>
            </div>
        </form>

        @if($admissions->isNotEmpty())
        <div class="table-responsive table-responsive-curriculum">
            <table class="table placement-table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 30%; min-width: 200px;">Student / Admission</th>
                        <th style="width: 25%; min-width: 180px;">Programme / Intake</th>
                        <th style="width: 20%; min-width: 150px;">Approved Assignment</th>
                        <th style="width: 15%; min-width: 120px;">Status</th>
                        <th style="width: 10%; min-width: 100px; white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($admissions as $admission)
                    @php
                        $approvedMappings = collect($options['mappings_approved'] ?? []);
                        $approvedMapping = $approvedMappings->firstWhere('programme_id', $admission->programme_id);
                        if ($approvedMapping && $approvedMapping->intake_session_id !== $admission->intake_session_id) {
                            $approvedMapping = null;
                        }
                    @endphp
                    <tr>
                        <td>
                            <div class="truncate-cell" title="{{ $admission->name }} ({{ $admission->code }}) - {{ $admission->app_number }}">
                                <strong>{{ $admission->name }}</strong>
                                <div class="text-muted small">{{ $admission->code }} · {{ $admission->app_number }}</div>
                            </div>
                        </td>
                        <td>
                            <div class="truncate-cell" title="{{ $label('programmes',$admission->programme_id) }} / {{ $label('intake_sessions',$admission->intake_session_id) }}">
                                {{ $label('programmes',$admission->programme_id) }}
                                <div class="text-muted small">{{ $label('intake_sessions',$admission->intake_session_id) }}</div>
                            </div>
                        </td>
                        <td>
                            @if($approvedMapping)
                            <div class="truncate-cell text-success" title="{{ $label('curricula',$approvedMapping->curriculum_id) }}">
                                <i class="bi bi-check-circle me-1"></i> {{ $label('curricula',$approvedMapping->curriculum_id) }}
                            </div>
                            @else
                            <span class="text-muted">No approved assignment</span>
                            @endif
                        </td>
                        <td>
                            @if($approvedMapping)
                            <span class="badge badge-compact bg-success">Ready</span>
                            @else
                            <span class="badge badge-compact bg-warning text-dark">No Assignment</span>
                            @endif
                        </td>
                        <td>
                            <div class="dropdown adminTable-action">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle table-action-btn-2"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        data-bs-display="static"
                                        aria-expanded="false"
                                        aria-label="Actions for admission #{{ $admission->id }}">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @if($approvedMapping)
                                    <li>
                                        <form class="dropdown-item-form" method="POST" action="{{ route('admin.curriculum_assignment.propose') }}">
                                            @csrf
                                            <input type="hidden" name="admission_id" value="{{ $admission->id }}">
                                            <button type="button" class="dropdown-item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#proposePlacementModal"
                                                    data-admission-id="{{ $admission->id }}"
                                                    data-admission-name="{{ $admission->name }}"
                                                    data-admission-code="{{ $admission->code }}"
                                                    data-mapping-id="{{ $approvedMapping->id }}"
                                                    data-mapping-data="{{ json_encode([
                                                        'programme' => $label('programmes',$approvedMapping->programme_id),
                                                        'intake' => $label('intake_sessions',$approvedMapping->intake_session_id),
                                                        'curriculum' => $label('curricula',$approvedMapping->curriculum_id),
                                                        'stage' => $label('curriculum_stages',$approvedMapping->entry_curriculum_stage_id),
                                                        'year' => $label('academic_years',$approvedMapping->entry_academic_year_id),
                                                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                                <i class="bi bi-person-plus me-2"></i> Propose Placement
                                            </button>
                                        </form>
                                    </li>
                                    @else
                                    <li><span class="dropdown-item-text text-muted">No approved assignment available</span></li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $admissions->links() }}
        @else
        <div class="curriculum-empty">
            <i class="bi bi-search"></i>
            <h6>No matching admissions</h6>
            <p class="mb-0">Search for accepted or enrolled admissions to propose placements.</p>
        </div>
        @endif
    </div>
</section>
@endif

<!-- ============================================================
     STAGE 3: REGISTRAR PROPOSAL REVIEW
     ============================================================ -->
<section class="curriculum-section">
    <header class="curriculum-section-header">
        <h5>3. Registrar Proposal Review</h5>
        <p>Review proposed placements, approve or reject with a recorded reason. Approved proposals require a separate manual Apply action by authorized staff.</p>
    </header>

    <div class="curriculum-section-body">
        <div class="table-responsive table-responsive-curriculum">
            <table class="table placement-table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 25%; min-width: 200px;">Student</th>
                        <th style="width: 30%; min-width: 220px;">Proposed Assignment</th>
                        <th style="width: 15%; min-width: 130px;">Status</th>
                        <th style="width: 15%; min-width: 120px;">Proposed / Reviewed</th>
                        <th style="width: 15%; min-width: 120px; white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($proposals as $proposal)
                    @php
                        $snapshot = json_decode($proposal->snapshot, true, 512, JSON_THROW_ON_ERROR);
                        $academic = $snapshot['academic'] ?? [];
                    @endphp
                    <tr>
                        <td>
                            <div class="truncate-cell" title="{{ $proposal->student_name }} ({{ $proposal->student_code }})">
                                <strong>{{ $proposal->student_name }}</strong>
                                <div class="text-muted small">{{ $proposal->student_code }}</div>
                            </div>
                        </td>
                        <td>
                            <div class="truncate-cell" title="{{ $academic['programme']['name'] ?? 'N/A' }} · {{ $academic['intake']['name'] ?? 'N/A' }} · Curriculum {{ $academic['curriculum']['version'] ?? 'N/A' }} · {{ $academic['stage']['label'] ?? 'N/A' }} · {{ $academic['year']['label'] ?? 'N/A' }}">
                                <strong>{{ $academic['programme']['name'] ?? 'N/A' }}</strong>
                                <div class="text-muted small">
                                    {{ $academic['intake']['name'] ?? 'N/A' }} ·
                                    Curriculum {{ $academic['curriculum']['version'] ?? 'N/A' }} ·
                                    {{ $academic['stage']['label'] ?? 'N/A' }} ·
                                    {{ $academic['year']['label'] ?? 'N/A' }}
                                </div>
                            </div>
                        </td>
                        <td>
                            {!! $proposalStatusBadge($proposal->status) !!}
                        </td>
                        <td>
                            <div class="small">
                                Proposed by Staff #{{ $proposal->proposed_by }}<br>
                                @if($proposal->reviewed_at)
                                Reviewed by Staff #{{ $proposal->reviewed_by }} at {{ \Carbon\Carbon::parse($proposal->reviewed_at)->format('d M Y H:i') }}
                                @else
                                <span class="text-muted">Awaiting review</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="dropdown adminTable-action">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle table-action-btn-2"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        data-bs-display="static"
                                        aria-expanded="false"
                                        aria-label="Actions for proposal #{{ $proposal->id }}">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @if($canView)
                                    <li>
                                        <button class="dropdown-item" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#viewProposalModal"
                                                data-proposal-id="{{ $proposal->id }}"
                                                data-proposal-data="{{ json_encode([
                                                    'id' => $proposal->id,
                                                    'student_name' => $proposal->student_name,
                                                    'student_code' => $proposal->student_code,
                                                    'status' => $proposal->status,
                                                    'ordinary_entry_evidence' => $proposal->ordinary_entry_evidence,
                                                    'snapshot' => $snapshot,
                                                    'proposed_by' => $proposal->proposed_by,
                                                    'reviewed_by' => $proposal->reviewed_by,
                                                    'reviewed_at' => $proposal->reviewed_at,
                                                    'review_reason' => $proposal->review_reason,
                                                    'enrolment_acknowledged_at' => $proposal->enrolment_acknowledged_at,
                                                    'applied_by' => $proposal->applied_by,
                                                    'applied_at' => $proposal->applied_at,
                                                    'placement_id' => $proposal->placement_id,
                                                    'mapping_id' => $proposal->mapping_id,
                                                    'created_at' => $proposal->created_at,
                                                    'updated_at' => $proposal->updated_at,
                                                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                            <i class="bi bi-eye me-2"></i> View Details
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    @endif

                                    @if($canApprove && $proposal->status === 'pending')
                                    <li>
                                        <button class="dropdown-item" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#reviewProposalModal"
                                                data-proposal-id="{{ $proposal->id }}"
                                                data-proposal-student="{{ $proposal->student_name }}">
                                            <i class="bi bi-clipboard-check me-2"></i> Review Proposal
                                        </button>
                                    </li>
                                    @endif

                                    @if($canApprove && $canPlace && $proposal->status === 'approved')
                                    <li>
                                        <button class="dropdown-item" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#applyPlacementModal"
                                                data-proposal-id="{{ $proposal->id }}"
                                                data-proposal-student="{{ $proposal->student_name }}">
                                            <i class="bi bi-check2-square me-2"></i> Apply Placement
                                        </button>
                                    </li>
                                    @endif

                                    @if($proposal->placement_id)
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.academic.placements.index', ['student_id' => $proposal->student_id]) }}">
                                            <i class="bi bi-box-arrow-up-right me-2"></i> View Placement #{{ $proposal->placement_id }}
                                        </a>
                                    </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5">
                            <div class="curriculum-empty">
                                <i class="bi bi-clipboard-check"></i>
                                <h6>No proposals</h6>
                                <p class="mb-0">Proposals will appear here when staff generate them from the Propose Initial Placement stage.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $proposals->links() }}
    </div>
</section>

<!-- ============================================================
     REGISTRAR EXCEPTIONS
     ============================================================ -->
<section class="curriculum-section">
    <header class="curriculum-section-header">
        <h5>Registrar Exceptions</h5>
        <p>Unresolved curriculum assignment and mapping exceptions requiring attention.</p>
    </header>

    <div class="curriculum-section-body">
        @forelse($exceptions as $exception)
        <div class="border-bottom py-2">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>{{ $exception->code }}</strong>: {{ $exception->reason }}
                    <br>
                    <small class="text-muted">
                        {{ $exception->owner }} · {{ $exception->workflow }} #{{ $exception->subject_id }} ·
                        Observed {{ $exception->occurrences }} time(s) · Last seen {{ \Carbon\Carbon::parse($exception->last_seen_at)->format('d M Y H:i') }}
                    </small>
                </div>
            </div>
        </div>
        @empty
        <div class="curriculum-empty">
            <i class="bi bi-shield-check"></i>
            <h6>No unresolved exceptions</h6>
            <p class="mb-0">All curriculum assignment and mapping workflows are operating normally.</p>
        </div>
        @endforelse

        @if($exceptions->hasPages())
        {{ $exceptions->links() }}
        @endif
    </div>
</section>

<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- Create Assignment Modal -->
@if($canManage)
<div class="modal fade" id="createAssignmentModal" tabindex="-1" aria-labelledby="createAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.curriculum_assignment.mapping.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createAssignmentModalLabel"><i class="bi bi-plus-lg me-2"></i>Create Curriculum Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="mapping-programme_id">Programme <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="mapping-programme_id" name="programme_id">
                                <option value="">Choose Programme</option>
                                @foreach($options['programmes'] as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="mapping-intake_session_id">Admissions Intake <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="mapping-intake_session_id" name="intake_session_id">
                                <option value="">Choose Intake</option>
                                @foreach($options['intake_sessions'] as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="mapping-curriculum_id">Curriculum Version <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="mapping-curriculum_id" name="curriculum_id">
                                <option value="">Choose Curriculum</option>
                                @foreach($options['curricula'] as $row)
                                <option value="{{ $row->id }}">{{ $label('curricula', $row->id) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="mapping-entry_academic_year_id">Entry Academic Year <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="mapping-entry_academic_year_id" name="entry_academic_year_id">
                                <option value="">Choose Academic Year</option>
                                @foreach($options['academic_years'] as $row)
                                <option value="{{ $row->id }}">{{ $row->label ?? $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="mapping-entry_curriculum_stage_id">Entry Programme Year <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="mapping-entry_curriculum_stage_id" name="entry_curriculum_stage_id">
                                <option value="">Choose Programme Year</option>
                                @foreach($options['curriculum_stages'] as $row)
                                <option value="{{ $row->id }}">{{ $label('curriculum_stages', $row->id) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="mapping-reason">Registrar Policy Reference & Scope <span class="text-danger">*</span></label>
                            <textarea required maxlength="2000" rows="3" class="form-control form-control-sm" id="mapping-reason" name="reason"
                                      placeholder="Record the Registrar policy or approval reference for this assignment. Explain the scope and authority."></textarea>
                            <div class="form-text">1–2000 characters. This reference appears on the assignment record and in audit logs.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Draft Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- Edit Assignment Modal -->
@if($canManage)
<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-labelledby="editAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.curriculum_assignment.mapping.store') }}" id="editAssignmentForm">
                @csrf
                @method('PUT')
                <input type="hidden" name="mapping_id" id="edit-mapping_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editAssignmentModalLabel"><i class="bi bi-pencil me-2"></i>Edit Curriculum Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i> Only draft assignments can be edited. Approved assignments are immutable; revoke and replace them instead.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="edit-programme_id">Programme <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="edit-programme_id" name="programme_id">
                                <option value="">Choose Programme</option>
                                @foreach($options['programmes'] as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit-intake_session_id">Admissions Intake <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="edit-intake_session_id" name="intake_session_id">
                                <option value="">Choose Intake</option>
                                @foreach($options['intake_sessions'] as $row)
                                <option value="{{ $row->id }}">{{ $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit-curriculum_id">Curriculum Version <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="edit-curriculum_id" name="curriculum_id">
                                <option value="">Choose Curriculum</option>
                                @foreach($options['curricula'] as $row)
                                <option value="{{ $row->id }}">{{ $label('curricula', $row->id) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit-entry_academic_year_id">Entry Academic Year <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="edit-entry_academic_year_id" name="entry_academic_year_id">
                                <option value="">Choose Academic Year</option>
                                @foreach($options['academic_years'] as $row)
                                <option value="{{ $row->id }}">{{ $row->label ?? $row->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="edit-entry_curriculum_stage_id">Entry Programme Year <span class="text-danger">*</span></label>
                            <select required class="form-select form-select-sm" id="edit-entry_curriculum_stage_id" name="entry_curriculum_stage_id">
                                <option value="">Choose Programme Year</option>
                                @foreach($options['curriculum_stages'] as $row)
                                <option value="{{ $row->id }}">{{ $label('curriculum_stages', $row->id) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="edit-reason">Registrar Policy Reference & Scope <span class="text-danger">*</span></label>
                            <textarea required maxlength="2000" rows="3" class="form-control form-control-sm" id="edit-reason" name="reason"
                                      placeholder="Record the Registrar policy or approval reference for this assignment."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- View Assignment Modal -->
@if($canView)
<div class="modal fade curriculum-modal" id="viewAssignmentModal" tabindex="-1" aria-labelledby="viewAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewAssignmentModalLabel"><i class="bi bi-eye me-2"></i>Curriculum Assignment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewAssignmentModalBody">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Revoke Assignment Modal -->
@if($canApprove)
<div class="modal fade" id="revokeAssignmentModal" tabindex="-1" aria-labelledby="revokeAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="revokeAssignmentForm">
                @csrf
                <input type="hidden" name="mapping_id" id="revoke-mapping_id">
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="revokeAssignmentModalLabel"><i class="bi bi-x-circle me-2"></i>Revoke Curriculum Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>This action cannot be undone.</strong> Revoking an approved assignment makes it inactive. Existing student placements based on this assignment are preserved, but no new placements can be proposed from it.
                    </div>
                    <div id="revokeAssignmentSummary" class="mb-3 p-3 bg-light rounded small"></div>
                    <div class="mb-3">
                        <label class="form-label" for="revoke-reason">Revocation Reason <span class="text-danger">*</span></label>
                        <textarea required maxlength="2000" rows="4" class="form-control form-control-sm" id="revoke-reason" name="reason"
                                  placeholder="Explain why this assignment is being revoked. This reason will be recorded in the audit log."></textarea>
                        <div class="form-text">1–2000 characters. Required for audit trail.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Revoke Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- Propose Placement Modal -->
@if($canManage)
<div class="modal fade" id="proposePlacementModal" tabindex="-1" aria-labelledby="proposePlacementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.curriculum_assignment.propose') }}" id="proposePlacementForm">
                @csrf
                <input type="hidden" name="admission_id" id="propose-admission_id">
                <input type="hidden" name="mapping_id" id="propose-mapping_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="proposePlacementModalLabel"><i class="bi bi-person-plus me-2"></i>Propose Initial Placement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Student:</strong> <span id="propose-student-name"></span> (<span id="propose-student-code"></span>)
                        <br>
                        <strong>Approved Assignment:</strong> <span id="propose-assignment-summary"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="propose-ordinary_entry_evidence">Ordinary Initial-Entry Evidence Reviewed <span class="text-danger">*</span></label>
                        <textarea required maxlength="2000" rows="4" class="form-control form-control-sm" id="propose-ordinary_entry_evidence" name="ordinary_entry_evidence"
                                  placeholder="Record the evidence reviewed for ordinary initial entry, including transfer and credit-recognition checks."></textarea>
                        <div class="form-text">1–2000 characters. Required for audit trail.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Generate Proposal for Review</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- Review Proposal Modal -->
@if($canApprove)
<div class="modal fade" id="reviewProposalModal" tabindex="-1" aria-labelledby="reviewProposalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="reviewProposalForm">
                @csrf
                <input type="hidden" name="proposal_id" id="review-proposal_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewProposalModalLabel"><i class="bi bi-clipboard-check me-2"></i>Review Placement Proposal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="reviewProposalSummary" class="mb-3 p-3 bg-light rounded small"></div>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="review-reason">Registrar Decision Reason <span class="text-danger">*</span></label>
                        <textarea required maxlength="2000" rows="3" class="form-control form-control-sm" id="review-reason" name="reason"
                                  placeholder="Record the Registrar review decision and reason."></textarea>
                    </div>

                    <div class="form-check mb-3" id="review-ack-container" style="display: none;">
                        <input class="form-check-input" type="checkbox" name="acknowledge_enrolment" value="1" id="review-acknowledge_enrolment" required>
                        <label class="form-check-label" for="review-acknowledge_enrolment">
                            {{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary flex-grow-1" onclick="submitReview('approve')">
                            <i class="bi bi-check-circle me-1"></i> Approve Proposal
                        </button>
                        <button type="button" class="btn btn-outline-danger flex-grow-1" onclick="submitReview('reject')">
                            <i class="bi bi-x-circle me-1"></i> Reject Proposal
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- Apply Placement Modal -->
@if($canApprove)
<div class="modal fade" id="applyPlacementModal" tabindex="-1" aria-labelledby="applyPlacementModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="applyPlacementForm">
                @csrf
                <input type="hidden" name="proposal_id" id="apply-proposal_id">
                <div class="modal-header">
                    <h5 class="modal-title text-primary" id="applyPlacementModalLabel"><i class="bi bi-check2-square me-2"></i>Apply Approved Placement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>This action creates the student's curriculum placement.</strong> It cannot be undone through this interface. The placement will enable automatic course enrolment for eligible courses after academic and Finance checks.
                    </div>
                    <div id="applyPlacementSummary" class="mb-3 p-3 bg-light rounded small"></div>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>{{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}</strong>
                    </div>

                    <div class="form-check mb-3">
                        <input required class="form-check-input" type="checkbox" name="acknowledge_enrolment" value="1" id="apply-acknowledge_enrolment">
                        <label class="form-check-label" for="apply-acknowledge_enrolment">
                            {{ \App\Support\Curriculum\CurriculumAssignmentProposals::NOTICE }}
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Apply Placement</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- View Proposal Modal -->
@if($canView)
<div class="modal fade curriculum-modal" id="viewProposalModal" tabindex="-1" aria-labelledby="viewProposalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewProposalModalLabel"><i class="bi bi-eye me-2"></i>Placement Proposal Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewProposalModalBody">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

@section('page_scripts')
<script>
(function () {
    'use strict';

    // ============================================================
    // View Assignment Modal
    // ============================================================
    document.getElementById('viewAssignmentModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const data = JSON.parse(button.dataset.assignmentData);
        const body = document.getElementById('viewAssignmentModalBody');

        const statusBadge = {
            'draft': '<span class="badge bg-secondary">Draft</span>',
            'approved': '<span class="badge bg-success">Approved</span>',
            'revoked': '<span class="badge bg-danger">Revoked</span>',
        }[data.status] || '<span class="badge bg-light text-dark">' + data.status + '</span>';

        let html = '';
        html += '<div class="detail-row"><span class="detail-label">Assignment ID</span><span class="detail-value">#' + data.id + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Programme</span><span class="detail-value">' + data.programme + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Intake</span><span class="detail-value">' + data.intake + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Curriculum</span><span class="detail-value">' + data.curriculum + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Programme Year</span><span class="detail-value">' + data.stage + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Entry Academic Year</span><span class="detail-value">' + data.year + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">' + statusBadge + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Registrar Reference</span><span class="detail-value">' + (data.reason || '—') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Created By</span><span class="detail-value">Staff #' + data.created_by + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Created At</span><span class="detail-value">' + new Date(data.created_at).toLocaleString() + '</span></div>';

        if (data.approved_by) {
            html += '<div class="detail-row"><span class="detail-label">Approved By</span><span class="detail-value">Staff #' + data.approved_by + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Approved At</span><span class="detail-value">' + new Date(data.approved_at).toLocaleString() + '</span></div>';
        }
        if (data.revoked_by) {
            html += '<div class="detail-row"><span class="detail-label">Revoked By</span><span class="detail-value">Staff #' + data.revoked_by + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Revoked At</span><span class="detail-value">' + new Date(data.revoked_at).toLocaleString() + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Revocation Reason</span><span class="detail-value">' + (data.revocation_reason || '—') + '</span></div>';
        }
        if (data.updated_at !== data.created_at) {
            html += '<div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value">' + new Date(data.updated_at).toLocaleString() + '</span></div>';
        }

        body.innerHTML = html;
    });

    // ============================================================
    // Edit Assignment Modal
    // ============================================================
    document.getElementById('editAssignmentModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const data = JSON.parse(button.dataset.assignmentData);

        document.getElementById('edit-mapping_id').value = data.id;
        document.getElementById('edit-programme_id').value = data.programme_id;
        document.getElementById('edit-intake_session_id').value = data.intake_session_id;
        document.getElementById('edit-curriculum_id').value = data.curriculum_id;
        document.getElementById('edit-entry_academic_year_id').value = data.entry_academic_year_id;
        document.getElementById('edit-entry_curriculum_stage_id').value = data.entry_curriculum_stage_id;
        document.getElementById('edit-reason').value = data.reason;

        // Update form action to include the mapping ID for PUT
        const form = document.getElementById('editAssignmentForm');
        form.action = '{{ route('admin.curriculum_assignment.mapping.store') }}';
    });

    // ============================================================
    // Revoke Assignment Modal
    // ============================================================
    document.getElementById('revokeAssignmentModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const mappingId = button.dataset.assignmentId;
        const row = button.closest('tr');

        document.getElementById('revoke-mapping_id').value = mappingId;

        // Build summary from table row
        const programme = row.querySelector('td:first-child strong')?.textContent?.trim() || '';
        const intake = row.querySelector('td:first-child .text-muted')?.textContent?.trim() || '';
        const curriculum = row.querySelector('td:nth-child(2) strong')?.textContent?.trim() || '';

        const summary = document.getElementById('revokeAssignmentSummary');
        summary.innerHTML = '<strong>Assignment to be revoked:</strong><br>' +
            'Programme: ' + programme + '<br>' +
            'Intake: ' + intake + '<br>' +
            'Curriculum: ' + curriculum;

        const form = document.getElementById('revokeAssignmentForm');
        form.action = '{{ route('admin.curriculum_assignment.mapping.revoke', ':id') }}'.replace(':id', mappingId);
    });

    // ============================================================
    // Propose Placement Modal
    // ============================================================
    document.getElementById('proposePlacementModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const mappingData = JSON.parse(button.dataset.mappingData);

        document.getElementById('propose-admission_id').value = button.dataset.admissionId;
        document.getElementById('propose-mapping_id').value = button.dataset.mappingId;
        document.getElementById('propose-student-name').textContent = button.dataset.admissionName;
        document.getElementById('propose-student-code').textContent = button.dataset.admissionCode;
        document.getElementById('propose-assignment-summary').textContent =
            mappingData.programme + ' / ' + mappingData.intake + ' — ' +
            mappingData.curriculum + ' / ' + mappingData.stage + ' / ' + mappingData.year;

        // Clear evidence textarea
        document.getElementById('propose-ordinary_entry_evidence').value = '';
    });

    // ============================================================
    // Review Proposal Modal
    // ============================================================
    let currentReviewProposalId = null;

    document.getElementById('reviewProposalModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const proposalId = button.dataset.proposalId;
        const studentName = button.dataset.proposalStudent;

        currentReviewProposalId = proposalId;

        document.getElementById('review-proposal_id').value = proposalId;
        document.getElementById('reviewProposalSummary').innerHTML =
            '<strong>Proposal #' + proposalId + '</strong> for <strong>' + studentName + '</strong>';
        document.getElementById('review-reason').value = '';
        document.getElementById('review-acknowledge_enrolment').checked = false;
        document.getElementById('review-ack-container').style.display = 'none';
    });

    window.submitReview = function (decision) {
        const form = document.getElementById('reviewProposalForm');
        const reason = document.getElementById('review-reason').value.trim();

        if (!reason) {
            alert('Please provide a review reason.');
            return;
        }

        if (decision === 'approve' && !document.getElementById('review-acknowledge_enrolment').checked) {
            alert('You must acknowledge the enrolment consequence notice.');
            return;
        }

        // Create hidden decision input
        let decisionInput = form.querySelector('input[name="decision"]');
        if (!decisionInput) {
            decisionInput = document.createElement('input');
            decisionInput.type = 'hidden';
            decisionInput.name = 'decision';
            form.appendChild(decisionInput);
        }
        decisionInput.value = decision;

        form.action = '{{ route('admin.curriculum_assignment.review', ':id') }}'.replace(':id', currentReviewProposalId);
        form.submit();
    };

    // Show acknowledgement checkbox only when user clicks Approve
    document.querySelectorAll('#reviewProposalModal .btn-primary').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('review-ack-container').style.display = 'block';
        });
    });

    document.querySelectorAll('#reviewProposalModal .btn-outline-danger').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('review-ack-container').style.display = 'none';
        });
    });

    // ============================================================
    // Apply Placement Modal
    // ============================================================
    document.getElementById('applyPlacementModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const proposalId = button.dataset.proposalId;
        const studentName = button.dataset.proposalStudent;

        document.getElementById('apply-proposal_id').value = proposalId;
        document.getElementById('applyPlacementSummary').innerHTML =
            '<strong>Proposal #' + proposalId + '</strong> for <strong>' + studentName + '</strong><br>' +
            'This will create a curriculum placement for the student.';
        document.getElementById('apply-acknowledge_enrolment').checked = false;

        const form = document.getElementById('applyPlacementForm');
        form.action = '{{ route('admin.curriculum_assignment.apply', ':id') }}'.replace(':id', proposalId);
    });

    // ============================================================
    // View Proposal Modal
    // ============================================================
    document.getElementById('viewProposalModal')?.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const data = JSON.parse(button.dataset.proposalData);
        const body = document.getElementById('viewProposalModalBody');

        const statusBadge = {
            'pending': '<span class="badge bg-warning text-dark">Pending Review</span>',
            'approved': '<span class="badge bg-primary">Approved</span>',
            'rejected': '<span class="badge bg-danger">Rejected</span>',
            'applied': '<span class="badge bg-success">Applied</span>',
        }[data.status] || '<span class="badge bg-light text-dark">' + data.status + '</span>';

        const academic = data.snapshot?.academic || {};

        let html = '';
        html += '<div class="detail-row"><span class="detail-label">Proposal ID</span><span class="detail-value">#' + data.id + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Student</span><span class="detail-value">' + data.student_name + ' (' + data.student_code + ')</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">' + statusBadge + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Programme</span><span class="detail-value">' + (academic.programme?.name || 'N/A') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Intake</span><span class="detail-value">' + (academic.intake?.name || 'N/A') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Curriculum</span><span class="detail-value">Curriculum ' + (academic.curriculum?.version || 'N/A') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Programme Year</span><span class="detail-value">' + (academic.stage?.label || 'N/A') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Entry Academic Year</span><span class="detail-value">' + (academic.year?.label || 'N/A') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Entry Evidence</span><span class="detail-value">' + (data.ordinary_entry_evidence || '—') + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Proposed By</span><span class="detail-value">Staff #' + data.proposed_by + '</span></div>';
        html += '<div class="detail-row"><span class="detail-label">Proposed At</span><span class="detail-value">' + new Date(data.created_at).toLocaleString() + '</span></div>';

        if (data.reviewed_at) {
            html += '<div class="detail-row"><span class="detail-label">Reviewed By</span><span class="detail-value">Staff #' + data.reviewed_by + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Reviewed At</span><span class="detail-value">' + new Date(data.reviewed_at).toLocaleString() + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Review Reason</span><span class="detail-value">' + (data.review_reason || '—') + '</span></div>';
        }
        if (data.enrolment_acknowledged_at) {
            html += '<div class="detail-row"><span class="detail-label">Enrolment Acknowledged</span><span class="detail-value">' + new Date(data.enrolment_acknowledged_at).toLocaleString() + '</span></div>';
        }
        if (data.applied_at) {
            html += '<div class="detail-row"><span class="detail-label">Applied By</span><span class="detail-value">Staff #' + data.applied_by + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Applied At</span><span class="detail-value">' + new Date(data.applied_at).toLocaleString() + '</span></div>';
            html += '<div class="detail-row"><span class="detail-label">Placement ID</span><span class="detail-value">#' + data.placement_id + '</span></div>';
        }
        if (data.updated_at !== data.created_at) {
            html += '<div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value">' + new Date(data.updated_at).toLocaleString() + '</span></div>';
        }

        body.innerHTML = html;
    });

    // ============================================================
    // Delete Assignment - handle via form with DELETE method
    // ============================================================
    document.querySelectorAll('form[method="POST"] input[name="_method"][value="DELETE"]').forEach(input => {
        const form = input.closest('form');
        form.addEventListener('submit', function (e) {
            if (!confirm('Permanently delete this draft assignment? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

})();
</script>
@endsection