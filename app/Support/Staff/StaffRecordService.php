<?php

namespace App\Support\Staff;

use App\Models\AuditLog;
use App\Models\StaffDocument;
use App\Models\StaffExperience;
use App\Models\StaffProfessionalRegistration;
use App\Models\StaffProfile;
use App\Models\StaffQualification;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * The only way staff professional records are written, verified or revealed.
 *
 * Every operation checks, before touching anything:
 *   1. permission  — the specific RBAC key (staff.view never implies the
 *                    sensitive ones: documents, NIN, verification, export);
 *   2. tenant      — target user / record belongs to the actor's school; a
 *                    record of another school is indistinguishable from a
 *                    missing one (ModelNotFoundException → 404, nothing revealed);
 *   3. target      — a staff member (never a parent, student or Super Admin).
 *
 * Validation failures throw StaffRecordException (field messages, never the
 * submitted NIN). Sensitive changes are written to the existing audit log with
 * safe values only: no NIN (plain/encrypted/hashed), no storage path.
 */
class StaffRecordService
{
    /** Not staff: Super Admin (platform), Parent, Student, reserved legacy role 8. */
    private const NOT_STAFF = [1, 6, 7, 8];

    /**
     * One contact-number pattern for the whole staff module, so a number the
     * profile screen accepts is never rejected by staff creation (or the other
     * way round). It accepts an international prefix and the spacing, dashes and
     * brackets used in practice, and requires digits at both ends so free text
     * such as "call me at the office" is refused.
     */
    public const PHONE_RULE = 'regex:/^[+()0-9][0-9 ()\\-]{4,24}[0-9]$/';

    /**
     * The Next of Kin block as a NEW staff record must supply it: all four
     * identity fields present, and contact numbers in a usable format.
     *
     * Kept separate from profileRules() because that is also the rule set for
     * editing an existing profile, where a staff member may legitimately have
     * no Next of Kin recorded yet, or a number stored before this rule existed.
     */
    public static function nextOfKinRules(bool $strict = true): array
    {
        $rules = array_intersect_key(self::profileRules(), array_flip([
            'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_email',
            'emergency_contact_phone', 'emergency_contact_alternative_phone', 'emergency_contact_address',
        ]));

        $rules['emergency_contact_phone'] = ['nullable', 'string', 'max:50', self::PHONE_RULE];
        $rules['emergency_contact_alternative_phone'] = ['nullable', 'string', 'max:50', self::PHONE_RULE];

        if ($strict) {
            foreach (['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_email', 'emergency_contact_phone'] as $field) {
                $rules[$field] = array_values(array_diff($rules[$field], ['nullable']));
                array_unshift($rules[$field], 'required');
            }
        }

        return $rules;
    }

    public function __construct(private PermissionService $permissions)
    {
    }

    // ── Tenant resolution ───────────────────────────────────────────────────

    /** A staff member of $actor's school, or 404. */
    public function staffInSchool(User $actor, int $userId): User
    {
        return User::where('id', $userId)->where('school_id', (int) $actor->school_id)
            ->whereNotIn('role_id', self::NOT_STAFF)->firstOrFail();
    }

    public function profileOf(User $actor, User $target): ?StaffProfile
    {
        $this->authorize($actor, 'staff.view');
        $this->assertSameSchool($actor, $target);

        return StaffProfile::inSchool((int) $actor->school_id)->where('user_id', $target->id)->first();
    }

    // ── Profile & NIN ───────────────────────────────────────────────────────

