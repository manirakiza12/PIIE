<?php

namespace App\Support\Staff;

use App\Mail\NewUserEmail;
use App\Models\User;
use App\Support\ProfilePhoto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The one place a staff member's login account is created.
 *
 * Extracted verbatim from the five AdminController create handlers (Admin,
 * Teacher, Accountant, Librarian, Warden), which differed only in role_id and
 * the Admin-only school_role marker. Those handlers now call provision() and
 * behave exactly as before (StaffCreationCharacterizationTest pins it). The
 * professional staff workflow will call the same method and pass $withinTransaction
 * to write the staff profile / qualifications / registrations / experience /
 * document metadata in the SAME transaction as the user.
 *
 * users stays the authoritative staff identity: name, email, role_id (base
 * role), school_id, department/designation, employment type, staff status,
 * staff number (STF-YYYY-XXXX-XXXX) and the user_information JSON live there
 * and are never copied elsewhere. One person = one user account; additional
 * responsibilities are RBAC custom roles, never another user.
 *
 * Credentials are emailed only after the transaction has committed, so a
 * rolled-back creation never sends a password for an account that does not exist.
 */
class StaffProvisioningService
{
    public const PHOTO_ERROR = 'Profile photo must be a JPG or PNG image of at most 4 MB.';
    public const DUPLICATE_EMAIL_ERROR = 'Email was already taken.';

    /** The base roles that have a staff creation workflow (role_id => key). */
    public const BASE_ROLES = [2 => 'admin', 3 => 'teacher', 4 => 'accountant', 5 => 'librarian', 10 => 'warden', 20 => 'staff'];

    private const SCHOOL_ADMIN = 2;

    /** HR Manager, as defined by SchoolAdminMiddleware's 'school_admin:hr' scope. */
    private const HR_MANAGER = 15;

    /**
     * Base roles $actor may create — mirrors the guards on the existing create
     * routes: Admin needs 'school_admin' (role 2); the other four 'school_admin:hr'
     * (role 2 or HR Manager 15). Disabled or portal-blocked accounts create nothing.
     *
     * @return int[]
     */
    public static function creatableRoleIds(?User $actor): array
    {
        if (!$actor || $actor->account_status === 'disable' || $actor->isStaffPortalBlocked()) {
            return [];
        }

        return match ((int) $actor->role_id) {
            self::SCHOOL_ADMIN => array_keys(self::BASE_ROLES),
            self::HR_MANAGER => [3, 4, 5, 10],
            default => [],
        };
    }

    /**
     * Creates a staff user of $roleId in $schoolId from the staff form fields.
     *
     * @param  callable(User): void|null  $withinTransaction  extra writes that must commit or roll back with the user
     * @throws StaffProvisioningException  with the exact user-facing message of the original handlers
     */
    public function provision(int $roleId, array $data, int $schoolId, ?callable $withinTransaction = null): User
    {
        if (!array_key_exists($roleId, self::BASE_ROLES)) {
            throw new \InvalidArgumentException("Role {$roleId} has no staff creation workflow.");
        }

        // Order preserved from the original handlers: photo first, then the duplicate-email check.
        $photo = '';
        if (!empty($data['photo'])) {
            $photo = ProfilePhoto::store($data['photo']);
            if ($photo === null) {
                throw new StaffProvisioningException(self::PHOTO_ERROR);
            }
        }

        $info = [
            'gender' => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday' => strtotime($data['birthday']),
            'phone' => $data['phone'],
            'address' => $data['address'],
            'photo' => $photo,
        ];
        if ($roleId === 2) {
            $info['school_role'] = 0;   // Admin flow marker (the users.school_role column is left untouched)
        }

        // Same (case-sensitive, collection-based) duplicate check as the original handlers.
        if (count(User::get()->where('email', $data['email'])) !== 0) {
            throw new StaffProvisioningException(self::DUPLICATE_EMAIL_ERROR);
        }

        $password = self::resolvePassword($data);

        $user = DB::transaction(function () use ($data, $roleId, $schoolId, $info, $password, $withinTransaction) {
            $user = User::create(array_merge(self::staffFields($data), [
                'email' => $data['email'],
                'password' => Hash::make($password['plain']),
                'role_id' => (string) $roleId,
                'school_id' => $schoolId,
                'user_information' => json_encode($info),
                'status' => 1,
                'code' => staff_code(),
                'staff_status' => $roleId === \App\Support\Roles\SystemRole::GENERIC_STAFF
                    ? ($data['staff_status'] ?? StaffStatus::ACTIVE)
                    : StaffStatus::ACTIVE,
                'force_password_change' => $password['force_change'],
            ]));

            if ($withinTransaction) {
                $withinTransaction($user);
            }

            return $user;
        });

        // Only reached after COMMIT.
        $this->sendCredentials($user->email, $user->name, $password['plain']);

        return $user;
    }

