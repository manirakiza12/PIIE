<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Models\StaffDocument;
use App\Models\StaffProfile;
use App\Support\Staff\StaffDocumentStorage;
use App\Support\Staff\StaffNextOfKin;
use App\Support\Staff\StaffNin;
use App\Support\Staff\StaffProvisioningException;
use App\Support\Staff\StaffProvisioningService;
use App\Support\Staff\StaffQualificationLevel;
use App\Support\Staff\StaffRecordException;
use App\Support\Staff\StaffRecordService;
use App\Support\Staff\StaffStatus;
use App\Support\Staff\StaffTitle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin -> Staff -> Add Staff -> Create <role>: one professional, full-page
 * staff record form, replacing the narrow drawer for every staff type.
 *
 * Additive: the existing per-role create routes, their validation, password
 * handling and credentials email are untouched. This form posts to
 * StaffProvisioningService::provisionWithNextOfKin(), which delegates to the same
 * provision() the drawer path uses, so staff-number generation, duplicate-email
 * detection, NIN protection, private document storage and the credentials email
 * all behave exactly as before.
 *
 * Portal access stays the existing architecture: the profile is created, the
 * account is left at "setup required", and the administrator sends a secure
 * setup link from Staff Directory -> Manage Access rather than choosing a
 * permanent password here.
 */
class StaffCreateController extends Controller
{
    /** The only blood groups a staff record may record. */
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** Genders offered on the form, matching the existing create flows. */
    public const GENDERS = ['Male', 'Female', 'Other'];

    /** Employment types, as already used by the existing staff create forms. */
    public const EMPLOYMENT_TYPES = ['Full Time', 'Part Time', 'Casual'];

    /**
     * Staff types this form can create, mirroring the launcher's authority.
     * 'academic' marks the types that also capture academic and professional
     * information; the common form is unchanged for the others.
     */
    public const TYPES = [
        'admin' => ['role_id' => 2, 'label' => 'Admin', 'academic' => false],
        'lecturer' => ['role_id' => 3, 'label' => 'Lecturer', 'academic' => true],
        'teacher' => ['role_id' => 3, 'label' => 'Teacher', 'academic' => true],
        'accountant' => ['role_id' => 4, 'label' => 'Accountant', 'academic' => false],
        'librarian' => ['role_id' => 5, 'label' => 'Librarian', 'academic' => false],
        'warden' => ['role_id' => 10, 'label' => 'Warden', 'academic' => false],
        'staff' => ['role_id' => 20, 'label' => 'Other Staff', 'academic' => false],
    ];

    /** Document types offered on the creation form, and the order they appear in. */
    public const UPLOADABLE_DOCUMENTS = [
        'cv', 'academic_certificate', 'national_id', 'professional_certificate',
        'appointment_letter', 'employment_contract', 'academic_transcript', 'other',
    ];

    /** Longest edge of the document repeater, so one request cannot carry 500 files. */
    private const MAX_DOCUMENTS = 10;

    /** Staff-facing description of what the private document store accepts. */
    public const ACCEPTED_MIME_LABELS = 'PDF, JPG or PNG';

    public function create(Request $request, string $type): View
    {
        $config = $this->type($type, $request->user());
        $schoolId = (int) $request->user()->school_id;

        return view('admin.staff.create', [
            'type' => $type,
            'config' => $config,
            'departments' => Department::where('school_id', $schoolId)->orderBy('name')->get(),
            'designations' => Designation::where('school_id', $schoolId)->orderBy('name')->get(),
            'relationships' => StaffNextOfKin::ALL,
            'titles' => StaffTitle::ALL,
            'qualificationLevels' => StaffQualificationLevel::ALL,
            'documentCategories' => $this->documentCategories(),
            'maxKb' => StaffDocumentStorage::maxKb(),
            'maxDocuments' => self::MAX_DOCUMENTS,
            'acceptedMime' => self::ACCEPTED_MIME_LABELS,
            'bloodGroups' => self::BLOOD_GROUPS,
            'genders' => self::GENDERS,
            'employmentTypes' => self::EMPLOYMENT_TYPES,
            'staffStatuses' => StaffStatus::ALL,
        ]);
    }