    /**
     * Creates or updates $target's profile. A 'nin' key records/replaces the NIN
     * and needs staff.nin.manage (or $creating, inside an authorized creation).
     */
    public function saveProfile(User $actor, User $target, array $data, bool $creating = false): StaffProfile
    {
        $this->assertSameSchool($actor, $target);
        $this->authorizeWrite($actor, $target, 'staff.edit', $creating);
        $clean = $this->validated($data, self::profileRules(), 'profile');

        $profile = StaffProfile::firstOrNew(['user_id' => $target->id], ['school_id' => (int) $target->school_id]);
        $isNew = !$profile->exists;
        $profile->fill(array_intersect_key($clean, array_flip((new StaffProfile())->getFillable())));
        $profile->school_id = (int) $target->school_id;
        $profile->updated_by = $actor->id;
        if ($isNew) {
            $profile->created_by = $actor->id;
        }

        $ninAction = null;
        if (array_key_exists('nin', $data) && trim((string) $data['nin']) !== '') {
            if (!($creating && in_array((int) $target->role_id, StaffProvisioningService::creatableRoleIds($actor), true))) {
                $this->authorize($actor, 'staff.nin.manage');
            }
            $ninAction = $profile->hasNin() ? 'STAFF_NIN_REPLACED' : 'STAFF_NIN_RECORDED';
            StaffNin::assign($profile, (string) $data['nin']);
        }

        $changed = array_keys(array_diff_key($profile->getDirty(), array_flip(['nin_encrypted', 'nin_hash', 'updated_by', 'created_by', 'updated_at', 'created_at'])));
        $profile->save();

        $this->audit($isNew ? 'STAFF_PROFILE_CREATED' : 'STAFF_PROFILE_UPDATED', $target, $isNew ? 'Created staff profile' : 'Updated staff profile',
            ['fields' => $changed]);
        if ($ninAction) {
            $this->audit($ninAction, $target, $ninAction === 'STAFF_NIN_RECORDED' ? 'Recorded NIN' : 'Replaced NIN', ['nin' => 'recorded']);
        }

        return $profile;
    }

    /** The full NIN (staff.nin.view), audited; null when none is recorded. */
    public function revealNin(User $actor, User $target): ?string
    {
        $this->authorize($actor, 'staff.nin.view');
        $profile = $this->profileOf($actor, $target);
        if (!$profile || !$profile->hasNin()) {
            return null;
        }
        $this->audit('STAFF_NIN_VIEWED', $target, 'Viewed NIN');

        return StaffNin::decrypt($profile);
    }

    /** What a profile screen may show: full NIN only with staff.nin.view, else "NIN recorded" / "Not provided". */
    public function ninDisplay(User $actor, User $target): string
    {
        $profile = $this->profileOf($actor, $target);
        if (!$profile || !$profile->hasNin()) {
            return 'Not provided';
        }

        return $this->permissions->allows($actor, 'staff.nin.view') ? (string) StaffNin::masked($profile) : 'NIN recorded';
    }

    // ── Qualifications, registrations, experience ───────────────────────────

    public function addQualification(User $actor, User $target, array $data, bool $creating = false): StaffQualification
    {
        $this->assertSameSchool($actor, $target);
        $this->authorizeWrite($actor, $target, 'staff.edit', $creating);
        $clean = $this->validated($data, self::qualificationRules(), 'qualification');
        $this->assertOrdered($clean, 'start_year', 'completion_year', 'The completion year cannot be before the start year.');
        $clean['evidence_document_id'] = $this->evidenceFor($target, $clean['evidence_document_id'] ?? null);

        $qualification = StaffQualification::create($clean + ['user_id' => $target->id, 'school_id' => (int) $target->school_id, 'created_by' => $actor->id]);
        $this->audit('STAFF_QUALIFICATION_ADDED', $target, 'Added qualification', ['qualification_id' => $qualification->id, 'level' => $qualification->qualification_level]);

        return $qualification;
    }

    public function addRegistration(User $actor, User $target, array $data, bool $creating = false): StaffProfessionalRegistration
    {
        $this->assertSameSchool($actor, $target);
        $this->authorizeWrite($actor, $target, 'staff.edit', $creating);
        $clean = $this->validated($data, self::registrationRules(), 'registration');
        $this->assertOrdered($clean, 'issue_date', 'expiry_date', 'The expiry date cannot be before the issue date.');
        $clean['evidence_document_id'] = $this->evidenceFor($target, $clean['evidence_document_id'] ?? null);

        $registration = StaffProfessionalRegistration::create($clean + ['user_id' => $target->id, 'school_id' => (int) $target->school_id, 'created_by' => $actor->id]);
        $this->audit('STAFF_REGISTRATION_ADDED', $target, 'Added professional registration', ['registration_id' => $registration->id]);

        return $registration;
    }