    /**
     * Common staff creation with the Next of Kin recorded, for ANY base role.
     *
     * The Next of Kin lives on the shared staff_profiles record, so it is
     * captured once here rather than per role: Admin, Lecturer, Accountant,
     * Librarian, Warden and Other Staff all get the same governed block, and a
     * Next of Kin is never created as a system user.
     *
     * Delegates to provision() so the existing behaviour - photo, duplicate
     * email check, staff number, password handling, credentials email after
     * COMMIT - is unchanged, and the profile is written inside the same
     * transaction through the same StaffRecordService the edit screens use.
     *
     * @param  array  $data  the account fields plus the emergency_contact_* block
     * @param  array  $professional  optional existing professional records, written in the
     *                                same transaction. Keys: 'qualifications' (staff_qualifications
     *                                rows), 'registrations' (staff_professional_registrations rows),
     *                                'documents' (['category' => …, 'file' => UploadedFile, 'ref' => …]).
     *                                A qualification or registration may point at one of those
     *                                documents with 'evidence_document_ref'. All of it reuses the
     *                                existing StaffRecordService writers, so there is no second
     *                                document store and no second qualification table.
     */
    public function provisionWithNextOfKin(int $roleId, array $data, User $actor, array $professional = []): User
    {
        if (! in_array($roleId, self::creatableRoleIds($actor), true)) {
            throw new AuthorizationException('You may not create this type of staff member.');
        }

        $profile = $this->profileFor($data);
        $qualifications = $professional['qualifications'] ?? [];
        $registrations = $professional['registrations'] ?? [];
        $documents = $professional['documents'] ?? [];

        $storedKeys = [];
        try {
            return $this->provision($roleId, $data, (int) $actor->school_id,
                function (User $user) use ($actor, $profile, $qualifications, $registrations, $documents, &$storedKeys): void {
                    $records = app(StaffRecordService::class);

                    // Documents first, so a qualification can cite one as its evidence
                    // by the same in-request reference.
                    $documentIds = [];
                    foreach ($documents as $index => $document) {
                        $stored = $records->uploadDocument($actor, $user, $document['file'], (string) $document['category'], true);
                        $storedKeys[] = $stored->storage_key;
                        $documentIds[(string) ($document['ref'] ?? $index)] = $stored->id;
                    }
                    $withEvidence = function (array $row) use ($documentIds): array {
                        if (isset($row['evidence_document_ref'])) {
                            $row['evidence_document_id'] = $documentIds[(string) $row['evidence_document_ref']]
                                ?? throw new StaffRecordException('An evidence document reference does not match an uploaded document.');
                        }
                        unset($row['evidence_document_ref']);

                        return $row;
                    };

                    $records->saveProfile($actor, $user, $profile, true);
                    foreach ($qualifications as $row) {
                        $records->addQualification($actor, $user, $withEvidence($row), true);
                    }
                    foreach ($registrations as $row) {
                        $records->addRegistration($actor, $user, $withEvidence($row), true);
                    }
                });
        } catch (\Throwable $e) {
            // Anything already written to the private store is removed, so a failed
            // creation leaves no orphaned file behind.
            foreach ($storedKeys as $key) {
                StaffDocumentStorage::delete($key);
            }

            throw $e;
        }
    }

