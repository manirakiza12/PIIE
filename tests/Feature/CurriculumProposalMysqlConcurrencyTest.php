<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Curriculum\CurriculumAssignmentProposals;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Support\CurriculumWorkerBarrier;
use Tests\TestCase;

/** Real connections and row locks; runs only on the existing disposable CI MySQL. */
class CurriculumProposalMysqlConcurrencyTest extends TestCase
{
    public function test_concurrent_proposals_applications_and_mapping_approvals_are_serialized(): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') $this->markTestSkipped('Real MySQL concurrency is exercised on GitHub CI.');
        $connection = config('database.connections.mysql');
        // CreatesApplication deliberately blocks the ordinary mysql connection.
        // Retain that guard and opt in through a separately named, CI-only connection.
        $this->assertSame('__piie_phpunit_blocked__', $connection['database']);
        $this->assertSame('ci_test', env('DB_DATABASE'));
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('ci_user', $connection['username']);
        $this->assertSame(3306, (int) $connection['port']);
        $this->assertTrue(app()->environment('testing'));
        $connection['database'] = 'ci_test';
        $connection['url'] = null;
        $connection['unix_socket'] = '';
        config(['database.connections.curriculum_ci' => $connection, 'database.default' => 'curriculum_ci']);
        $this->assertSame('ci_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $suffix = bin2hex(random_bytes(5));
        $school = DB::table('schools')->insertGetId(['title' => 'Module 1 CI '.$suffix, 'email' => 'ci@example.test', 'phone' => 0, 'address' => 'CI', 'school_info' => 'Disposable fixture', 'status' => 1, 'school_type' => 'higher_ed', 'academic_calendar_pattern' => 'semester']);
        $actor = DB::table('users')->insertGetId(['school_id' => $school, 'name' => 'Registrar CI', 'email' => 'r'.$suffix.'@example.test', 'password' => 'unused', 'role_id' => '2', 'code' => 'R'.$suffix, 'account_status' => 'active']);
        $student = DB::table('users')->insertGetId(['school_id' => $school, 'name' => 'Student CI', 'email' => 's'.$suffix.'@example.test', 'password' => 'unused', 'role_id' => '7', 'code' => 'S'.$suffix, 'account_status' => 'active']);
        $programme = DB::table('programmes')->insertGetId(['school_id' => $school, 'name' => 'Explicit CI Programme', 'code' => 'CI'.$suffix, 'level' => 'Degree', 'mode' => 'fulltime', 'is_active' => 1]);
        $intake = DB::table('intake_sessions')->insertGetId(['school_id' => $school, 'name' => 'Explicit CI Intake', 'is_open' => 1]);
        $year = DB::table('academic_years')->insertGetId(['school_id' => $school, 'label' => 'CI 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $curriculum = DB::table('curricula')->insertGetId(['school_id' => $school, 'programme_id' => $programme, 'version' => 'CI-V1', 'effective_academic_year_id' => $year, 'status' => 'approved']);
        $stage = DB::table('curriculum_stages')->insertGetId(['school_id' => $school, 'curriculum_id' => $curriculum, 'label' => 'Year 1', 'sequence' => 1]);
        $subject = DB::table('subjects')->insertGetId(['school_id' => $school, 'name' => 'CI Unit', 'code' => 'CI'.$suffix, 'class_id' => 0, 'session_id' => 0, 'programme_id' => $programme]);
        DB::table('curriculum_memberships')->insert(['school_id' => $school, 'curriculum_id' => $curriculum, 'curriculum_stage_id' => $stage, 'subject_id' => $subject, 'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'compulsory', 'credits' => 3, 'sequence' => 1]);
        DB::table('student_profiles')->insert(['school_id' => $school, 'user_id' => $student, 'programme_id' => $programme, 'intake_session_id' => $intake, 'status' => 'active']);
        $admission = DB::table('admissions')->insertGetId(['school_id' => $school, 'app_number' => 'CI'.$suffix, 'programme_id' => $programme, 'intake_session_id' => $intake, 'first_name' => 'CI', 'last_name' => 'Student', 'status' => 'accepted', 'automation_student_id' => $student]);
        $service = app(CurriculumAssignmentProposals::class);
        $registrar = User::findOrFail($actor);
        $data = ['programme_id' => $programme, 'intake_session_id' => $intake, 'curriculum_id' => $curriculum, 'entry_academic_year_id' => $year, 'entry_curriculum_stage_id' => $stage, 'reason' => 'CI explicit Registrar policy reference'];
        $mapping1 = $service->createMapping($registrar, $data);
        $mapping2 = $service->createMapping($registrar, $data);
        $outcomes = $this->race($school, $actor, 'approve-mapping', [$mapping1, $mapping2]);
        $this->assertSame(1, DB::table('curriculum_applicability_mappings')->where('school_id', $school)->where('status', 'approved')->count());
        $this->assertSame(1, count(array_filter($outcomes, fn ($r) => ($r['blocked'] ?? '') === 'mapping_ambiguous')));
        $outcomes = $this->race($school, $actor, 'propose', [$admission, $admission]);
        $this->assertSame($outcomes[0]['result'], $outcomes[1]['result']);
        $proposal = $outcomes[0]['result'];
        $service->review($registrar, $proposal, true, 'CI Registrar approved', true);
        $outcomes = $this->race($school, $actor, 'apply', [$proposal, $proposal]);
        $this->assertSame($outcomes[0]['result'], $outcomes[1]['result']);
        $this->assertSame(1, DB::table('student_curriculum_assignments')->where('school_id', $school)->where('student_id', $student)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $school)->where('action', 'CURRICULUM_PROPOSAL_APPLIED')->count());
        $this->assertSame(0, DB::table('course_registrations')->where('school_id', $school)->count());
        $this->assertSame('accepted', DB::table('admissions')->where('id', $admission)->value('status'));
    }

    private function race(int $school, int $actor, string $operation, array $ids): array
    {
        $processes = [];
        try {
            DB::transaction(function () use ($school, $actor, $operation, $ids, &$processes): void {
                DB::table('schools')->where('id', $school)->lockForUpdate()->firstOrFail();
                foreach ($ids as $id) {
                    $process = new Process([PHP_BINARY, base_path('tests/Feature/Support/CurriculumProposalMysqlWorker.php'), $operation, (string) $actor, (string) $id], base_path(), ['APP_ENV' => 'testing']);
                    $process->setTimeout(30);
                    $processes[] = $process;
                    $process->start();
                }
                // READY precedes the service's school lock. Never wait for worker
                // completion here: both workers need the lock held by this parent.
                foreach ($processes as $process) CurriculumWorkerBarrier::awaitReady($process);
            });
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $lines = array_values(array_filter(explode("\n", trim($process->getOutput())), fn ($line) => str_starts_with($line, '{')));
                $results[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            // Also reap siblings when startup, readiness, waiting or assertions fail.
            foreach ($processes as $process) $process->stop(0);
        }
    }
}
