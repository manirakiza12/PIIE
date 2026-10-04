<?php

namespace App\Providers;

use App\Support\CourseOffering\WorkspaceNav;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Supplies the lecturer's Course Offering workspace navigation to EVERY page in
 * the teacher/course_offerings namespace.
 *
 * WHY A VIEW COMPOSER RATHER THAN A CONTROLLER VARIABLE
 *
 * The navigation was previously a private controller method rendered by a
 * hand-written loop in a single view. It was therefore present on the Overview
 * page and absent everywhere else - a lecturer on the Course Content page had no
 * visible way to reach Assignments, and no amount of correctly building a second
 * tab strip would have fixed it, because the second one would have been the one
 * someone forgot.
 *
 * A composer makes the navigation a property of the NAMESPACE rather than of a
 * particular view file. A lecturer Course Offering page now has the navigation
 * because of where it lives, not because someone remembered to add an include.
 * That is the difference between a fix and a convention.
 *
 * IT IS A CONVENIENCE, NOT THE ACCESS CONTROL
 *
 * The composer reads the offering the controller already resolved and asks the
 * existing `LecturerCourseOfferingAccess` what this lecturer may use. It never
 * grants anything: every destination re-checks the lecturer's own allocation on
 * that exact Course Offering when it is opened, so a rendered tab is a pointer,
 * not a permission.
 */
class CourseOfferingWorkspaceNavServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The namespace only. Registering this on `teacher.*` would run it for
        // every teacher portal page, and on `*` for every page in the product -
        // both would add a needless database query to unrelated screens.
        View::composer('teacher.course_offerings.*', function ($view): void {
            $request = request();
            $user = Auth::user();

            // An unauthenticated render - a 500 page, or a test without a session -
            // must not be able to fail here and mask whatever it was already
            // reporting. The navigation is decoration; it never turns a working
            // page into a broken one.
            if (! $user || ! $request) {
                return;
            }

            $nav = app(WorkspaceNav::class);

            $offering = $nav->offeringFor($request, $view->getData());

            // Not inside a Course Offering (the lecturer's own list of Offerings,
            // for instance). No navigation to render, and nothing is broken.
            if (! $offering) {
                return;
            }

            // Reuse the resolution the lecturer's workspace already uses, so
            // `my_allocation_is_current` and the rest behave identically here and
            // on the Overview page. A lecturer with no allocation on this Offering
            // gets no navigation at all rather than a row of tabs that 404.
            $access = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class);
            $resolved = $access->resolveForLecturer($user, (int) $offering->id);

            if (! $resolved) {
                return;
            }

            $canTeach = $access->teachingActionsAllowed($resolved);

            $view->with('workspaceNavOffering', $resolved);
            $view->with('workspaceTabs', $nav->tabs(
                $request,
                $resolved,
                $canTeach,
                $nav->currentKeyFor($request),
            ));
        });
    }
}
