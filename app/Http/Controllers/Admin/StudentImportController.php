<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Programme;
use App\Models\User;
use App\Support\Students\StudentProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV bulk import for students who are already studying.
 *
 * These people never went through admissions, so they have no application fee.
 * The import therefore provisions exactly what the single-add form provisions —
 * user, profile, enrolment and fee invoice — and never touches admissions.
 *
 * Import is deliberately two-step: a preview validates every row and reports
 * problems without writing anything, and only a confirmed import creates
 * students. Each row is its own transaction, so one bad row cannot abort or
 * half-apply the rest.
 */
class StudentImportController extends Controller
{
    private const COLUMNS = [
        'name', 'email', 'class', 'section', 'programme', 'department',
        'year_of_study', 'nationality', 'national_id_or_passport',
        'next_of_kin_contact', 'next_of_kin_address', 'status',
    ];

    private const HEADER_ROW = [
        'name', 'email', 'class_name', 'section_name', 'programme_name',
        'department_name', 'year_of_study', 'nationality',
        'national_id_or_passport', 'next_of_kin_contact', 'next_of_kin_address', 'status',
    ];

    private function schoolId(): int
    {
        return (int) Auth::user()->school_id;
    }

    public function index()
    {
        return view('admin.student.import', [
            'columns' => self::COLUMNS,
            'headerRow' => self::HEADER_ROW,
            'classes' => DB::table('classes')->where('school_id', $this->schoolId())->orderBy('name')->get(),
            'programmes' => Programme::where('school_id', $this->schoolId())->orderBy('name')->get(),
        ]);
    }

