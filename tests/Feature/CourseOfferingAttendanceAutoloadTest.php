<?php

namespace Tests\Feature;

use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceRules;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionStatus;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceStatus;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceService;
use Tests\TestCase;

/**
 * Regression for the lecturer Attendance create 500.
 *
 * The four HEI Course Offering attendance vocabularies were all declared inside
 * CourseOfferingAttendanceStatus.php. PSR-4 maps a class name to a file of the
 * same name, so CourseOfferingAttendanceSessionType could only be found if
 * CourseOfferingAttendanceStatus had already been loaded. TeacherCourseOfferingAttendance
 * Controller::create() references SessionType first, so the first real request to
 * GET /teacher/course-offerings/{offering}/attendance/create failed with
 *
 *   Class "App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType" not found
 *
 * The full test suite passed only because some earlier test happened to load a
 * sibling class first. This file therefore checks each class in ISOLATION: it
 * asserts the declared file matches the class, and that no sibling file
 * declares it, so the test cannot pass by accident of execution order.
 */
class CourseOfferingAttendanceAutoloadTest extends TestCase
{
    public function test_every_attendance_support_class_autoloads_on_its_own(): void
    {
        $classes = [
            CourseOfferingAttendanceStatus::class,
            CourseOfferingAttendanceSessionStatus::class,
            CourseOfferingAttendanceSessionType::class,
            CourseOfferingAttendanceRules::class,
            CourseOfferingAttendanceService::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} autoloads by its own name");
        }
    }

    /**
     * Each class must be declared in a file whose name matches it, otherwise
     * PSR-4 cannot resolve it without a sibling having been loaded first.
     */
    public function test_each_class_is_declared_in_a_file_matching_its_own_name(): void
    {
        foreach ([
            CourseOfferingAttendanceStatus::class,
            CourseOfferingAttendanceSessionStatus::class,
            CourseOfferingAttendanceSessionType::class,
            CourseOfferingAttendanceRules::class,
        ] as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $actual = (new \ReflectionClass($class))->getFileName();

            $this->assertSame(
                $short.'.php',
                basename((string) $actual),
                "{$class} lives in a file named {$short}.php"
            );
            $this->assertDirectoryExists(dirname((string) $actual));
        }
    }

    /** The original defect: these three shared one file. Nothing may share one now. */
    public function test_no_attendance_file_declares_more_than_one_class(): void
    {
        $directory = app_path('Support/CourseOfferingAttendance');

        foreach (glob($directory.'/*.php') as $file) {
            $source = (string) file_get_contents((string) $file);
            preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $matches);

            $this->assertLessThanOrEqual(
                1,
                count($matches[1]),
                basename((string) $file).' declares exactly one class (found: '.implode(', ', $matches[1]).')'
            );
        }
    }

    /** The vocabularies themselves are unchanged by the file split. */
    public function test_vocabulary_values_are_preserved(): void
    {
        $this->assertSame([0, 1, 2, 3], CourseOfferingAttendanceStatus::ALL);
        $this->assertSame([1, 2], CourseOfferingAttendanceStatus::ATTENDED);
        $this->assertSame('Present', CourseOfferingAttendanceStatus::label(1));
        $this->assertTrue(CourseOfferingAttendanceStatus::isValid(3));

        $this->assertSame(['draft', 'finalised', 'locked'], CourseOfferingAttendanceSessionStatus::ALL);
        $this->assertSame(['draft'], CourseOfferingAttendanceSessionStatus::MUTABLE);
        $this->assertTrue(CourseOfferingAttendanceSessionStatus::acceptsMarking('draft'));
        $this->assertFalse(CourseOfferingAttendanceSessionStatus::acceptsMarking('locked'));
        $this->assertSame('Finalised', CourseOfferingAttendanceSessionStatus::label('finalised'));

        $this->assertCount(7, CourseOfferingAttendanceSessionType::ALL);
        $this->assertArrayHasKey('live_class', CourseOfferingAttendanceSessionType::LABELS);
        $this->assertSame('Live Class', CourseOfferingAttendanceSessionType::label('live_class'));

        $this->assertSame('lecture', CourseOfferingAttendanceRules::assertType('lecture'));
        $this->assertNull(CourseOfferingAttendanceRules::assertTimes(null, '10:00'));
        $this->assertNull(CourseOfferingAttendanceRules::assertTimes('09:00', '11:00'));
    }

    /** A null type is refused here; the service coalesces it to the default first. */
    public function test_a_null_type_is_refused_by_the_rule_itself(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Choose a valid Attendance Session type.');
        CourseOfferingAttendanceRules::assertType(null);
    }

    /** Business rules are unchanged, not loosened to make autoloading work. */
    public function test_attendance_rules_still_reject_bad_input(): void
    {
        try {
            CourseOfferingAttendanceRules::assertType('nonsense');
            $this->fail('An invalid Attendance Session type must be refused.');
        } catch (\DomainException $exception) {
            $this->assertSame('Choose a valid Attendance Session type.', $exception->getMessage());
        }

        try {
            CourseOfferingAttendanceRules::assertTimes('11:00', '09:00');
            $this->fail('An end time at or before the start must be refused.');
        } catch (\DomainException $exception) {
            $this->assertSame('The Attendance Session end time must be later than its start time.', $exception->getMessage());
        }
    }
}
