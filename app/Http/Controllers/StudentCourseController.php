<?php

namespace App\Http\Controllers;

use App\Support\Assignments\AssignmentAccess;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseExperience\StudentCourseHome;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * THE STUDENT'S COURSE HOME.
 *
 * ── WHY A LANDING PAGE AT ALL ─────────────────────────────────────────────
 *
 * A student with a confirmed registration was sent straight to a plain list of
 * modules and lessons. That list answers "what is in this course" and nothing
 * else. It does not say how far the student has got, what to do next, what is
 * overdue, who is teaching, or whether the next Live Class is today - which are
 * the questions a student actually opens a course to answer.
 *
 * So the Course Home is that page, and the content list is one tab of it rather
 * than the whole experience.
 *
 * ── AUTHORISATION IS DONE HERE AND ONLY HERE ──────────────────────────────
 *
 * Both `CourseContentAccess` and `AssignmentAccess` resolve an Offering for a
 * student, and both do it from the CONFIRMED REGISTRATION. They are the existing
 * authorities, so this controller uses them and adds no rule of its own. A 404
 * rather than a 403 is deliberate and consistent with the rest of the Course
 * Offering student surface: a colleague should not be able to tell from the
 * response whether an Offering exists in a tenant they cannot see.
 *
 * `StudentCourseHome` is then given an Offering it may assume the caller has
 * checked, and it does no authorisation of its own - a second gate is a second
 * place for the two to disagree, and a gate that sometimes opens is worse than
 * no gate.
 */
class StudentCourseController extends Controller
{
    public function __construct(
        private readonly CourseContentAccess $contentAccess,
        private readonly AssignmentAccess $assignmentAccess,
        private readonly StudentCourseHome $home,
    ) {}

    /**
     * GET /student/courses/{id}
     *
     * The Course Home: header, navigation, and the four cards.
     */
    public function show(Request $request, $id): View
    {
        $student = $request->user();

        $offering = $this->assignmentAccess->resolveOffering($student, (int) $id);

        // BOTH authorities, asked. They are the two the Offering is reachable
        // through today - Course Content and Assignments - and they answer the same
        // question from the same confirmed registration. Requiring both means the
        // Course Home cannot be a way into an Offering that one of the two would
        // have refused, which is the whole point of having two.
        if (! $this->contentAccess->canStudentAccess($student, $offering)
            || ! $this->assignmentAccess->canStudentAccess($student, $offering)) {
            abort(404);
        }

        $offering->loadMissing(['subject', 'academicYear', 'academicPeriod', 'lecturerAllocations.lecturer']);

        $view = $this->home->build($student, $offering);

        return view('student.courses.show', array_merge($view, [
            'offering' => $offering,
        ]));
    }
}
