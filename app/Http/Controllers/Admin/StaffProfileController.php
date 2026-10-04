<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Designation;
use App\Models\StaffDocument;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\ProfilePhoto;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffAccountAccess;
use App\Support\Staff\StaffDocumentStorage;
use App\Support\Staff\StaffNextOfKin;
use App\Support\Staff\StaffNin;
use App\Support\Staff\StaffProvisioningService;
use App\Support\Staff\StaffQualificationLevel;
use App\Support\Staff\StaffRecordException;
use App\Support\Staff\StaffRecordService;
use App\Support\Staff\StaffStatus;
use App\Support\Staff\StaffTitle;
use App\Support\TenantConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin -> Staff Directory -> one staff member: the complete record, the
 * correction form, and the governed Suspend / Reinstate action.
 *
 * This is HR profile management, deliberately NOT access governance, so it stays
 * separate from RolePermissionController (Roles & Permissions -> Manage Access):
 *
 *   here   name, contact, department/designation, employment, Next of Kin, and
 *          for lecturers the academic & professional records
 *   there  base role, custom roles, direct permissions
 *
 * Hard guarantees, enforced by the writers below rather than by leaving fields
 * out of the form:
 *
 *  - NEVER written: users.code (Staff Number), users.role_id (base role),
 *    users.account_status, users.password, users.force_password_change,
 *    users.status. Identity and access are not HR data.
 *  - NEVER written: user_staff_roles / user_permissions. Correcting a profile
 *    cannot add, remove or alter a single permission.
 *  - Never deleted: a staff member, a qualification, a professional registration
 *    or a stored document. Corrections are applied in place.
 *  - Tenant-scoped through StaffRecordService::staffInSchool(), so another
 *    school's staff id is a plain 404 and its existence is never revealed.
 *  - Audited through the existing audit log with meaningful old and new values,
 *    and never with a NIN in any form.
 */
class StaffProfileController extends Controller
{
    /** The same vocabularies staff creation accepts, so the two cannot drift. */
    private const BLOOD_GROUPS = StaffCreateController::BLOOD_GROUPS;

    private const GENDERS = StaffCreateController::GENDERS;

    private const EMPLOYMENT_TYPES = StaffCreateController::EMPLOYMENT_TYPES;

    /** Longest edge of one request's document batch. */
    private const MAX_DOCUMENTS = 10;

    public function __construct(private PermissionService $permissions)
    {
    }

    // ─────────────────────────────────────────────────────── View profile

    public function show($id, StaffRecordService $records): View
    {
        $actor = $this->actor();
        $member = $records->staffInSchool($actor, (int) $id);
        abort_unless($this->can($actor, 'staff.view'), 403);

        $school = (int) $actor->school_id;
        $information = $this->information($member);
        $setupState = $this->setupState($member);

        return view('admin.staff.profile', $this->viewData($member, $this->profileOrBlank($records, $actor, $member), $information) + [
            'qualifications' => $member->staffQualifications()->where('school_id', $school)->orderBy('id')->get(),
            'registrations' => $member->staffProfessionalRegistrations()->where('school_id', $school)->orderBy('id')->get(),
            'documents' => $member->staffDocuments()->where('school_id', $school)->orderBy('id')->get(),
            'experiences' => $member->staffExperiences()->where('school_id', $school)->orderBy('id')->get(),
            'ninDisplay' => $records->ninDisplay($actor, $member),
            'setupState' => $setupState,
            'setupStateLabel' => $this->setupStateLabel($setupState),
            'isAcademic' => $this->isAcademic($member),
            'canEdit' => $this->can($actor, 'staff.edit'),
            'canManageAccess' => (int) $member->role_id !== SystemRole::SCHOOL_ADMIN
                && (int) $member->id !== (int) $actor->id,
            'canViewDocuments' => $this->can($actor, 'staff.documents.view'),
        ]);
    }

    // ────────────────────────────────────────────────────────── Edit staff