    /**
     * Corrects an EXISTING qualification in place, so editing a staff record
     * never appends a second row for the same award.
     *
     * The row must already belong to $target and to $actor's school; anything
     * else is a 404, exactly as for a missing record. A field the submitted form
     * did not send (evidence_document_id, which the staff edit form does not
     * manage) is left as it is rather than blanked.
     */
    public function updateQualification(User $actor, User $target, int $qualificationId, array $data): StaffQualification
    {
        $this->assertSameSchool($actor, $target);
        $this->authorize($actor, 'staff.edit');
        $qualification = $this->professionalRow(StaffQualification::class, $actor, $target, $qualificationId);
        $clean = $this->validated($data, self::qualificationRules(), 'qualification');
        $this->assertOrdered($clean, 'start_year', 'completion_year', 'The completion year cannot be before the start year.');
        if (array_key_exists('evidence_document_id', $clean)) {
            $clean['evidence_document_id'] = $this->evidenceFor($target, $clean['evidence_document_id']);
        }

        $old = $qualification->only(array_keys($clean));
        $qualification->fill($clean)->save();
        $this->audit('STAFF_QUALIFICATION_UPDATED', $target, 'Updated qualification',
            ['qualification_id' => $qualification->id] + $clean, $old);

        return $qualification;
    }

    /** Corrects an EXISTING professional registration in place; see updateQualification(). */
    public function updateRegistration(User $actor, User $target, int $registrationId, array $data): StaffProfessionalRegistration
    {
        $this->assertSameSchool($actor, $target);
        $this->authorize($actor, 'staff.edit');
        $registration = $this->professionalRow(StaffProfessionalRegistration::class, $actor, $target, $registrationId);
        $clean = $this->validated($data, self::registrationRules(), 'registration');
        $this->assertOrdered($clean, 'issue_date', 'expiry_date', 'The expiry date cannot be before the issue date.');
        if (array_key_exists('evidence_document_id', $clean)) {
            $clean['evidence_document_id'] = $this->evidenceFor($target, $clean['evidence_document_id']);
        }

        $old = $registration->only(array_keys($clean));
        $registration->fill($clean)->save();
        $this->audit('STAFF_REGISTRATION_UPDATED', $target, 'Updated professional registration',
            ['registration_id' => $registration->id] + $clean, $old);

        return $registration;
    }

    public function addExperience(User $actor, User $target, array $data, bool $creating = false): StaffExperience
    {
        $this->assertSameSchool($actor, $target);
        $this->authorizeWrite($actor, $target, 'staff.edit', $creating);
        $clean = $this->validated($data, self::experienceRules(), 'experience');
        $this->assertOrdered($clean, 'start_date', 'end_date', 'The end date cannot be before the start date.');
        $clean['currently_working'] = (bool) ($clean['currently_working'] ?? false);

        $experience = StaffExperience::create($clean + ['user_id' => $target->id, 'school_id' => (int) $target->school_id, 'created_by' => $actor->id]);
        $this->audit('STAFF_EXPERIENCE_ADDED', $target, 'Added employment experience', ['experience_id' => $experience->id]);

        return $experience;
    }

    public function verifyQualification(User $actor, int $qualificationId, string $status): StaffQualification
    {
        $this->authorize($actor, 'staff.qualifications.verify');
        $qualification = StaffQualification::inSchool((int) $actor->school_id)->findOrFail($qualificationId);
        $this->assertStatus($status, StaffQualification::VERIFICATION_STATUSES);

        $before = $qualification->verification_status;
        $qualification->forceFill(['verification_status' => $status, 'verified_by' => $actor->id, 'verified_at' => now()])->save();
        $this->audit('STAFF_QUALIFICATION_VERIFIED', $qualification->user, "Qualification marked {$status}",
            ['qualification_id' => $qualification->id, 'verification_status' => $status], ['verification_status' => $before]);

        return $qualification;
    }

    // ── Documents ───────────────────────────────────────────────────────────

    public function uploadDocument(User $actor, User $target, UploadedFile $file, string $category, bool $creating = false): StaffDocument
    {
        $this->assertSameSchool($actor, $target);
        $this->authorizeWrite($actor, $target, 'staff.documents.upload', $creating);
        if (!array_key_exists($category, StaffDocument::CATEGORIES)) {
            throw new StaffRecordException('Choose a valid document category.', ['category' => 'Choose a valid document category.']);
        }

        $stored = StaffDocumentStorage::store($file, (int) $target->school_id, (int) $target->id);
        try {
            $document = StaffDocument::create($stored + ['user_id' => $target->id, 'school_id' => (int) $target->school_id, 'category' => $category, 'uploaded_by' => $actor->id]);
        } catch (\Throwable $e) {
            StaffDocumentStorage::delete($stored['storage_key']);
            throw $e;
        }
        $this->audit('STAFF_DOCUMENT_UPLOADED', $target, 'Uploaded staff document', ['document_id' => $document->id, 'category' => $category]);

        return $document;
    }

