<?php

namespace App\Http\Controllers;

use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseExams\CourseOfferingAssessments;
use App\Support\CourseExams\CourseOfferingExamAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * THE STUDENT'S QUIZZES & EXAMS TAB, INSIDE A COURSE OFFERING.
 *
 * ── AUTHORISATION IS ASKED, NOT INVENTED ───────────────────────────────────
 *
 * Both the Offering and the student are resolved through existing authorities:
 * `CourseContentAccess` decides whether this student may reach this Offering at
 * all (a confirmed registration, in this tenant, for an active non-staff user),
 * and `CourseOfferingExamAccess` is what the engine itself uses to decide which
 * exams this student may open. This controller adds no third opinion.
 *
 * The 404 rather than 403 is deliberate and consistent with the rest of the Course
 * Offering student surface: a student who is not on this course should not be able
 * to tell from the response whether the course exists in a tenant they cannot see.
 *
 * ── IT LISTS, AND LINKS OUT ────────────────────────────────────────────────
 *
 * Every action on every row - the instructions, starting, resuming, saving, the
 * deliberate final submission and the released result - is the engine's existing
 * `student.online_exam.*` route. This page deliberately contains no start button,
 * no timer and no answer field, because a second implementation of the attempt
 * lifecycle is precisely the thing that would drift from the first.
 */
class StudentCourseExamsController extends Controller
{
    public function __construct(
        private readonly CourseContentAccess $contentAccess,
        private readonly CourseOfferingExamAccess $examAccess,
        private readonly CourseOfferingAssessments $assessments,
    ) {}

    /**
     * GET /student/courses/{id}/exams
     */
    public function index(Request $request, $id): View
    {
        $student = $request->user();

        $offering = $this->contentAccess->resolveOffering($student, (int) $id);

        if (! $this->contentAccess->canStudentAccess($student, $offering)) {
            abort(404);
        }

        $offering->loadMissing(['subject', 'academicYear', 'academicPeriod', 'lecturerAllocations.lecturer']);

        return view('student.courses.exams', [
            'offering' => $offering,
            'rows' => $this->assessments->forStudent($student, $offering),
        ]);
    }
}
