<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Support\CurriculumWorkerBarrier;
use Tests\TestCase;

class LessonPublicationMysqlConcurrencyTest extends TestCase
{
    public function test_concurrent_creation_and_republication_keep_one_lesson_and_assignment(): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') { $this->markTestSkipped('Real MySQL concurrency runs only in disposable GitHub CI.'); }
        $connection = config('database.connections.mysql');
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('__piie_phpunit_blocked__', $connection['database']);
        $this->assertSame('ci_test', env('DB_DATABASE'));
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('ci_user', $connection['username']);
        $this->assertSame(3306, (int) $connection['port']);
        $connection['database'] = 'ci_test';
        $connection['url'] = null;
        $connection['unix_socket'] = '';
        config(['database.connections.lesson_ci'=>$connection, 'database.default'=>'lesson_ci']);
        $this->assertSame('ci_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $suffix = bin2hex(random_bytes(5));
        $school = DB::table('schools')->insertGetId(['title'=>'Lesson CI '.$suffix,'email'=>'ci@example.test','phone'=>0,'address'=>'CI','school_info'=>'Disposable fixture','status'=>1,'school_type'=>'higher_ed','academic_calendar_pattern'=>'semester']);
        $actor = DB::table('users')->insertGetId(['school_id'=>$school,'name'=>'Lesson CI admin','email'=>'lesson'.$suffix.'@example.test','password'=>'unused','role_id'=>'2','code'=>'L'.$suffix,'account_status'=>'active']);
        $year = DB::table('academic_years')->insertGetId(['school_id'=>$school,'label'=>'CI 2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','status'=>'active']);
        $period = DB::table('academic_periods')->insertGetId(['school_id'=>$school,'academic_year_id'=>$year,'type'=>'semester','label'=>'Semester 1','sequence'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-06-30','status'=>'active']);
        $subject = DB::table('subjects')->insertGetId(['school_id'=>$school,'name'=>'Lesson CI unit','code'=>'LC'.$suffix,'class_id'=>0,'session_id'=>0]);
        $offering = DB::table('course_offerings')->insertGetId(['school_id'=>$school,'subject_id'=>$subject,'academic_year_id'=>$year,'academic_period_id'=>$period,'reference'=>'LC'.$suffix,'status'=>'in_progress']);
        $module = DB::table('course_offering_modules')->insertGetId(['school_id'=>$school,'course_offering_id'=>$offering,'title'=>'CI module','status'=>'published','sequence'=>1]);
        $assignment = DB::table('assignments')->insertGetId(['school_id'=>$school,'course_offering_id'=>$offering,'title'=>'CI assignment','status'=>'published','is_published'=>1,'instructions'=>'<p>Complete the exercise.</p>','due_date'=>'2027-01-01 10:00:00','max_marks'=>10]);

        $ids = $this->race('users', $actor, ['create', $actor, $module, $assignment, $suffix]);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, DB::table('course_offering_lessons')->where('school_id',$school)->count());
        $this->assertSame(1, DB::table('assignments')->where('school_id',$school)->count());
        $lesson = $ids[0];
        $ids = $this->race('course_offering_lessons', $lesson, ['update', $actor, $lesson, $assignment, $suffix]);
        $this->assertSame([$lesson, $lesson], $ids);
        $this->assertSame(1, DB::table('course_offering_lessons')->where('school_id',$school)->count());
        $this->assertSame('published', DB::table('course_offering_lessons')->where('id',$lesson)->value('status'));
    }

    private function race(string $table, int $lockId, array $arguments): array
    {
        $processes = [];
        try {
            DB::transaction(function () use ($table,$lockId,$arguments,&$processes): void {
                DB::table($table)->where('id',$lockId)->lockForUpdate()->firstOrFail();
                for ($i=0; $i<2; $i++) {
                    $process = new Process(array_merge([PHP_BINARY,base_path('tests/Feature/Support/LessonPublicationMysqlWorker.php')], array_map('strval',$arguments)), base_path(), ['APP_ENV'=>'testing']);
                    $process->setTimeout(40);
                    $processes[]=$process;
                    $process->start();
                }
                foreach ($processes as $process) { CurriculumWorkerBarrier::awaitReady($process); }
            });
            $ids=[];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(),$process->getErrorOutput().$process->getOutput());
                preg_match('/LESSON_ID=(\d+)/',$process->getOutput(),$match);
                $this->assertNotEmpty($match,$process->getOutput());
                $ids[]=(int)$match[1];
            }
            return $ids;
        } finally {
            foreach ($processes as $process) { $process->stop(0); }
        }
    }
}
