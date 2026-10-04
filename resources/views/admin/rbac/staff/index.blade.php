@extends('admin.navigation')

@section('content')
<div class="rbac">
    @include('admin.rbac._header', ['title' => get_phrase('Staff Directory'), 'tab' => 'staff'])

    <p class="rbac-muted">{{ get_phrase('Designation describes a person’s institutional position. Access roles and permissions are managed separately.') }}</p>

    {{-- Master data the staff forms depend on, so a missing designation can be
         added without leaving the Staff screens. --}}
    @permission('hr.designations')
        <p class="rbac-muted small">
            <a href="{{ route('admin.designation_list') }}" target="_blank" rel="noopener">
                <i class="bi bi-diagram-3"></i> {{ get_phrase('Manage Designations') }}
            </a>
            &middot;
            <a href="{{ route('admin.department_list') }}" target="_blank" rel="noopener">
                <i class="bi bi-diagram-2"></i> {{ get_phrase('Manage Departments') }}
            </a>
            &middot;
            <a href="{{ route('admin.staff.add') }}"><i class="bi bi-person-plus"></i> {{ get_phrase('Add Staff') }}</a>
        </p>
    @endpermission

    <div class="rbac-card">
        <form method="GET" action="{{ route('admin.rbac.staff.index') }}" class="rbac-filters" role="search">
            <div>
                <label for="rbac-q" class="eForm-label">{{ get_phrase('Search') }}</label>
                <input type="search" id="rbac-q" name="q" class="form-control eForm-control" value="{{ $filters['q'] ?? '' }}" placeholder="{{ get_phrase('Name, email or staff ID') }}">
            </div>
            <div>
                <label for="rbac-base" class="eForm-label">{{ get_phrase('Staff type') }}</label>
                <select id="rbac-base" name="base_role" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All') }}</option>
                    @foreach ($baseRoles as $id => $label)
                        <option value="{{ $id }}" @selected((string) ($filters['base_role'] ?? '') === (string) $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rbac-department" class="eForm-label">{{ get_phrase('Department') }}</label>
                <select id="rbac-department" name="department_id" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All departments') }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) ($filters['department_id'] ?? '') === (string) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rbac-designation" class="eForm-label">{{ get_phrase('Designation / Job Title') }}</label>
                <select id="rbac-designation" name="designation_id" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All designations') }}</option>
                    @foreach ($designations as $designation)
                        <option value="{{ $designation->id }}" @selected((string) ($filters['designation_id'] ?? '') === (string) $designation->id)>{{ $designation->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rbac-employment" class="eForm-label">{{ get_phrase('Employment type') }}</label>
                <select id="rbac-employment" name="employment_type" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All') }}</option>
                    @foreach (['Full Time', 'Part Time', 'Casual'] as $type)
                        <option value="{{ $type }}" @selected(($filters['employment_type'] ?? '') === $type)>{{ get_phrase($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rbac-custom" class="eForm-label">{{ get_phrase('Custom role') }}</label>
                <select id="rbac-custom" name="custom_role" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All') }}</option>
                    @foreach ($customRoles as $role)
                        <option value="{{ $role->id }}" @selected((string) ($filters['custom_role'] ?? '') === (string) $role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="rbac-status" class="eForm-label">{{ get_phrase('Status') }}</label>
                <select id="rbac-status" name="status" class="form-select eForm-select">
                    <option value="">{{ get_phrase('All') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ get_phrase('Active') }}</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ get_phrase('Inactive') }}</option>
                </select>
            </div>
            <div class="rbac-actions">
                <button type="submit" class="btn btn-primary">{{ get_phrase('Filter') }}</button>
                <a href="{{ route('admin.rbac.staff.index') }}" class="btn btn-outline-secondary">{{ get_phrase('Reset') }}</a>
            </div>
        </form>
    </div>

    <div class="rbac-card">
        @if ($staff->isEmpty())
            <div class="rbac-empty"><i class="bi bi-people"></i>{{ get_phrase('No staff members match these filters.') }}</div>
        @else
            <div class="table-responsive">
                <table class="rbac-table">
                    <thead>
                        <tr>
                            <th>{{ get_phrase('Name') }}</th>
                            <th>{{ get_phrase('Staff ID') }}</th>
                            <th>{{ get_phrase('Staff type') }}</th>
                            <th>{{ get_phrase('Department') }}</th>
                            <th>{{ get_phrase('Designation / Job Title') }}</th>
                            <th>{{ get_phrase('Employment type') }}</th>
                            <th>{{ get_phrase('Access roles') }}</th>
                            <th>{{ get_phrase('Direct permissions') }}</th>
                            <th>{{ get_phrase('Status') }}</th>
                            <th class="text-end">{{ get_phrase('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($staff as $member)
                            @php
                                $inactive = $member->account_status === 'disable' || \App\Support\Staff\StaffStatus::blocksPortal($member->staff_status);
                                $isAdmin = (int) $member->role_id === 2;
                                $suspended = (string) $member->staff_status === \App\Support\Staff\StaffStatus::SUSPENDED;
                            @endphp
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $member->name }}</span><br>
                                    <span class="rbac-muted small">{{ $member->email }}</span>
                                </td>
                                <td class="rbac-key">{{ $member->code ?: '—' }}</td>
                                <td>
                                    {{ (int) $member->role_id === \App\Support\Roles\SystemRole::TEACHER
                                        ? app(\App\Support\TenantConfiguration::class)->terminology()['teacher']
                                        : ((int) $member->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF
                                            ? get_phrase('Other Staff')
                                            : (\App\Support\Roles\SystemRole::name((int) $member->role_id) ?? get_phrase('Staff member'))) }}
                                    @if (in_array((int) $member->role_id, $legacyRoles, true))<span class="rbac-badge rbac-badge-legacy ms-1" title="{{ get_phrase('Legacy base role with historical meanings; unchanged.') }}">{{ get_phrase('Legacy') }}</span>@endif
                                </td>
                                <td>{{ $member->department_name ?: '—' }}</td>
                                <td>{{ $member->designation_name ?: '—' }}</td>
                                <td>{{ $member->employment_type ?: '—' }}</td>
                                <td>
                                    @forelse ($rolesByUser[$member->id] ?? [] as $assigned)
                                        <span class="rbac-badge {{ $assigned->is_active ? 'rbac-badge-role' : 'rbac-badge-inactive' }} mb-1">{{ $assigned->name }}</span>
                                    @empty
                                        <span class="rbac-muted">—</span>
                                    @endforelse
                                </td>
                                <td>{{ $directCounts[$member->id] ?? 0 }}</td>
                                <td><span class="rbac-badge {{ $inactive ? 'rbac-badge-inactive' : 'rbac-badge-active' }}">{{ $inactive ? get_phrase('Inactive') : get_phrase('Active') }}</span></td>
                                <td class="text-end">
                                    {{-- One clear Actions menu per row. There is no hard
                                         delete: a staff record is corrected, suspended
                                         or reinstated, never removed. --}}
                                    <div class="rbac-actions justify-content-end">
                                        @if($isAdmin)<span class="rbac-badge rbac-badge-admin">{{ get_phrase('Full access') }}</span>@endif
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button"
                                                    data-bs-toggle="dropdown" aria-expanded="false"
                                                    aria-label="{{ get_phrase('Actions for') }} {{ $member->name }}">
                                                {{ get_phrase('Actions') }}
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="{{ route('admin.staff.profile.show', $member->id) }}">
                                                        <i class="bi bi-person-vcard"></i> {{ get_phrase('View Profile') }}
                                                    </a>
                                                </li>
                                                <li>
                                                    @if((int) $member->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF)
                                                        {{-- Other Staff keeps its own, richer profile
                                                             correction screen. --}}
                                                        <a class="dropdown-item" href="{{ route('admin.staff.other.edit', $member->id) }}">
                                                            <i class="bi bi-pencil-square"></i> {{ get_phrase('Edit profile') }}
                                                        </a>
                                                    @else
                                                        <a class="dropdown-item" href="{{ route('admin.staff.profile.edit', $member->id) }}">
                                                            <i class="bi bi-pencil-square"></i> {{ get_phrase('Edit staff') }}
                                                        </a>
                                                    @endif
                                                </li>
                                                <li>
                                                    {{-- PORTAL / ACCOUNT ACCESS: password setup, the setup
                                                         link and the account state. Deliberately a separate
                                                         entry from Roles & Permissions below, which is a
                                                         different concept with a different authority. --}}
                                                    <a class="dropdown-item" href="{{ route('admin.staff.account-access.show', $member->id) }}">
                                                        <i class="bi bi-key"></i> {{ get_phrase('Account Access') }}
                                                        @if (\App\Support\Staff\StaffAccountAccess::requiresSetup($member))
                                                            <span class="rbac-badge rbac-badge-legacy ms-1">{{ get_phrase('Setup required') }}</span>
                                                        @endif
                                                    </a>
                                                </li>
                                                <li>
                                                    {{-- ROLES & PERMISSIONS: base role, custom roles, direct
                                                         permissions and effective access. --}}
                                                    <a class="dropdown-item" href="{{ route('admin.rbac.staff.show', $member->id) }}">
                                                        <i class="bi bi-shield-lock"></i> {{ get_phrase('Roles & Permissions') }}
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                @if((int) $member->id !== (int) auth()->id())
                                                <li>
                                                    {{-- The existing staff lifecycle: Suspend blocks
                                                         the portal, Reinstate restores it. No record
                                                         is ever deleted. --}}
                                                    <form method="POST" action="{{ route('admin.staff.profile.status', $member->id) }}"
                                                          onsubmit="return confirm(@json($suspended
                                                              ? get_phrase('Reinstate this staff member? Their portal login becomes usable again.')
                                                              : get_phrase('Suspend this staff member? Their portal login stays blocked until they are reinstated.')))">
                                                        @csrf
                                                        <input type="hidden" name="staff_status" value="{{ $suspended ? 'active' : 'suspended' }}">
                                                        <button type="submit" class="dropdown-item {{ $suspended ? '' : 'text-danger' }}">
                                                            <i class="bi {{ $suspended ? 'bi-play-circle' : 'bi-pause-circle' }}"></i>
                                                            {{ $suspended ? get_phrase('Reinstate') : get_phrase('Suspend') }}
                                                        </button>
                                                    </form>
                                                </li>
                                                @endif
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $staff->links() }}</div>
        @endif
    </div>
</div>
@endsection