    public function verifyDocument(User $actor, int $documentId, string $status, ?string $notes = null): StaffDocument
    {
        $this->authorize($actor, 'staff.documents.verify');
        $document = StaffDocument::inSchool((int) $actor->school_id)->findOrFail($documentId);
        $this->assertStatus($status, StaffDocument::VERIFICATION_STATUSES);

        $before = $document->verification_status;
        $document->forceFill(['verification_status' => $status, 'verified_by' => $actor->id, 'verified_at' => now(),
            'verification_notes' => $notes !== null ? mb_substr($notes, 0, 2000) : null])->save();
        $this->audit('STAFF_DOCUMENT_VERIFIED', $document->user, "Document marked {$status}",
            ['document_id' => $document->id, 'verification_status' => $status], ['verification_status' => $before]);

        return $document;
    }

    /**
     * The document $actor may download, resolved ONLY by id within $actor's school
     * (staff.documents.view). Another school's id is a plain 404.
     *
     * @return array{0: StaffDocument, 1: string}  the document and its absolute private path
     */
    public function documentForDownload(User $actor, int $documentId): array
    {
        $this->authorize($actor, 'staff.documents.view');
        $document = StaffDocument::inSchool((int) $actor->school_id)->findOrFail($documentId);
        $path = StaffDocumentStorage::path($document->storage_key);
        if (!$path || !is_file($path)) {
            throw (new ModelNotFoundException())->setModel(StaffDocument::class, [$documentId]);
        }
        $this->audit('STAFF_DOCUMENT_DOWNLOADED', $document->user, 'Downloaded staff document', ['document_id' => $document->id, 'category' => $document->category]);

        return [$document, $path];
    }

    // ── Validation rules (shared with the professional creation workflow) ───