    public function edit($id, StaffRecordService $records): View
    {
        $actor = $this->actor();
        $member = $records->staffInSchool($actor, (int) $id);
        abort_unless($this->can($actor, 'staff.edit'), 403);

        $school = (int) $actor->school_id;

        return view('admin.staff.edit', $this->viewData($member, $this->profileOrBlank($records, $actor, $member), $this->information($member)) + [
            // The single professional record this form corrects, when one exists.
            'qualification' => $member->staffQualifications()->where('school_id', $school)->orderByRaw('completion_year is null desc')->orderBy('id')->first(),
            'registration' => $member->staffProfessionalRegistrations()->where('school_id', $school)->orderBy('id')->first(),
            'existingQualifications' => $member->staffQualifications()->where('school_id', $school)->orderBy('id')->get(),
            'existingDocuments' => $member->staffDocuments()->where('school_id', $school)->orderBy('id')->get(),
            'isAcademic' => $this->isAcademic($member),
            'ninDisplay' => $records->ninDisplay($actor, $member),
            'canUploadDocuments' => $this->can($actor, 'staff.documents.upload'),
            'canViewDocuments' => $this->can($actor, 'staff.documents.view'),
        ]);
    }

    public function update(Request $request, $id, StaffRecordService $records)
    {
        $actor = $this->actor();
        $member = $records->staffInSchool($actor, (int) $id);
        abort_unless($this->can($actor, 'staff.edit'), 403);
        $school = (int) $actor->school_id;

        $data = $request->validate($this->rules($school), $this->messages());

        $this->assertEmailAvailable($data['email'], (int) $member->id);
        $this->assertDependentFields($request);

        // Null for a base role with no academic block, so a non-academic
        // correction can never create an empty qualification.
        $professional = $this->isAcademic($member) ? $this->academicPayload($request) : null;

        // Captured before anything is written, so the audit entry really is the
        // OLD value and not the new one.
        $before = $this->auditBefore($member);

        try {
            DB::transaction(function () use ($actor, $member, $records, $data, $request, $professional) {
                $this->writeAccount($member, $data, $request);
                $this->writeProfile($actor, $member, $records, $data, $request, $professional);
                $this->writeProfessional($actor, $member, $records, $professional, $request);
            });
        } catch (StaffRecordException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        AuditLog::record('STAFF_RECORD_UPDATED', 'Staff', "Corrected the staff record for #{$member->id}", [
            'school_id' => $school,
            'record_type' => User::class,
            'record_id' => (int) $member->id,
            'old_values' => $before,
            // Field NAMES plus the non-identifying employment values, so the
            // entry is useful without ever recording an identity number.
            'new_values' => [
                'fields' => array_values(array_diff(array_keys($data), ['nin', 'photo', 'title_other', 'qualification_level_other'])),
                'employment' => [
                    'designation_id' => $data['designation_id'],
                    'department_id' => $data['department_id'] ?? null,
                    'employment_type' => $data['employment_type'],
                    'staff_status' => $data['staff_status'],
                ],
            ],
        ]);

        return redirect()->route('admin.staff.profile.show', $member->id)
            ->with('message', get_phrase('The staff record was updated. Access roles and account access were not changed.'));
    }

    // ────────────────────────────────────── Governed Suspend / Reinstate

    /**
     * The existing staff lifecycle, nothing new: users.staff_status moved to
     * StaffStatus::SUSPENDED (which already blocks the portal) or back to ACTIVE.
     *
     * Audited as an employment change. It deliberately does NOT touch
     * users.account_status, the password, or any role — those stay on the
     * Account Access screen, and no record is ever deleted.
     */
    public function status(Request $request, $id, StaffRecordService $records)
    {
        $actor = $this->actor();
        $member = $records->staffInSchool($actor, (int) $id);
        abort_unless($this->can($actor, 'staff.edit'), 403);

        $to = (string) $request->input('staff_status');
        if (!in_array($to, [StaffStatus::SUSPENDED, StaffStatus::ACTIVE], true)) {
            return back()->with('error', get_phrase('Unsupported employment status change.'));
        }
        if ((int) $member->id === (int) $actor->id) {
            return back()->with('error', get_phrase('You cannot change your own employment status here.'));
        }

        $from = (string) ($member->staff_status ?: StaffStatus::ACTIVE);
        if ($from === $to) {
            return back()->with('message', get_phrase('That employment status was already in place.'));
        }

        $member->forceFill(['staff_status' => $to])->save();

        AuditLog::record('STAFF_EMPLOYMENT_STATUS_CHANGED', 'Staff', "Employment status for staff #{$member->id} changed", [
            'school_id' => (int) $member->school_id,
            'record_type' => User::class,
            'record_id' => (int) $member->id,
            'old_values' => ['staff_status' => $from],
            'new_values' => ['staff_status' => $to],
        ]);

        return back()->with('message', $to === StaffStatus::SUSPENDED
            ? get_phrase('Employment status set to Suspended. Their portal login stays blocked until they are reinstated.')
            : get_phrase('Employment status set to Active.'));
    }

    // ────────────────────────────────────────────────────────── internals

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor
            && (int) $actor->role_id !== SystemRole::SUPER_ADMIN
            && !empty($actor->school_id)
            && $this->permissions->isActive($actor), 403);