    /** A pre-filled CSV so staff are not guessing the column names. */
    public function template()
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADER_ROW);
        fputcsv($handle, [
            'Musoke Okello', 'musoke.okello@example.test', 'BITC-2026-DAY-A', '',
            'Bachelor of Business Information Technology', '', '1', 'Ugandan',
            'CM-0000001', '+256700000000', 'Kampala', 'active',
        ]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="student-import-template.csv"',
        ]);
    }

    /**
     * Validates the upload and reports exactly what would happen, writing
     * nothing. Rows are resolved by NAME so the spreadsheet matches what staff
     * see in the admin screens rather than internal ids.
     */
    public function preview(Request $request)
    {
        $rows = $this->readCsv($request);
        $resolved = $this->resolveAll($rows);

        return view('admin.student.import_preview', [
            'columns' => self::COLUMNS,
            'headerRow' => self::HEADER_ROW,
            'ready' => $resolved['ready'],
            'errors' => $resolved['errors'],
            'total' => $resolved['total'],
            'importable' => $resolved['importable'],
        ]);
    }

    public function import(Request $request)
    {
        $rows = $this->readCsv($request);
        $resolved = $this->resolveAll($rows);

        if ($resolved['importable'] === []) {
            return back()->with('error', 'No importable rows were found. Fix the errors and upload again.');
        }

        $schoolId = $this->schoolId();
        $created = 0;
        $failed = [];
        $passwords = [];

        foreach ($resolved['importable'] as $row) {
            try {
                $result = StudentProvisioner::provision($row['data'], $schoolId, false);
                StudentProvisioner::sendWelcomeEmail($result['user'], $result['password']);
                $created++;
                $passwords[] = [$result['user']->email, $result['user']->code];
            } catch (Throwable $exception) {
                $failed[] = ['row' => $row['line'], 'message' => $exception->getMessage()];
                Log::warning('Student import row failed', ['row' => $row['line'], 'school' => $schoolId]);
            }
        }

        AuditLog::record('create', 'Students', "Bulk student import: {$created} created, " . count($failed) . ' failed.', [
            'event_type' => 'ACTION', 'record_type' => User::class, 'school_id' => $schoolId,
        ]);

        return view('admin.student.import_result', [
            'created' => $created,
            'failed' => $failed,
            'accounts' => $passwords,
        ]);
    }

    /** @return array<int,array<string,string>> */
    private function readCsv(Request $request): array
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:4096',
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        // Tolerate a UTF-8 BOM, which Excel adds and which would otherwise make
        // the first column name read as "\xEF\xBB\xBFname".
        if ($header && isset($header[0])) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header ?: []);

        $rows = [];
        $line = 1;
        while (($record = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($record, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = ['line' => $line, 'data' => array_combine(
                $header,
                array_pad(array_slice($record, 0, count($header)), count($header), '')
            )];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Turns each raw row into a validated, school-scoped payload, collecting a
     * readable reason for every row that cannot be imported.
     */
    private function resolveAll(array $rows): array
    {
        $schoolId = $this->schoolId();

        $classes = DB::table('classes')->where('school_id', $schoolId)->get()->keyBy('name');
        $programmes = Programme::where('school_id', $schoolId)->get()->keyBy('name');
        $departments = DB::table('departments')->where('school_id', $schoolId)->get()->keyBy('name');
        // `sections` has no school_id of its own - it hangs off a class. Scope it
// through the school's classes so a section from another tenant can never be
// matched by name alone.
$classIds = $classes->pluck('id')->all();
$sections = DB::table('sections')->whereIn('class_id', $classIds)->get()->keyBy('name');

        $seenEmails = [];
        $ready = $errors = $importable = [];

        foreach ($rows as $row) {
            $r = $row['data'];
            $label = trim((string) ($r['name'] ?? '')) ?: '(no name)';
            $email = trim((string) ($r['email'] ?? ''));
            $rowErrors = [];

            $validator = Validator::make([
                'name'  => $r['name'] ?? '',
                'email' => $r['email'] ?? '',
                'class_name' => $r['class_name'] ?? '',
            ], [
                'name'  => 'required|max:255',
                'email' => 'required|email|max:255',
                'class_name' => 'required',
            ]);
            if ($validator->fails()) {
                $rowErrors[] = implode(' ', $validator->errors()->all());
            }

            $className = trim((string) ($r['class_name'] ?? ''));
            if ($className !== '' && ! $classes->has($className)) {
                $rowErrors[] = "class \"{$className}\" does not exist in this school";
            }
            $programmeName = trim((string) ($r['programme_name'] ?? ''));
            if ($programmeName !== '' && ! $programmes->has($programmeName)) {
                $rowErrors[] = "programme \"{$programmeName}\" does not exist in this school";
            }
            $departmentName = trim((string) ($r['department_name'] ?? ''));
            if ($departmentName !== '' && ! $departments->has($departmentName)) {
                $rowErrors[] = "department \"{$departmentName}\" does not exist in this school";
            }
            $sectionName = trim((string) ($r['section_name'] ?? ''));
            if ($sectionName !== '' && ! $sections->has($sectionName)) {
                $rowErrors[] = "section \"{$sectionName}\" does not exist in this school";
            }

            if ($email !== '') {
                if (isset($seenEmails[$email])) {
                    $rowErrors[] = "duplicate email within the file (also on row {$seenEmails[$email]})";
                } elseif (User::where('email', $email)->exists()) {
                    $rowErrors[] = 'email is already registered';
                } else {
                    $seenEmails[$email] = $row['line'];
                }
            }

            $class = $className !== '' && $classes->has($className) ? $classes->get($className) : null;
            $section = $sectionName !== '' && $sections->has($sectionName) ? $sections->get($sectionName) : null;
            if ($class && $section && (int) $section->class_id !== (int) $class->id) {
                $rowErrors[] = 'section does not belong to the chosen class';
            }

            $status = strtolower(trim((string) ($r['status'] ?? ''))) ?: 'active';
            if (! in_array($status, ['active', 'suspended', 'graduated', 'withdrawn', 'deferred'], true)) {
                $rowErrors[] = "status \"{$status}\" is not valid";
                $status = 'active';
            }

            if ($rowErrors) {
                $errors[] = ['line' => $row['line'], 'name' => $label, 'email' => $email, 'reasons' => $rowErrors];
                continue;
            }

            $data = [
                'name'             => trim((string) $r['name']),
                'email'            => $email,
                'password_option'  => 'auto',
                'programme_id'     => $programmeName !== '' ? $programmes->get($programmeName)->id : null,
                'department_id'    => $departmentName !== '' ? $departments->get($departmentName)->id : null,
                'class_id'         => $class->id,
                'section_id'       => $section ? $section->id : null,
                'year_of_study'    => $r['year_of_study'] !== '' && $r['year_of_study'] !== null
                                        ? (int) $r['year_of_study'] : null,
                'nationality'      => ($r['nationality'] ?? '') !== '' ? $r['nationality'] : null,
                'national_id_or_passport' => ($r['national_id_or_passport'] ?? '') !== '' ? $r['national_id_or_passport'] : null,
                'next_of_kin_contact' => ($r['next_of_kin_contact'] ?? '') !== '' ? $r['next_of_kin_contact'] : null,
                'next_of_kin_address'  => ($r['next_of_kin_address'] ?? '') !== '' ? $r['next_of_kin_address'] : null,
                'status'           => $status,
                'user_information' => json_encode([]),
            ];

            $ready[] = ['line' => $row['line'], 'name' => $data['name'], 'email' => $email, 'data' => $data];
            $importable[] = ['line' => $row['line'], 'data' => $data];
        }

        return ['ready' => $ready, 'errors' => $errors, 'total' => count($rows), 'importable' => $importable];
    }
}