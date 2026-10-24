<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffNin;
use App\Support\Staff\StaffRecordException;
use App\Support\Staff\StaffRecordService;
use App\Support\Staff\StaffProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class OtherStaffController extends Controller
{
    public function create()
    {
        $school = (int) auth()->user()->school_id;
        return view('admin.staff.other_create', [
            'departments' => Department::where('school_id', $school)->orderBy('name')->get(),
            'designations' => Designation::where('school_id', $school)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, StaffProvisioningService $provisioning)
    {
        $school = (int) auth()->user()->school_id;
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191'], 'phone' => ['required', 'string', 'max:50'],
            'gender' => ['required', 'in:Male,Female,Other'], 'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:1000'], 'department_id' => ['nullable', 'integer'],
            'designation_id' => ['required', 'integer'], 'employment_type' => ['required', 'in:Full Time,Part Time,Casual'],
            'nin' => ['required', 'string', 'min:5', 'max:30'], 'staff_status' => ['required', 'in:active,on_leave,suspended,inactive,terminated'],
        ]);

        // The qualification block is optional, but once any of its fields is
        // started, require all three together and report errors in staff-facing
        // language rather than Laravel's nested input keys.
        $qualificationValidator = Validator::make($request->only('qualifications'), [
            'qualifications' => ['nullable', 'array', 'max:1'],
            'qualifications.*.qualification_level' => ['nullable', 'string', 'max:50'],
            'qualifications.*.qualification_name' => ['nullable', 'string', 'max:191'],
            'qualifications.*.institution' => ['nullable', 'string', 'max:191'],
        ], [
            'qualifications.*.qualification_level.string' => 'Select or enter the qualification level.',
            'qualifications.*.qualification_level.max' => 'The qualification level may not be longer than 50 characters.',
            'qualifications.*.qualification_name.string' => 'Enter the qualification/award.',
            'qualifications.*.qualification_name.max' => 'The qualification/award may not be longer than 191 characters.',
            'qualifications.*.institution.string' => 'Enter the institution.',
            'qualifications.*.institution.max' => 'The institution may not be longer than 191 characters.',
        ]);
        $qualificationValidator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('qualifications', []) as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $started = collect(['qualification_level', 'qualification_name', 'institution'])
                    ->contains(fn ($field) => trim((string) ($row[$field] ?? '')) !== '');
                if (!$started) {
                    continue;
                }

                foreach ([
                    'qualification_level' => 'Select or enter the qualification level.',
                    'qualification_name' => 'Enter the qualification/award.',
                    'institution' => 'Enter the institution.',
                ] as $field => $message) {
                    if (trim((string) ($row[$field] ?? '')) === '') {
                        $validator->errors()->add("qualifications.{$index}.{$field}", $message);
                    }
                }
            }
        });
        $data += $qualificationValidator->validate();

        if (isset($data['department_id']) && !Department::where('school_id', $school)->whereKey($data['department_id'])->exists()) abort(422);
        if (!Designation::where('school_id', $school)->whereKey($data['designation_id'])->exists()) abort(422);

        $account = $data + ['password_mode' => 'auto', 'photo' => null];
        $profile = ['nin' => $data['nin']];
        $qualifications = array_values(array_filter($data['qualifications'] ?? [], fn ($row) => !empty($row['qualification_name'])));
        $user = $provisioning->provisionProfessional(auth()->user(), SystemRole::GENERIC_STAFF, $account, $profile, $qualifications);
        return redirect()->route('admin.rbac.staff.show', $user->id)->with('message', 'Other staff member created. Next step: manage access.');
    }

    public function edit($id, StaffRecordService $records)
    {
        $actor = $this->schoolAdministrator();
        $member = $this->genericStaffInSchool($id, $actor);
        $profile = $records->profileOf($actor, $member);
        $information = json_decode((string) $member->user_information, true) ?: [];

        return view('admin.staff.other_edit', [
            'member' => $member,
            'profile' => $profile,
            'nin' => $profile ? $records->revealNin($actor, $member) : null,
            'information' => $information,
            'birthday' => !empty($information['birthday'])
                ? date('Y-m-d', is_numeric($information['birthday']) ? (int) $information['birthday'] : strtotime($information['birthday']))
                : '',
            'departments' => Department::where('school_id', (int) $actor->school_id)->orderBy('name')->get(),
            'designations' => Designation::where('school_id', (int) $actor->school_id)->orderBy('name')->get(),
            'qualifications' => $member->staffQualifications()->where('school_id', (int) $actor->school_id)->orderBy('id')->get(),
            'registrations' => $member->staffProfessionalRegistrations()->where('school_id', (int) $actor->school_id)->orderBy('id')->get(),
            'experiences' => $member->staffExperiences()->where('school_id', (int) $actor->school_id)->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, $id, StaffRecordService $records)
    {
        $actor = $this->schoolAdministrator();
        $member = $this->genericStaffInSchool($id, $actor);

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($member->id)],
            'phone' => ['required', 'string', 'max:50'],
            'gender' => ['required', Rule::in(['Male', 'Female', 'Other'])],
            'birthday' => ['required', 'date'],
            'nin' => ['required', 'string', 'min:5', 'max:30'],
            'address' => ['required', 'string', 'max:1000'],
            'department_id' => ['required', 'integer'],
            'designation_id' => ['required', 'integer'],
            'employment_type' => ['required', Rule::in(['Full Time', 'Part Time', 'Casual'])],
            'staff_status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'inactive', 'terminated'])],
        ], [
            'first_name.required' => 'Enter the first name.',
            'last_name.required' => 'Enter the last name.',
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email address is already in use.',
            'phone.required' => 'Enter a phone number.',
            'gender.required' => 'Select a gender.',
            'birthday.required' => 'Enter the date of birth.',
            'birthday.date' => 'Enter a valid date of birth.',
            'nin.required' => 'Enter the NIN / identity number.',
            'nin.min' => 'The NIN / identity number must contain at least 5 characters.',
            'nin.max' => 'The NIN / identity number may not exceed 30 characters.',
            'address.required' => 'Enter an address.',
            'department_id.required' => 'Select a department.',
            'department_id.integer' => 'Select a valid department.',
            'designation_id.required' => 'Select a designation / job title.',
            'designation_id.integer' => 'Select a valid designation / job title.',
            'employment_type.required' => 'Select an employment type.',
            'staff_status.required' => 'Select a staff status.',
        ]);

        $validator->after(function ($validator) use ($request, $actor, $member) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            if ($email !== '' && User::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $member->id)->exists()) {
                $validator->errors()->add('email', 'This email address is already in use.');
            }

            if ($request->filled('department_id') && !Department::where('school_id', (int) $actor->school_id)->whereKey($request->input('department_id'))->exists()) {
                $validator->errors()->add('department_id', 'Select a department from your school.');
            }
            if ($request->filled('designation_id') && !Designation::where('school_id', (int) $actor->school_id)->whereKey($request->input('designation_id'))->exists()) {
                $validator->errors()->add('designation_id', 'Select a designation / job title from your school.');
            }
            if ($request->filled('nin') && !StaffNin::isValid((string) $request->input('nin'))) {
                $validator->errors()->add('nin', 'Enter a valid NIN / identity number using 5 to 30 letters or digits.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $data['email'] = mb_strtolower(trim($data['email']));
        $oldEmail = (string) $member->email;
        $information = json_decode((string) $member->user_information, true) ?: [];
        $information = array_merge($information, [
            'gender' => $data['gender'],
            'birthday' => strtotime($data['birthday']),
            'phone' => trim($data['phone']),
            'address' => trim($data['address']),
        ]);
        $staffFields = StaffProvisioningService::staffFields($data);

        try {
            DB::transaction(function () use ($member, $actor, $records, $data, $information, $staffFields, $oldEmail) {
                $member->forceFill(array_merge($staffFields, [
                    'email' => $data['email'],
                    'user_information' => json_encode($information),
                    'staff_status' => $data['staff_status'],
                ]))->save();

                $records->saveProfile($actor, $member, ['nin' => $data['nin']]);

                if (strcasecmp($oldEmail, $data['email']) !== 0 && Schema::hasTable('password_resets')) {
                    DB::table('password_resets')->whereRaw('LOWER(email) = ?', [mb_strtolower($oldEmail)])->delete();
                }

                $profile = $member->staffProfile()->where('school_id', (int) $actor->school_id)->first();
                if (!$profile || !hash_equals((string) $profile->nin_hash, StaffNin::hash($data['nin']))) {
                    $records->saveProfile($actor, $member, ['nin' => $data['nin']]);
                }
            });
        } catch (StaffRecordException $exception) {
            return redirect()->back()->withErrors($exception->errors())->withInput();
        }

        AuditLog::record('OTHER_STAFF_PROFILE_UPDATED', 'Staff', "Updated Other Staff profile for staff #{$member->id}", [
            'school_id' => (int) $member->school_id,
            'record_type' => User::class,
            'record_id' => (int) $member->id,
            'new_values' => ['fields' => ['name', 'email', 'phone', 'gender', 'birthday', 'address', 'department_id', 'designation_id', 'employment_type', 'staff_status']],
        ]);

        return redirect()->route('admin.rbac.staff.index')->with('message', get_phrase('Other Staff profile updated. Use Account Access separately if password setup is needed.'));
    }

    private function schoolAdministrator(): User
    {
        $actor = auth()->user();
        abort_unless($actor && (int) $actor->role_id === SystemRole::SCHOOL_ADMIN && !empty($actor->school_id)
            && app(PermissionService::class)->isActive($actor), 403);

        return $actor;
    }

    private function genericStaffInSchool($id, User $actor): User
    {
        return User::where('school_id', (int) $actor->school_id)
            ->where('role_id', SystemRole::GENERIC_STAFF)->findOrFail($id);
    }
}