    /**
     * Everything this request wants on the shared staff_profiles record: the
     * controlled title, the professional extras the profile owns, the Next of
     * Kin block, and the NIN. Reduced to exactly the profile fields it owns and
     * validated once, so the creation form and the profile screens cannot drift.
     *
     * An empty block simply means the staff member has nothing recorded yet on
     * those optional fields; only the Next of Kin is mandatory on creation.
     */
    private function profileFor(array $data): array
    {
        $nokKeys = [
            'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_email', 'emergency_contact_phone',
            'emergency_contact_alternative_phone', 'emergency_contact_address',
        ];
        $block = array_intersect_key($data, array_flip(array_merge(
            $nokKeys,
            ['title', 'title_other', 'city', 'country', 'years_teaching_experience', 'nin']
        )));

        $hasNextOfKin = array_filter(
            array_intersect_key($block, array_flip($nokKeys)),
            fn ($value) => trim((string) $value) !== ''
        ) !== [];

        // A controlled title, with the "Other" description folded into the existing
        // column rather than adding one.
        if (trim((string) ($block['title'] ?? '')) !== '') {
            $block['title'] = StaffTitle::normalise($block['title'], $block['title_other'] ?? null);
        }
        unset($block['title_other']);

        // A controlled relationship, likewise folded into the existing column.
        if ($hasNextOfKin) {
            $block['emergency_contact_relationship'] = StaffNextOfKin::normalise(
                $block['emergency_contact_relationship'] ?? null,
                $data['emergency_contact_relationship_other'] ?? null
            );
        }

        // One source of truth for the block, so staff creation cannot drift from
        // the profile screens; creation is the strict case because a new record
        // must capture the Next of Kin in full.
        $rules = array_merge(
            array_intersect_key(StaffRecordService::nextOfKinRules(), array_flip($nokKeys)),
            array_intersect_key(StaffRecordService::profileRules(), array_flip(['title', 'city', 'country', 'years_teaching_experience'])),
            ['nin' => ['nullable', 'string', 'min:'.StaffNin::MIN_LENGTH, 'max:'.StaffNin::MAX_LENGTH]]
        );

        $validator = Validator::make($block, $rules, StaffRecordService::messagesFor($rules));
        if ($validator->fails()) {
            throw new StaffRecordException('The staff record details are not valid.', $validator->errors()->toArray());
        }

        $clean = $validator->validated();
        // The NIN is only written when one was actually supplied, so an omitted
        // field never blanks a value.
        if (trim((string) ($clean['nin'] ?? '')) === '') {
            unset($clean['nin']);
        }

        return $clean;
    }

    /**
     * Professional staff creation (the future full-page workflow): the user AND all
     * professional records commit together or not at all; credentials are emailed
     * only after COMMIT; any file already stored is deleted if anything fails.
     *
     * $account      the same fields as the existing forms (first/last name, email, phone,
     *               gender, birthday, address, photo, department/designation, employment
     *               type, password_mode/password)
     * $profile      StaffRecordService::profileRules() fields + 'nin' (REQUIRED for new staff)
     * $documents    [['category' => …, 'file' => UploadedFile, 'ref' => optional key], …]
     * $qualifications / $registrations may point at one of those documents with
     *               'evidence_document_ref' => the document's 'ref' (or list index)
     *
     * @throws AuthorizationException  actor may not create this base role
     * @throws StaffRecordException     invalid input (nothing was created)
     * @throws StaffProvisioningException  bad photo / email taken (nothing was created)
     */
    public function provisionProfessional(User $actor, int $roleId, array $account, array $profile, array $qualifications = [],
        array $registrations = [], array $experiences = [], array $documents = []): User
    {
        if (!in_array($roleId, self::creatableRoleIds($actor), true)) {
            throw new AuthorizationException('You may not create this type of staff member.');
        }

        $this->validateProfessional($account, $profile, $qualifications, $registrations, $experiences, $documents);
        $account += ['gender' => '', 'blood_group' => '', 'birthday' => '', 'phone' => '', 'address' => ''];

        $storedKeys = [];
        try {
            return $this->provision($roleId, $account, (int) $actor->school_id,
                function (User $user) use ($actor, $profile, $qualifications, $registrations, $experiences, $documents, &$storedKeys) {
                    $records = app(StaffRecordService::class);

                    $documentIds = [];
                    foreach ($documents as $index => $document) {
                        $stored = $records->uploadDocument($actor, $user, $document['file'], (string) $document['category'], true);
                        $storedKeys[] = $stored->storage_key;
                        $documentIds[(string) ($document['ref'] ?? $index)] = $stored->id;
                    }
                    $withEvidence = function (array $row) use ($documentIds): array {
                        if (isset($row['evidence_document_ref'])) {
                            $row['evidence_document_id'] = $documentIds[(string) $row['evidence_document_ref']]
                                ?? throw new StaffRecordException('An evidence document reference does not match an uploaded document.');
                        }
                        unset($row['evidence_document_ref']);

                        return $row;
                    };

                    $records->saveProfile($actor, $user, $profile, true);
                    foreach ($qualifications as $row) {
                        $records->addQualification($actor, $user, $withEvidence($row), true);
                    }
                    foreach ($registrations as $row) {
                        $records->addRegistration($actor, $user, $withEvidence($row), true);
                    }
                    foreach ($experiences as $row) {
                        $records->addExperience($actor, $user, $row, true);
                    }
                });
        } catch (\Throwable $e) {
            foreach ($storedKeys as $key) {
                StaffDocumentStorage::delete($key);
            }
            throw $e;
        }
    }

