<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\GlobalSettings;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnhancedSettingsController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    // ── Academic / Grading ────────────────────────────────────
    public function academic()
    {
        $grades = Grade::where('school_id', $this->school_id)->orderByDesc('mark_from')->get();
        return view('admin.settings.academic', compact('grades'));
    }

    public function teachingPlatforms()
    {
        $platform_settings = $this->getLiveClassPlatformSettings();
        return view('admin.settings.teaching_platforms', compact('platform_settings'));
    }

    public function saveAcademic(Request $request)
    {
        $request->validate(['_settings_section' => ['nullable', 'in:grading,platforms']]);
        // Delete existing and re-insert grading scale from posted data
        $section = $request->input('_settings_section');
        if ($section !== 'platforms' && $request->has('grade')) {
            Grade::where('school_id', $this->school_id)->delete();
            foreach ($request->grade as $g) {
                if (!empty($g['name'])) {
                    Grade::create([
                        'school_id'      => $this->school_id,
                        'name'           => $g['name'],
                        'grade_point'    => $g['grade_point'] ?? 0,
                        'gpa_points'     => $g['gpa_points'] ?? 0,
                        'classification' => $g['classification'] ?? '',
                        'mark_from'      => $g['mark_from'] ?? 0,
                        'mark_upto'      => $g['mark_upto'] ?? 100,
                    ]);
                }
            }
        }

        $platformKeys = [
            'live_class_platform_jitsi',
            'live_class_platform_google_meet',
            'live_class_platform_zoom',
            'live_class_platform_bigbluebutton',
            'live_class_platform_custom',
        ];

        if ($section !== 'grading') {
            foreach ($platformKeys as $key) {
                GlobalSettings::updateOrCreate(['key' => $key], ['value' => $request->has($key) ? '1' : '0']);
            }
            GlobalSettings::updateOrCreate(
                ['key' => 'live_class_jitsi_base_url'],
                ['value' => trim((string) $request->input('live_class_jitsi_base_url', 'https://meet.jit.si')) ?: 'https://meet.jit.si']
            );
        }
        return redirect()->back()->with('success', get_phrase($section === 'platforms' ? 'Teaching platform settings saved' : 'Grading scale updated'));
    }

    // ── Notifications ─────────────────────────────────────────
    public function notifications()
    {
        $notif_settings = $this->getNotifSettings();
        $admissionsCopyEmail = \App\Support\Admissions\AdmissionsNotificationCopy::recipient((int) $this->school_id);
        return view('admin.settings.notifications', compact('notif_settings', 'admissionsCopyEmail'));
    }

    public function saveNotifications(Request $request)
    {
        $request->validate(['admissions_notification_email' => ['nullable', 'email:filter', 'max:254']]);
        if ($request->has('admissions_notification_email')) {
            GlobalSettings::updateOrCreate(
                ['key' => \App\Support\Admissions\AdmissionsNotificationCopy::settingKey((int) $this->school_id)],
                ['value' => trim((string) $request->input('admissions_notification_email'))]
            );
        }
        $keys = [
            'notif_new_student', 'notif_fee_paid', 'notif_exam_published',
            'notif_assignment', 'notif_leave_request', 'notif_notice',
            'sms_new_student', 'sms_fee_paid', 'sms_exam_result',
        ];
        foreach ($keys as $key) {
            $val = $request->has($key) ? '1' : '0';
            GlobalSettings::updateOrCreate(['key' => $key], ['value' => $val]);
        }
        return redirect()->back()->with('success', get_phrase('Notification settings saved'));
    }

    // ── Permissions ───────────────────────────────────────────
    public function permissions()
    {
        $roles = $this->systemRoles();
        $all_perms = $this->getPermissionList();
        // Load saved permissions per role
        $role_perms = [];
        foreach ($roles as $role) {
            $roleId = $this->resolveRoleId($role);
            if ($roleId <= 0) {
                continue;
            }

            $val = GlobalSettings::where('key', "role_perm_{$roleId}")->value('value');
            $role_perms[$roleId] = $val ? json_decode($val, true) : [];
        }
        return view('admin.settings.permissions', compact('roles', 'all_perms', 'role_perms'));
    }

    public function savePermissions(Request $request)
    {
        $roles = $this->systemRoles();
        foreach ($roles as $role) {
            $roleId = $this->resolveRoleId($role);
            if ($roleId <= 0) {
                continue;
            }

            $perms = $request->input("perms.{$roleId}", []);
            if (!is_array($perms)) {
                $perms = [];
            }

            $perms = array_values(array_unique(array_filter(array_map(function ($item) {
                return trim((string) $item);
            }, $perms))));

            GlobalSettings::updateOrCreate(
                ['key' => "role_perm_{$roleId}"],
                ['value' => json_encode($perms)]
            );
        }
        return redirect()->back()->with('success', get_phrase('Permissions saved'));
    }

    // ── Backup ────────────────────────────────────────────────
    public function backup()
    {
        $backups = $this->listBackups();
        return view('admin.settings.backup', compact('backups'));
    }

    public function runBackup(Request $request)
    {
        $dir = storage_path('backups');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $db     = config('database.connections.mysql.database');
        $user   = config('database.connections.mysql.username');
        $pass   = config('database.connections.mysql.password');
        $host   = config('database.connections.mysql.host');
        $file   = $dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Use mysqldump via exec
        $cmd = "mysqldump --user={$user} --password={$pass} --host={$host} {$db} > \"{$file}\" 2>&1";
        exec($cmd, $output, $rc);

        if ($rc === 0 && file_exists($file)) {
            return redirect()->back()->with('success', get_phrase('Backup created') . ': ' . basename($file));
        }

        // Fallback: export schema only via PHP
        $tables  = DB::select('SHOW TABLES');
        $sql     = "-- TDIIBT Backup " . date('Y-m-d H:i:s') . "\n-- Database: {$db}\n\n";
        foreach ($tables as $t) {
            $tname = array_values((array)$t)[0];
            $create = DB::select("SHOW CREATE TABLE `{$tname}`");
            $sql   .= array_values((array)$create[0])[1] . ";\n\n";
        }
        file_put_contents($file, $sql);

        return redirect()->back()->with('success', get_phrase('Schema backup created') . ': ' . basename($file));
    }

    // ── API ───────────────────────────────────────────────────
    public function apiSettings()
    {
        $api_key = GlobalSettings::where('key', "api_key_{$this->school_id}")->value('value');
        if (!$api_key) {
            $api_key = Str::random(40);
            GlobalSettings::create(['key' => "api_key_{$this->school_id}", 'value' => $api_key]);
        }
        $api_base = url('/api');
        return view('admin.settings.api', compact('api_key', 'api_base'));
    }

    public function regenerateKey()
    {
        $api_key = Str::random(40);
        GlobalSettings::updateOrCreate(
            ['key' => "api_key_{$this->school_id}"],
            ['value' => $api_key]
        );
        return redirect()->back()->with('success', get_phrase('API key regenerated'));
    }

    // ── Helpers ───────────────────────────────────────────────
    private function getNotifSettings(): array
    {
        $keys = [
            'notif_new_student', 'notif_fee_paid', 'notif_exam_published',
            'notif_assignment', 'notif_leave_request', 'notif_notice',
            'sms_new_student', 'sms_fee_paid', 'sms_exam_result',
        ];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = GlobalSettings::where('key', $key)->value('value') === '1';
        }
        return $result;
    }

    private function getLiveClassPlatformSettings(): array
    {
        return [
            'live_class_platform_jitsi' => GlobalSettings::where('key', 'live_class_platform_jitsi')->value('value') !== '0',
            'live_class_platform_google_meet' => GlobalSettings::where('key', 'live_class_platform_google_meet')->value('value') !== '0',
            'live_class_platform_zoom' => GlobalSettings::where('key', 'live_class_platform_zoom')->value('value') !== '0',
            'live_class_platform_bigbluebutton' => GlobalSettings::where('key', 'live_class_platform_bigbluebutton')->value('value') === '1',
            'live_class_platform_custom' => GlobalSettings::where('key', 'live_class_platform_custom')->value('value') === '1',
            'live_class_jitsi_base_url' => GlobalSettings::where('key', 'live_class_jitsi_base_url')->value('value') ?: 'https://meet.jit.si',
            // Status only — the credentials themselves live in .env
            // (config/services.php), matching Zoom/Google Meet above, not
            // this GlobalSettings table. See LIVE_CLASS_JITSI_JWT_SETUP.md.
            'jitsi_jwt_configured' => \App\Support\LiveClasses\JitsiTokenService::isConfigured(),
        ];
    }

    private function getPermissionList(): array
    {
        return [
            'Academic'    => ['View Dashboard', 'View Students', 'Edit Students', 'Delete Students', 'View Staff', 'Edit Staff', 'Manage Courses', 'Enter Marks', 'Publish Results', 'Manage Attendance'],
            'Live Classes' => ['View Live Classes', 'Create Live Classes', 'Edit Live Classes', 'Delete Live Classes', 'Cancel Live Classes', 'Publish Live Classes', 'Join Live Classes', 'Manage Live Class Platforms'],
            'Online Exams' => [
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'edit_all_online_exams',
                'delete_online_exams',
                'publish_online_exams',
                'cancel_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'mark_exam_answers',
                'view_exam_results',
                'manage_exam_settings',
                'review_exam_proctoring',
                'sit_online_exams',
            ],
            'Finance'     => ['View Finance', 'Record Payments', 'Manage Payroll', 'View Invoices'],
            'Admissions'  => ['View Admissions', 'Manage Admissions', 'Issue Offer Letters'],
            'Operations'  => ['View Library', 'Post Notices', 'Manage Events', 'Manage Leave'],
            'System'      => ['View Reports', 'View Audit Log', 'System Settings', 'Manage Users'],
        ];
    }

    /**
     * The system roles. `roles` is a global list (role_id, name — migration
     * 2022_05_16_051816, no school_id), and role_perm_{role_id} is a platform-wide
     * legacy setting (RBAC Phase 3A/3B). The former school_id filter referenced a
     * column that does not exist and crashed this page on every real database.
     */
    private function systemRoles()
    {
        return DB::table('roles')->orderBy('role_id')->get();
    }

    private function resolveRoleId(object $role): int
    {
        return (int) ($role->role_id ?? $role->id ?? 0);
    }

    private function listBackups(): array
    {
        $dir = storage_path('backups');
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/backup_*.sql');
        if (!$files) return [];
        $result = [];
        foreach (array_reverse($files) as $f) {
            $result[] = [
                'name' => basename($f),
                'size' => round(filesize($f) / 1024, 1) . ' KB',
                'date' => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }
        return array_slice($result, 0, 10);
    }
}