    public function store(Request $request, string $type)
    {
        $config = $this->type($type, $request->user());
        $schoolId = (int) $request->user()->school_id;

        $data = $request->validate([
            // Personal
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'required', Rule::in(StaffTitle::ALL)],
            'title_other' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', Rule::in(self::GENDERS)],
            // Blank by default: today is never a date of birth.
            'birthday' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'nin' => ['nullable', 'string', 'min:'.StaffNin::MIN_LENGTH, 'max:'.StaffNin::MAX_LENGTH],
            'years_teaching_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'blood_group' => ['nullable', Rule::in(self::BLOOD_GROUPS)],
            'address' => ['nullable', 'string', 'max:1000'],
            // Contact — the staff module's own contact pattern, so this form
            // cannot drift from what the profile screens accept.
            'email' => ['required', 'email:filter', 'max:191'],
            'phone' => ['required', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            'alternative_phone' => ['nullable', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            // Employment
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('school_id', $schoolId)],
            'designation_id' => ['required', 'integer', Rule::exists('designations', 'id')->where('school_id', $schoolId)],
            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],
            'staff_status' => ['required', Rule::in(StaffStatus::ALL)],
            'date_joined' => ['nullable', 'date'],
            // Next of Kin
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['nullable', Rule::in(StaffNextOfKin::ALL)],
            'emergency_contact_relationship_other' => ['nullable', 'string', 'max:40'],
            'emergency_contact_email' => ['nullable', 'email:filter', 'max:191'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            'emergency_contact_alternative_phone' => ['nullable', 'string', 'max:50', StaffRecordService::PHONE_RULE],
            'emergency_contact_address' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ], $this->messages());

        // The Next of Kin is captured for every staff type, so require the whole
        // block together rather than accepting a half-filled emergency contact.
        $this->assertNextOfKinComplete($data);
        $this->assertTitleComplete($data);

        // provision() reads the legacy account fields directly, so an omitted
        // optional field is normalised rather than left undefined.
        $data += [
            'middle_name' => '', 'title' => '', 'nationality' => '', 'nin' => '',
            'blood_group' => '', 'address' => '', 'alternative_phone' => '',
            'department_id' => null, 'date_joined' => null, 'birthday' => '',
            'years_teaching_experience' => null,
        ];

        // The academic block and the supporting documents belong to the types that
        // declare them; every other role keeps exactly the common form.
        $professional = $config['academic']
            ? $this->professionalRecords($request)
            : ['qualifications' => [], 'registrations' => [], 'documents' => []];

        try {
            $user = app(StaffProvisioningService::class)
                ->provisionWithNextOfKin($config['role_id'], $data, $request->user(), $professional);
        } catch (StaffProvisioningException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (StaffRecordException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        // The success screen is reachable only for the record just created, and
        // only once, so it cannot become a way to browse other staff records.
        return redirect()->route('admin.staff.created')
            ->with('staff_created_id', $user->id)
            ->with('staff_created_label', $config['label']);
    }

    /**
     * The post-creation screen: who was created, their staff number, and that
     * portal access is still "setup required" until the administrator chooses to
     * send a secure setup link. It never sends one by itself.
     */
    public function created(Request $request): View|RedirectResponse
    {
        $id = $request->session()->pull('staff_created_id');

        if (! $id) {
            return redirect()->route('admin.staff.add');
        }

        $created = app(StaffRecordService::class)->staffInSchool($request->user(), (int) $id);
        $label = (string) $request->session()->get('staff_created_label', 'Staff');

        return view('admin.staff.created', [
            'created' => $created,
            'label' => $label,
            'profile' => StaffProfile::where('user_id', $created->id)->first(),
        ]);
    }

    // ------------------------------------------------------------ internals

    private function type(string $type, User $actor): array
    {
        $config = self::TYPES[$type] ?? null;
        $allowed = StaffProvisioningService::creatableRoleIds($actor);

        abort_if($config === null, 404);
        abort_unless(in_array($config['role_id'], $allowed, true), 403);

        return $config + ['key' => $type];
    }

    /**
     * The academic and professional records for a Lecturer, mapped onto the
     * EXISTING professional-record architecture:
     *
     *   highest qualification  -> staff_qualifications.qualification_level
     *   field of study         -> staff_qualifications.specialisation
     *   awarding institution   -> staff_qualifications.institution
     *   year awarded           -> staff_qualifications.completion_year
     *   professional body      -> staff_professional_registrations.professional_body
     *   registration number    -> staff_professional_registrations.registration_number
     *   supporting documents   -> staff_documents (private store)
     *
     * No new column and no second table: the qualification is one row in the
     * existing 0..N-per-staff table, so further qualifications can still be
     * added from the staff profile afterwards.
     */
    private function professionalRecords(Request $request): array
    {
        $academic = $request->validate([
            'qualification_level' => ['required', 'string', Rule::in(StaffQualificationLevel::ALL)],
            'qualification_level_other' => ['nullable', 'string', 'max:35'],
            'field_of_study' => ['required', 'string', 'max:191'],
            'institution' => ['required', 'string', 'max:191'],
            'completion_year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 10)],
            'professional_body' => ['nullable', 'string', 'max:191'],
            'registration_number' => ['nullable', 'string', 'max:100'],
        ], [
            'qualification_level.required' => 'Choose the highest qualification held.',
            'qualification_level.in' => 'Choose the highest qualification held.',
            'qualification_level_other.max' => 'That qualification may not be longer than 35 characters.',
            'field_of_study.required' => 'Enter the field of study or specialisation.',
            'institution.required' => 'Enter the awarding institution.',
            'completion_year.min' => 'Enter a year from 1900 onwards.',
            'completion_year.max' => 'The year awarded cannot be in the future.',
            'professional_body.max' => 'The professional body may not be longer than 191 characters.',
            'registration_number.max' => 'The registration number may not be longer than 100 characters.',
        ]);

        if ($academic['qualification_level'] === StaffQualificationLevel::OTHER
            && trim((string) ($academic['qualification_level_other'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'qualification_level_other' => ['Enter the qualification held.'],
            ]);
        }

        $documents = $this->uploadedDocuments($request);

        $qualification = [
            'qualification_level' => StaffQualificationLevel::normalise(
                $academic['qualification_level'],
                $academic['qualification_level_other'] ?? null
            ),
            // The award itself reads as the qualification name; the field of study
            // keeps its own existing column.
            'qualification_name' => StaffQualificationLevel::base($academic['qualification_level']),
            'specialisation' => $academic['field_of_study'],
            'institution' => $academic['institution'],
        ];
        if (! empty($academic['completion_year'])) {
            $qualification['completion_year'] = (int) $academic['completion_year'];
        }
        // A professional certificate uploaded here is the evidence for the
        // qualification, linked by the same in-request reference the existing
        // architecture already supports.
        if (($ref = $this->firstDocumentOfCategory($documents, 'academic_certificate')) !== null) {
            $qualification['evidence_document_ref'] = $ref;
        }

        $registrations = [];
        if (trim((string) ($academic['professional_body'] ?? '')) !== '') {
            $registration = ['professional_body' => $academic['professional_body']];
            if (trim((string) ($academic['registration_number'] ?? '')) !== '') {
                $registration['registration_number'] = $academic['registration_number'];
            }
            if (($ref = $this->firstDocumentOfCategory($documents, 'professional_certificate')) !== null) {
                $registration['evidence_document_ref'] = $ref;
            }
            $registrations[] = $registration;
        }

        return [
            'qualifications' => [$qualification],
            'registrations' => $registrations,
            'documents' => $documents,
        ];
    }

    /**
     * The supporting documents, in the order submitted, each carrying a document
     * type and a file. Empty rows left behind by the repeater are skipped.
     *
     * The type allow-list is the EXISTING StaffDocument::CATEGORIES set, and the
     * file itself is checked and stored privately by StaffDocumentStorage, so
     * this form cannot accept a type or a file the rest of the staff module
     * would refuse.
     */
    private function uploadedDocuments(Request $request): array
    {
        // Files are not in the input bag: the two halves of each repeater row
        // arrive separately and are rejoined by row index.
        $types = $request->input('documents', []);
        $files = $request->file('documents', []);
        if (! is_array($types) || ! is_array($files)) {
            return [];
        }

        $maxKb = StaffDocumentStorage::maxKb();
        $documents = [];
        $index = 0;

        foreach (array_keys($types + $files) as $key) {
            $category = trim((string) (is_array($types[$key] ?? null) ? ($types[$key]['category'] ?? '') : ''));
            $file = $files[$key]['file'] ?? null;

            if (($file === null || $file === '') && $category === '') {
                continue;   // untouched repeater row
            }

            $errors = [];
            if ($category === '' || ! array_key_exists($category, StaffDocument::CATEGORIES)) {
                $errors["documents.{$key}.category"][] = 'Choose a document type.';
            }
            if (! ($file instanceof UploadedFile) || ! $file->isValid()) {
                $errors["documents.{$key}.file"][] = 'Choose a file to upload.';
            } elseif ($problem = StaffDocumentStorage::validate($file)) {
                // The private store's own rules, reported against this row.
                $errors["documents.{$key}.file"][] = $problem;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $documents[] = ['category' => $category, 'file' => $file, 'ref' => (string) $index];
            $index++;
        }

        if (count($documents) > self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages([
                'documents' => ['Upload at most '.self::MAX_DOCUMENTS.' documents at a time. Add the rest from the staff profile.'],
            ]);
        }

        return $documents;
    }

    /** The reference of the first uploaded document of $category, if any. */
    private function firstDocumentOfCategory(array $documents, string $category): ?string
    {
        foreach ($documents as $document) {
            if ($document['category'] === $category) {
                return (string) $document['ref'];
            }
        }

        return null;
    }

    /** The document types this form offers, in the existing architecture's order. */
    private function documentCategories(): array
    {
        $out = [];
        foreach (self::UPLOADABLE_DOCUMENTS as $key) {
            if (isset(StaffDocument::CATEGORIES[$key])) {
                $out[$key] = StaffDocument::CATEGORIES[$key];
            }
        }

        return $out;
    }

    /** "Other" on the title needs a title; the other options must not carry one. */
    private function assertTitleComplete(array $data): void
    {
        $title = (string) ($data['title'] ?? '');
        $detail = trim((string) ($data['title_other'] ?? ''));

        if ($title === StaffTitle::OTHER && $detail === '') {
            throw ValidationException::withMessages([
                'title_other' => ['Enter the title to use.'],
            ]);
        }

        if ($title !== '' && $title !== StaffTitle::OTHER && $detail !== '') {
            throw ValidationException::withMessages([
                'title_other' => ['A custom title is only used when "Other" is selected.'],
            ]);
        }
    }

    private function assertNextOfKinComplete(array $data): void
    {
        $fields = [
            'emergency_contact_name' => 'Enter the Next of Kin full name.',
            'emergency_contact_relationship' => 'Choose how the Next of Kin is related to this staff member.',
            'emergency_contact_email' => 'Enter the Next of Kin email address.',
            'emergency_contact_phone' => 'Enter the Next of Kin contact number.',
        ];
        $errors = [];
        foreach ($fields as $field => $message) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = [$message];
            }
        }

        if (($data['emergency_contact_relationship'] ?? '') === StaffNextOfKin::OTHER
            && trim((string) ($data['emergency_contact_relationship_other'] ?? '')) === '') {
            $errors['emergency_contact_relationship_other'] = ['Describe how the Next of Kin is related to this staff member.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
            'emergency_contact_relationship.in' => 'Choose a Next of Kin relationship.',
            'emergency_contact_relationship_other.max' => 'The relationship description may not be longer than 40 characters.',
            'emergency_contact_email.email' => 'Enter a valid Next of Kin email address.',
            'emergency_contact_phone.regex' => 'Enter a valid contact number, for example +256 712 345 678.',
            'emergency_contact_alternative_phone.regex' => 'Enter a valid alternative contact number, for example +256 712 345 678.',
            'emergency_contact_address.max' => 'The Next of Kin address may not be longer than 255 characters.',
            'photo.mimes' => 'The profile photo must be a JPG or PNG image.',
            'photo.max' => 'The profile photo may not be larger than 4 MB.',
        ];
    }
}
