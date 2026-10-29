<?php

namespace App\Console\Commands;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLessonResource;
use App\Models\CourseRegistration;
use App\Models\School;
use App\Support\TenantTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Fixed read-only evidence; never publish, reschedule or repair historical data. */
class AuditCourseLearning extends Command
{
    protected $signature = 'course-content:audit {offering : Course offering ID}';
    protected $description = 'Inspect publication, release, enrollment counts and missing learning files without changing data';

    public function handle(): int
    {
        $offering = CourseOffering::query()->with('subject')->findOrFail((int) $this->argument('offering'));
        $school = School::query()->find($offering->school_id);
        $zone = app(TenantTimezone::class)->resolve($school);
        $this->info('Offering '.$offering->id.' — '.$offering->subject?->name.' — '.$offering->reference);
        $this->line('Institution clock: '.now()->setTimezone($zone)->toIso8601String().' ('.$zone.')');
        $this->line('Confirmed registrations: '.CourseRegistration::query()->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)->where('status', 'confirmed')->count());
        $rows = [];
        $modules = \App\Models\CourseOfferingModule::query()->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)->with('lessons')->orderBy('sequence')->get();
        foreach ($modules as $module) {
            foreach ($module->lessons->sortBy('sequence') as $lesson) {
                $rows[] = [$module->id, $module->status, $module->released_at?->copy()->setTimezone($zone)->toIso8601String() ?? 'immediate',
                    $lesson->id, $lesson->status, $lesson->released_at?->copy()->setTimezone($zone)->toIso8601String() ?? 'immediate',
                    $module->isReleasedToStudents() && $lesson->isReleasedToStudents() ? 'released (sequence applies)' : 'not released'];
            }
        }
        $this->table(['Module', 'State', 'Module release', 'Lesson', 'State', 'Lesson release', 'Availability'], $rows);
        $missing = 0;
        foreach (CourseOfferingLessonResource::query()->where('school_id', $offering->school_id)->where('course_offering_id', $offering->id)->get() as $resource) {
            if (! $resource->lesson()->exists() || ($resource->isFile() && (! $resource->stored_name || ! Storage::disk('local')->exists($resource->stored_name)))) {
                $this->warn('Missing lesson or file for resource '.$resource->id);
                $missing++;
            }
        }
        $this->line('Missing lesson/file resources: '.$missing);

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