    /** Everything that can be checked before anything is written. */
    private function validateProfessional(array $account, array $profile, array $qualifications, array $registrations, array $experiences, array $documents): void
    {
        $errors = [];
        $check = function (array $data, array $rules, string $prefix) use (&$errors) {
            $validator = Validator::make(array_intersect_key($data, $rules), $rules);
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[$prefix . $field] = $messages[0];
            }
        };

        $check($account, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:50'],
        ], '');
        if (!StaffNin::isValid($profile['nin'] ?? null)) {
            $errors['nin'] = 'A valid NIN (5 to 30 letters or digits) is required.';
        }
        $check($profile, StaffRecordService::profileRules(), '');
        foreach ($qualifications as $i => $row) {
            $check($row, StaffRecordService::qualificationRules(), "qualifications.{$i}.");
        }
        foreach ($registrations as $i => $row) {
            $check($row, StaffRecordService::registrationRules(), "registrations.{$i}.");
        }
        foreach ($experiences as $i => $row) {
            $check($row, StaffRecordService::experienceRules(), "experiences.{$i}.");
        }
        foreach ($documents as $i => $document) {
            if (!array_key_exists((string) ($document['category'] ?? ''), \App\Models\StaffDocument::CATEGORIES) || !($document['file'] ?? null) instanceof UploadedFile) {
                $errors["documents.{$i}"] = 'Each document needs a valid category and a file.';
            }
        }

        if ($errors) {
            throw new StaffRecordException('Some staff details are missing or invalid.', $errors);
        }
    }

    /**
     * Shared Staff Module field prep: split-name recombination plus
     * department/designation FK and employment type. Used by every
     * staff-role create/update so the five roles stay consistent.
     */
    public static function staffFields(array $data): array
    {
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        return [
            'name' => trim("{$firstName} {$lastName}"),
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'department_id' => $data['department_id'] ?? null,
            'designation_id' => $data['designation_id'] ?? null,
            'employment_type' => $data['employment_type'] ?? null,
        ];
    }

    /**
     * An auto-generated temporary password (forces a change on first login) or
     * an administrator-chosen one (no forced change). The plaintext only lives
     * in memory long enough to hash and email — never persisted or logged.
     */
    public static function resolvePassword(array $data): array
    {
        if (($data['password_mode'] ?? 'auto') === 'manual' && !empty($data['password'])) {
            return ['plain' => $data['password'], 'force_change' => false];
        }

        return ['plain' => Str::random(10), 'force_change' => true];
    }

    /** Emails login credentials when SMTP is configured (otherwise silently skipped, as before). */
    public function sendCredentials(string $email, string $name, string $plainPassword): void
    {
        if (!empty(get_settings('smtp_user')) && get_settings('smtp_pass') && get_settings('smtp_host') && get_settings('smtp_port')) {
            \App\Support\Mail\SafeMail::send($email, new NewUserEmail([
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword,
            ]), 'staff-credentials');
        }
    }
}
