<?php

namespace Database\Seeders;

use App\Models\Classes;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\GlobalSettings;
use App\Models\IntakeSession;
use App\Models\Package;
use App\Models\Programme;
use App\Models\School;
use App\Models\Section;
use App\Models\Session;
use App\Models\StudentProfile;
use App\Models\Subscription;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PiieLocalSetupSeeder extends Seeder
{
    public function run()
    {
        if (!app()->environment('local')) {
            throw new \RuntimeException('PIIE test accounts may only be configured locally.');
        }

        DB::transaction(function () {
            foreach ([1 => 'superadmin', 2 => 'admin', 3 => 'teacher', 4 => 'accountant', 5 => 'librarian', 6 => 'parent', 7 => 'student'] as $id => $name) {
                $existing = DB::table('roles')->where('role_id', $id)->first();
                if ($existing && strtolower($existing->name) !== $name) {
                    throw new \RuntimeException("Role {$id} already has a different name.");
                }
                DB::table('roles')->updateOrInsert(['role_id' => $id], ['name' => $name, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()]);
            }

            $school = School::where('email', 'info@piie.test')
                ->orWhere('title', 'Prime International Institute of Excellence (PIIE)')
                ->orWhere('title', 'PIIE')->first() ?? new School();
            $school->fill([
                'title' => 'Prime International Institute of Excellence (PIIE)',
                'email' => 'info@piie.test', 'phone' => $school->phone ?? 0,
                'address' => $school->address ?? '',
                'school_info' => 'PIIE local school system for login and testing.',
                'status' => 1, 'school_type' => 'higher_ed', 'education_level' => 'tertiary',
                'school_currency' => 'KES', 'currency_position' => 'left-space',
            ])->save();
            $session = Session::firstOrCreate(
                ['school_id' => $school->id, 'session_title' => date('Y')], ['status' => 1]
            );
            Session::where('school_id', $school->id)->where('id', '!=', $session->id)->update(['status' => 0]);
            $session->update(['status' => 1]);
            $school->running_session = $session->id;
            $school->save();

            $settings = [
                'system_name' => $school->title, 'system_title' => 'PIIE',
                'system_email' => 'info@piie.test', 'language' => 'english',
                'timezone' => 'Africa/Nairobi', 'system_currency' => 'KES',
                'currency_position' => 'left-space', 'running_session' => (string) $session->id,
                'primary_school_id' => (string) $school->id,
                'footer_text' => 'Prime International Institute of Excellence',
                'footer_link' => '/', 'payment_settings' => '[]', 'frontend_view' => '1',
            ];
            foreach ($settings as $key => $value) {
                GlobalSettings::updateOrCreate(['key' => $key], ['value' => $value]);
            }

            $package = Package::firstOrCreate(['name' => 'PIIE Local Testing'], [
                'price' => 0, 'package_type' => 'paid', 'interval' => 'yearly',
                'days' => 365, 'studentLimit' => '1000', 'features' => '[]',
                'status' => 1, 'description' => 'Local testing subscription',
            ]);
            Subscription::updateOrCreate(['school_id' => $school->id, 'package_id' => $package->id], [
                'paid_amount' => 0, 'payment_method' => 'offline', 'transaction_keys' => '{}',
                'expire_date' => now()->addYear()->timestamp, 'date_added' => time(),
                'studentLimit' => '1000', 'active' => 1, 'status' => '1',
            ]);

            $department = Department::firstOrCreate(
                ['school_id' => $school->id, 'name' => 'General Studies']
            );

            $programme = Programme::firstOrCreate(
                ['school_id' => $school->id, 'code' => 'BBIT'],
                [
                    'name' => 'Bachelor of Business Information Technology',
                    'level' => 'Bachelors', 'duration' => '4 Years', 'mode' => 'Full Time',
                    'tuition_fee' => 0, 'department_id' => $department->id, 'is_active' => 1,
                ]
            );

            $intakeSession = IntakeSession::firstOrCreate(
                ['school_id' => $school->id, 'name' => date('Y').' September Intake'],
                ['open_date' => now()->subMonth(), 'close_date' => now()->addMonth(), 'application_fee' => 0, 'is_open' => 1]
            );

            $class = Classes::firstOrCreate(['school_id' => $school->id, 'name' => 'Year 1']);
            $section = Section::firstOrCreate(['class_id' => $class->id, 'name' => 'A']);

            $teacherUser = null;
            foreach ([
                ['superadmin', 'Super Administrator', 1],
                ['admin', 'School Administrator', 2],
                ['teacher', 'Teacher', 3],
                ['accountant', 'Accountant', 4],
                ['librarian', 'Librarian', 5],
                ['parent', 'Parent', 6],
            ] as [$handle, $name, $role]) {
                $email = $handle.'@piie.test';
                if (User::where('email', $email)->count() > 1) {
                    throw new \RuntimeException("Duplicate accounts already exist for {$email}.");
                }
                $user = User::firstOrNew(['email' => $email]);
                $user->fill([
                    'name' => 'PIIE '.$name, 'password' => Hash::make('1234'),
                    'role_id' => $role, 'school_id' => $role === 1 ? null : $school->id,
                    'school_role' => $role === 2 ? 1 : null,
                    'status' => 1, 'account_status' => 'active',
                    'staff_status' => in_array($role, [2, 3, 4]) ? 'active' : null,
                    'force_password_change' => false, 'language' => 'english',
                    'code' => $user->code ?: 'PIIE-'.strtoupper($handle),
                    'user_information' => $user->user_information ?: json_encode([
                        'gender' => '', 'birthday' => '',
                        'phone' => '', 'address' => '', 'photo' => '',
                    ]),
                ]);
                $user->email_verified_at = now();
                $user->menu_permission = null;
                $user->save();

                if ($role === 3) {
                    $teacherUser = $user;
                }
            }

            if ($teacherUser) {
                TeacherProgrammeAssignment::updateOrCreate(
                    ['teacher_id' => $teacherUser->id, 'programme_id' => $programme->id, 'school_id' => $school->id],
                    ['marks' => 1, 'attendance' => 1, 'updated_at' => now()]
                );

                TeacherPermission::updateOrCreate(
                    [
                        'class_id' => $class->id,
                        'section_id' => $section->id,
                        'school_id' => $school->id,
                        'teacher_id' => $teacherUser->id,
                    ],
                    ['marks' => 1, 'attendance' => 1, 'updated_at' => time()]
                );
            }

            $studentEmail = 'student@piie.test';
            if (User::where('email', $studentEmail)->count() > 1) {
                throw new \RuntimeException("Duplicate accounts already exist for {$studentEmail}.");
            }
            $student = User::firstOrNew(['email' => $studentEmail]);
            $student->fill([
                'name' => 'PIIE Student', 'password' => Hash::make('1234'),
                'role_id' => 7, 'school_id' => $school->id,
                'status' => 1, 'account_status' => 'active',
                'force_password_change' => false, 'language' => 'english',
                'code' => $student->code ?: 'PIIE-STUDENT',
                'user_information' => $student->user_information ?: json_encode([
                    'gender' => '', 'birthday' => '',
                    'phone' => '', 'address' => '', 'photo' => '',
                ]),
            ]);
            $student->email_verified_at = now();
            $student->menu_permission = null;
            $student->save();

            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'school_id' => $school->id, 'programme_id' => $programme->id,
                    'intake_session_id' => $intakeSession->id, 'year_of_study' => 1,
                    'nationality' => null, 'national_id_or_passport' => null,
                    'next_of_kin_address' => null, 'next_of_kin_contact' => null,
                    'additional_image' => null, 'status' => 'active',
                ]
            );

            Enrollment::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'class_id' => $class->id, 'section_id' => $section->id,
                    'school_id' => $school->id, 'department_id' => $department->id,
                    'session_id' => $session->id,
                ]
            );

            $this->call([LiveClassPermissionSeeder::class, OnlineExamPermissionSeeder::class, AdmissionDocumentRequirementSeeder::class]);
        });
    }
}