        return $actor;
    }

    private function can(User $actor, string $key): bool
    {
        return $this->permissions->allows($actor, $key);
    }

    /** Lecturer/Teacher, the base role the creation form also treats as academic. */
    private function isAcademic(User $member): bool
    {
        return (int) $member->role_id === SystemRole::TEACHER;
    }

    private function information(User $member): array
    {
        return json_decode((string) $member->user_information, true) ?: [];
    }

    /**
     * The staff_profiles row, or an UNSAVED blank one when the record has none
     * (a staff member created before the Staff Module). The screens then read
     * one shape instead of guarding every field against null.
     */
    private function profileOrBlank(StaffRecordService $records, User $actor, User $member): StaffProfile
    {
        return $records->profileOf($actor, $member)
            ?: (new StaffProfile())->forceFill(['school_id' => (int) $member->school_id]);
    }

    /**
     * The EXISTING governed account-setup state, read from the same
     * StaffAccountAccess helper the Account Access screen uses — so the profile
     * can never disagree with the screen it links to.
     */
    private function setupState(User $member): string
    {
        return StaffAccountAccess::state($member);
    }

    /** The platform's canonical account-access wording, identical on both screens. */
    private function setupStateLabel(string $state): string
    {
        return match ($state) {
            StaffAccountAccess::PENDING => get_phrase('Pending setup'),
            StaffAccountAccess::COMPLETED => get_phrase('Completed'),
            default => get_phrase('Setup required'),
        };
    }

    /** Everything the profile and the edit screen both display. */
    private function viewData(User $member, ?StaffProfile $profile, array $information): array
    {
        $school = (int) $member->school_id;

        return [
            'member' => $member,
            'profile' => $profile,
            'information' => $information,
            'birthday' => !empty($information['birthday'])
                ? date('Y-m-d', is_numeric($information['birthday']) ? (int) $information['birthday'] : strtotime((string) $information['birthday']))
                : '',
            'baseRole' => $this->baseRoleLabel($member),
            'titles' => StaffTitle::ALL,
            'relationships' => StaffNextOfKin::ALL,
            'qualificationLevels' => StaffQualificationLevel::ALL,
            'bloodGroups' => self::BLOOD_GROUPS,
            'genders' => self::GENDERS,
            'employmentTypes' => self::EMPLOYMENT_TYPES,
            'staffStatuses' => StaffStatus::ALL,
            'departments' => Department::where('school_id', $school)->orderBy('name')->get(),
            'designations' => Designation::where('school_id', $school)->orderBy('name')->get(),
            'documentCategories' => $this->documentCategories(),
            'maxKb' => StaffDocumentStorage::maxKb(),
            'maxDocuments' => self::MAX_DOCUMENTS,
            'acceptedMime' => StaffCreateController::ACCEPTED_MIME_LABELS,
        ];
    }

    private function baseRoleLabel(User $member): string
    {
        $roleId = (int) $member->role_id;

        return match ($roleId) {
            SystemRole::TEACHER => app(TenantConfiguration::class)->terminology()['teacher'],
            SystemRole::GENERIC_STAFF => get_phrase('Other Staff'),
            default => (string) (SystemRole::name($roleId) ?? ('Role ' . $roleId)),
        };
    }

    private function documentCategories(): array
    {
        $out = [];
        foreach (StaffCreateController::UPLOADABLE_DOCUMENTS as $key) {
            if (isset(StaffDocument::CATEGORIES[$key])) {
                $out[$key] = StaffDocument::CATEGORIES[$key];
            }
        }

        return $out;
    }

    // ── validation ───────────────────────────────────────────────────────

    private function rules(int $school): array
    {
        $rules = [
            // Personal
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'required', Rule::in(StaffTitle::ALL)],
            'title_other' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', Rule::in(self::GENDERS)],
            'birthday' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'nin' => ['nullable', 'string', 'min:'.StaffNin::MIN_LENGTH, 'max:'.StaffNin::MAX_LENGTH],
            'blood_group' => ['nullable', Rule::in(self::BLOOD_GROUPS)],
            'address' => ['nullable', 'string', 'max:1000'],
            // Contact
            'email' => ['required', 'email:filter', 'max:191'],
            'phone' => ['required', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            'alternative_phone' => ['nullable', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            // Employment — department and designation must exist IN THIS TENANT.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('school_id', $school)],
            'designation_id' => ['required', 'integer', Rule::exists('designations', 'id')->where('school_id', $school)],
            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],
            'staff_status' => ['required', Rule::in(StaffStatus::ALL)],
            'date_joined' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
            // Academic & professional, only shown for the academic base roles.
            'qualification_level' => ['nullable', 'string', Rule::in(StaffQualificationLevel::ALL)],
            'qualification_level_other' => ['nullable', 'string', 'max:35'],
            'field_of_study' => ['nullable', 'string', 'max:191'],
            'institution' => ['nullable', 'string', 'max:191'],
            'completion_year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 10)],
            'professional_body' => ['nullable', 'string', 'max:191'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'years_teaching_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
        ];

        // Next of Kin stays lenient on edit: a record may legitimately predate it.
        $nok = StaffRecordService::nextOfKinRules(false);
        $nok['emergency_contact_relationship'] = ['nullable', Rule::in(StaffNextOfKin::ALL)];
        $nok['emergency_contact_relationship_other'] = ['nullable', 'string', 'max:40'];

        return array_merge($rules, $nok);
    }

    private function messages(): array
    {
        return [
            'first_name.required' => 'Enter the first name.',
            'last_name.required' => 'Enter the last name.',
            'gender.required' => 'Select a gender.',
            'gender.in' => 'Select a gender.',
            'birthday.before' => 'The date of birth must be a date in the past.',
            'birthday.date' => 'Enter a valid date of birth.',
            'title.in' => 'Choose a title.',
            'title.required' => 'Choose a title.',
            'title_other.max' => 'That title may not be longer than 20 characters.',
            'nin.min' => 'A national ID number may not be shorter than 5 characters.',
            'nin.max' => 'A national ID number may not be longer than 30 characters.',
            'email.required' => 'Enter the staff member’s email address.',
            'email.email' => 'Enter a valid email address.',
            'phone.required' => 'Enter the primary contact number.',
            'phone.regex' => 'Enter a valid contact number, for example +256 712 345 678.',
            'alternative_phone.regex' => 'Enter a valid alternative contact number, for example +256 712 345 678.',
            'department_id.exists' => 'Select a department from this institution.',
            'designation_id.required' => 'Select a designation.',
            'designation_id.exists' => 'Select a designation from this institution.',
            'employment_type.required' => 'Select an employment type.',
            'employment_type.in' => 'Select an employment type.',
            'staff_status.required' => 'Select an employment status.',
            'staff_status.in' => 'Select an employment status.',
            'emergency_contact_name.max' => 'The Next of Kin name may not be longer than 150 characters.',
            'emergency_contact_email.email' => 'Enter a valid Next of Kin email address.',
            'emergency_contact_phone.regex' => 'Enter a valid contact number, for example +256 712 345 678.',
            'emergency_contact_alternative_phone.regex' => 'Enter a valid alternative contact number, for example +256 712 345 678.',
            'emergency_contact_address.max' => 'The Next of Kin address may not be longer than 255 characters.',
            'qualification_level.in' => 'Choose the highest qualification held.',
            'qualification_level_other.max' => 'That qualification may not be longer than 35 characters.',
            'completion_year.min' => 'Enter a year from 1900 onwards.',
            'completion_year.max' => 'The year awarded cannot be in the future.',
            'photo.mimes' => 'The profile photo must be a JPG or PNG image.',
            'photo.max' => 'The profile photo may not be larger than 4 MB.',
        ];
    }

    private function assertEmailAvailable(string $email, int $memberId): void
    {
        if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->where('id', '!=', $memberId)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email address is already in use.'],
            ]);
        }
    }

    /**
     * The "Other" escape hatches and the academic block are all-or-nothing, so a
     * half-finished correction is reported instead of being silently dropped.
     */
    private function assertDependentFields(Request $request): void
    {
        $errors = [];

        $title = (string) $request->input('title');
        $titleOther = trim((string) $request->input('title_other'));
        if ($title === StaffTitle::OTHER && $titleOther === '') {
            $errors['title_other'] = ['Enter the title to use.'];
        }
        if ($title !== '' && $title !== StaffTitle::OTHER && $titleOther !== '') {
            $errors['title_other'] = ['A custom title is only used when "Other" is selected.'];
        }

        if ($request->input('emergency_contact_relationship') === StaffNextOfKin::OTHER
            && trim((string) $request->input('emergency_contact_relationship_other')) === '') {
            $errors['emergency_contact_relationship_other'] = ['Describe how the Next of Kin is related to this staff member.'];
        }

        if ($request->input('qualification_level') === StaffQualificationLevel::OTHER
            && trim((string) $request->input('qualification_level_other')) === '') {
            $errors['qualification_level_other'] = ['Enter the qualification held.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    // ── writers ───────────────────────────────────────────────────────────

    /**
     * users table. The written keys are a FIXED list, so users.code, role_id,
     * account_status, password, force_password_change and status cannot be
     * reached from the request even if one is posted.
     */
    private function writeAccount(User $member, array $data, Request $request): void
    {
        $information = $this->information($member);

        if ($request->hasFile('photo')) {
            $photo = ProfilePhoto::store($request->file('photo'));
            if ($photo === null) {
                throw ValidationException::withMessages([
                    'photo' => ['The profile photo must be a JPG or PNG image of at most 4 MB.'],
                ]);
            }
            $information['photo'] = $photo;
        }

        $information = array_merge($information, [
            'gender' => $data['gender'],
            'blood_group' => $data['blood_group'] ?? '',
            'birthday' => empty($data['birthday']) ? 0 : strtotime((string) $data['birthday']),
            'phone' => $data['phone'],
            'address' => trim((string) ($data['address'] ?? '')),
        ]);

        $fields = array_merge(StaffProvisioningService::staffFields($data), [
            'email' => mb_strtolower(trim($data['email'])),
            'user_information' => json_encode($information),
            'staff_status' => $data['staff_status'],
        ]);

        $member->forceFill($fields)->save();
    }

    /** staff_profiles: the Next of Kin, the title, the professional extras and the NIN. */
    private function writeProfile(User $actor, User $member, StaffRecordService $records, array $data, Request $request, ?array $professional): void
    {
        $nok = [];
        foreach (['emergency_contact_name', 'emergency_contact_email', 'emergency_contact_phone', 'emergency_contact_alternative_phone', 'emergency_contact_address'] as $field) {
            $nok[$field] = $data[$field] ?? null;
        }

        // normalise() throws on an empty or unknown value, so it is only called
        // for a relationship that was actually chosen. With no relationship and
        // no Next of Kin details, the stored value is left exactly as it was.
        $relationship = (string) $request->input('emergency_contact_relationship');
        if ($relationship !== '') {
            $nok['emergency_contact_relationship'] = StaffNextOfKin::normalise($relationship, $request->input('emergency_contact_relationship_other'));
        } elseif (array_filter($nok, fn ($v) => trim((string) $v) !== '') === []) {
            $nok['emergency_contact_relationship'] = null;
        }

        $title = (string) $request->input('title');
        $profile = [
            'title' => $title !== '' ? StaffTitle::normalise($title, $request->input('title_other')) : null,
            'middle_name' => $data['middle_name'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'date_joined' => $data['date_joined'] ?? null,
            'alternative_phone' => $data['alternative_phone'] ?? null,
        ] + $nok;

        if ($professional !== null) {
            $profile['years_teaching_experience'] = $data['years_teaching_experience'] ?? null;
        }

        // A blank NIN keeps whatever is already recorded, so an unrelated HR
        // correction can never silently erase the identity number.
        if (trim((string) $data['nin']) !== '') {
            $profile['nin'] = $data['nin'];
        }

        $records->saveProfile($actor, $member, $profile);
    }

    /**
     * The academic & professional records. An existing qualification and
     * registration are CORRECTED IN PLACE; a new one is created only when the
     * staff member has none. Nothing is deleted, and documents are only added.
     */
    private function writeProfessional(User $actor, User $member, StaffRecordService $records, ?array $payload, Request $request): void
    {
        if ($payload === null) {
            return;
        }

        $school = (int) $member->school_id;

        $qualification = $payload['qualification'];
        if ($qualification !== null) {
            $existing = $member->staffQualifications()->where('school_id', $school)
                ->orderByRaw('completion_year is null desc')->orderBy('id')->first();
            if ($existing) {
                $records->updateQualification($actor, $member, (int) $existing->id, $qualification);
            } else {
                $records->addQualification($actor, $member, $qualification);
            }
        }

        $registration = $payload['registration'];
        if ($registration !== null) {
            $existing = $member->staffProfessionalRegistrations()->where('school_id', $school)->orderBy('id')->first();
            if ($existing) {
                $records->updateRegistration($actor, $member, (int) $existing->id, $registration);
            } else {
                $records->addRegistration($actor, $member, $registration);
            }
        }

        foreach ($this->uploadedDocuments($request) as $document) {
            $records->uploadDocument($actor, $member, $document['file'], (string) $document['category']);
        }
    }

    /**
     * The academic block, mapped onto the EXISTING professional-record
     * architecture. A wholly blank block yields nulls, so nothing is created and
     * an existing record is left untouched.
     */
    private function academicPayload(Request $request): array
    {
        $level = (string) $request->input('qualification_level');
        $study = trim((string) $request->input('field_of_study'));
        $institution = trim((string) $request->input('institution'));
        $year = $request->input('completion_year');

        $qualification = null;
        if (array_filter([$level, $study, $institution], fn ($v) => trim((string) $v) !== '') !== []) {
            $errors = [];
            if ($level === '') {
                $errors['qualification_level'] = 'Choose the highest qualification held.';
            }
            if ($study === '') {
                $errors['field_of_study'] = 'Enter the field of study or specialisation.';
            }
            if ($institution === '') {
                $errors['institution'] = 'Enter the awarding institution.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $qualification = [
                'qualification_level' => StaffQualificationLevel::normalise($level, $request->input('qualification_level_other')),
                'qualification_name' => StaffQualificationLevel::base($level),
                'specialisation' => $study,
                'institution' => $institution,
            ];
            if ($year !== null && $year !== '') {
                $qualification['completion_year'] = (int) $year;
            }
        }

        $body = trim((string) $request->input('professional_body'));
        $number = trim((string) $request->input('registration_number'));
        $registration = null;
        if ($body !== '' || $number !== '') {
            if ($body === '') {
                throw ValidationException::withMessages([
                    'professional_body' => ['Enter the professional body.'],
                ]);
            }
            $registration = ['professional_body' => $body];
            if ($number !== '') {
                $registration['registration_number'] = $number;
            }
        }

        return ['qualification' => $qualification, 'registration' => $registration];
    }

    /** The supporting-document batch, in submitted order; untouched rows are skipped. */
    private function uploadedDocuments(Request $request): array
    {
        $types = $request->input('documents', []);
        $files = $request->file('documents', []);
        if (!is_array($types) || !is_array($files)) {
            return [];
        }

        $documents = [];
        foreach (array_keys($types + $files) as $key) {
            $category = trim((string) (is_array($types[$key] ?? null) ? ($types[$key]['category'] ?? '') : ''));
            $file = $files[$key]['file'] ?? null;

            if (($file === null || $file === '') && $category === '') {
                continue;   // untouched repeater row
            }

            $errors = [];
            if ($category === '' || !array_key_exists($category, StaffDocument::CATEGORIES)) {
                $errors["documents.{$key}.category"][] = 'Choose a document type.';
            }
            if (!($file instanceof UploadedFile) || !$file->isValid()) {
                $errors["documents.{$key}.file"][] = 'Choose a file to upload.';
            } elseif ($problem = StaffDocumentStorage::validate($file)) {
                $errors["documents.{$key}.file"][] = $problem;
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $documents[] = ['category' => $category, 'file' => $file];
        }

        if (count($documents) > self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages([
                'documents' => ['Upload at most '.self::MAX_DOCUMENTS.' documents at a time.'],
            ]);
        }

        return $documents;
    }

    /** The meaningful "before" values for the audit entry. No NIN, ever. */
    private function auditBefore(User $member): array
    {
        $information = $this->information($member);

        return [
            'name' => $member->name,
            'email' => $member->email,
            'department_id' => $member->department_id,
            'designation_id' => $member->designation_id,
            'employment_type' => $member->employment_type,
            'staff_status' => $member->staff_status,
            'gender' => $information['gender'] ?? null,
            'phone' => $information['phone'] ?? null,
            'address' => $information['address'] ?? null,
        ];
    }
}