    public static function profileRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:30'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'string', 'max:30'],
            'religion' => ['nullable', 'string', 'max:100'],
            'alternative_phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'date_joined' => ['nullable', 'date'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:60'],
            'emergency_contact_email' => ['nullable', 'email:filter', 'max:191'],
            // Format only. A staff member who was recorded before contact numbers
            // were validated keeps the number already on file, so the strict
            // requirement below applies to creation, not to these profile rules.
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_alternative_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_address' => ['nullable', 'string', 'max:255'],
            'academic_title' => ['nullable', 'string', 'max:100'],
            'specialisation' => ['nullable', 'string', 'max:191'],
            'years_teaching_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'research_interests' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public static function qualificationRules(): array
    {
        $max = (int) date('Y') + 10;

        return [
            'qualification_level' => ['required', 'string', 'max:50'],
            'qualification_name' => ['required', 'string', 'max:191'],
            'specialisation' => ['nullable', 'string', 'max:191'],
            'institution' => ['required', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:100'],
            'start_year' => ['nullable', 'integer', 'min:1900', "max:{$max}"],
            'completion_year' => ['nullable', 'integer', 'min:1900', "max:{$max}"],
            'grade_or_class' => ['nullable', 'string', 'max:100'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'evidence_document_id' => ['nullable', 'integer'],
        ];
    }

    public static function registrationRules(): array
    {
        return [
            'professional_body' => ['required', 'string', 'max:191'],
            'membership_number' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'evidence_document_id' => ['nullable', 'integer'],
        ];
    }

    public static function experienceRules(): array
    {
        return [
            'employer' => ['required', 'string', 'max:191'],
            'position' => ['required', 'string', 'max:191'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'currently_working' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function authorize(User $actor, string $key): void
    {
        if (!$this->permissions->allows($actor, $key)) {
            throw new AuthorizationException('You do not have permission to do that.');
        }
    }

    /**
     * Writes need $key — except while creating a staff member, where the right to create
     * that base role (StaffProvisioningService::creatableRoleIds) covers the initial records.
     */
    private function authorizeWrite(User $actor, User $target, string $key, bool $creating): void
    {
        if ($creating && in_array((int) $target->role_id, StaffProvisioningService::creatableRoleIds($actor), true)) {
            return;
        }
        $this->authorize($actor, $key);
    }

    /** $to may not precede $from; only checked when both are present (years as integers, otherwise dates). */
    private function assertOrdered(array $clean, string $from, string $to, string $message): void
    {
        $start = $clean[$from] ?? null;
        $end = $clean[$to] ?? null;
        if ($start === null || $start === '' || $end === null || $end === '') {
            return;
        }

        $before = is_numeric($start) && is_numeric($end)
            ? (int) $end < (int) $start
            : strtotime((string) $end) < strtotime((string) $start);
        if ($before) {
            throw new StaffRecordException($message, [$to => $message]);
        }
    }

    private function assertSameSchool(User $actor, User $target): void
    {
        if (empty($target->school_id) || (int) $target->school_id !== (int) $actor->school_id || in_array((int) $target->role_id, self::NOT_STAFF, true)) {
            throw (new ModelNotFoundException())->setModel(User::class, [$target->id]);
        }
    }

    /** An evidence document must belong to the same staff member (and so the same school). */
    private function evidenceFor(User $target, $documentId): ?int
    {
        if ($documentId === null || $documentId === '') {
            return null;
        }
        $exists = StaffDocument::inSchool((int) $target->school_id)->where('user_id', $target->id)->whereKey((int) $documentId)->exists();
        if (!$exists) {
            throw new StaffRecordException('The evidence document must be one of this staff member\'s documents.',
                ['evidence_document_id' => 'The evidence document must be one of this staff member\'s documents.']);
        }

        return (int) $documentId;
    }

    /**
     * A professional record that already belongs to $target inside $actor's
     * school. A row of another staff member, or of another school, is
     * indistinguishable from a missing one (404) so an id cannot be probed.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param  class-string<T>  $model
     * @return T
     */
    private function professionalRow(string $model, User $actor, User $target, int $id)
    {
        return $model::where('school_id', (int) $actor->school_id)
            ->where('user_id', $target->id)
            ->findOrFail($id);
    }

    private function assertStatus(string $status, array $allowed): void
    {
        if (!in_array($status, $allowed, true)) {
            throw new StaffRecordException('Invalid verification status.', ['verification_status' => 'Invalid verification status.']);
        }
    }

    private function validated(array $data, array $rules, string $what): array
    {
        $validator = Validator::make(array_intersect_key($data, $rules), $rules, self::messagesFor($rules));
        if ($validator->fails()) {
            $errors = array_map(fn ($messages) => $messages[0], $validator->errors()->toArray());
            throw new StaffRecordException("The {$what} details are not valid.", $errors);
        }

        return $validator->validated();
    }

    /**
     * Staff-facing wording. Laravel's defaults leak input keys and rule names
     * ("The emergency contact email must be a valid email address"), which is
     * not language an administrator should have to read.
     */
    public static function messagesFor(array $rules): array
    {
        $messages = [
            'email' => 'Enter a valid email address.',
            'max' => 'This value is longer than allowed.',
            'required_with' => 'Complete the Next of Kin details: name, relationship, email and contact number.',
        ];

        return [
            'emergency_contact_name.required_with' => 'Enter the Next of Kin full name.',
            'emergency_contact_name.max' => 'The Next of Kin name may not be longer than 150 characters.',
            'emergency_contact_relationship.required_with' => 'Choose how the Next of Kin is related to this staff member.',
            'emergency_contact_relationship.max' => 'The Next of Kin relationship may not be longer than 60 characters.',
            'emergency_contact_email.email' => 'Enter a valid Next of Kin email address.',
            'emergency_contact_email.max' => 'The Next of Kin email address may not be longer than 191 characters.',
            'emergency_contact_phone.required_with' => 'Enter the Next of Kin contact number.',
            'emergency_contact_phone.regex' => 'Enter a valid contact number, for example +256 712 345 678.',
            'emergency_contact_phone.max' => 'The Next of Kin contact number may not be longer than 50 characters.',
            'emergency_contact_alternative_phone.regex' => 'Enter a valid alternative contact number, for example +256 712 345 678.',
            'emergency_contact_address.max' => 'The Next of Kin address may not be longer than 255 characters.',
        ];
    }

    private function audit(string $action, ?User $target, string $what, array $new = [], array $old = []): void
    {
        AuditLog::record($action, 'Staff', $target ? "{$what} for staff #{$target->id}" : $what, [
            'school_id' => $target?->school_id,
            'record_type' => User::class,
            'record_id' => $target?->id,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }
}
